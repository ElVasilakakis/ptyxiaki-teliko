<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use App\Services\GeofencingService;

class Sensor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'sensor_type',
        'sensor_name',
        'description',
        'location',
        'unit',
        'thresholds',
        'value',
        'accuracy',
        'reading_timestamp',
        'enabled',
        'calibration_offset',
        'last_calibration',
        'alert_enabled',
        'alert_threshold_min',
        'alert_threshold_max',
    ];

    protected $casts = [
        'thresholds' => 'array',
        'value' => 'float',
        'accuracy' => 'float',
        'reading_timestamp' => 'datetime',
        'enabled' => 'boolean',
        'calibration_offset' => 'float',
        'last_calibration' => 'datetime',
        'alert_enabled' => 'boolean',
        'alert_threshold_min' => 'float',
        'alert_threshold_max' => 'float',
    ];

    // Relationships
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    // Query Scopes
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('reading_timestamp', '>', now()->subHours($hours));
    }

    // Accessors
    public function getStatusAttribute(): string
    {
        // For GPS sensors, check geofencing first
        if (str_starts_with($this->sensor_type, 'gps') && $this->device && $this->device->land) {
            if (!$this->isInsideGeofence()) {
                return 'critical'; // Outside geofence
            }
        }

        // For other sensors, check thresholds
        if (!$this->isWithinThresholds()) {
            return 'critical';
        }

        if ($this->needsCalibration()) {
            return 'warning';
        }

        return 'normal';
    }

    public function getFormattedValueAttribute()
    {
        switch ($this->sensor_type) {
            case 'gps_latitude':
            case 'gps_longitude':
                return number_format((float)$this->value, 6, '.', '');
                
            case 'gps_altitude':
            case 'gps_speed':
                return number_format((float)$this->value, 2, '.', '');
                
            case 'temperature':
                return number_format($this->value, 1) . '°C';
                
            case 'humidity':
            case 'light':
            case 'battery':
                return number_format($this->value, 0) . '%';
                
            case 'wifi_signal':
            case 'signal':
                return number_format($this->value, 0) . ' dBm';
                
            default:
                if (is_numeric($this->value)) {
                    return number_format((float)$this->value, 2, '.', '');
                }
                return $this->value;
        }
    }

    public function isWithinThresholds(): bool
    {
        if (!$this->thresholds || $this->value === null) {
            return true;
        }

        $min = $this->thresholds['min'] ?? null;
        $max = $this->thresholds['max'] ?? null;

        if ($min !== null && $this->value < $min) {
            return false;
        }

        if ($max !== null && $this->value > $max) {
            return false;
        }

        return true;
    }

    public function isInsideGeofence(): bool
    {
        if (!str_starts_with($this->sensor_type, 'gps') || !$this->device || !$this->device->land) {
            return true; // Not applicable
        }

        $land = $this->device->land;
        
        // The location field is already an array (Laravel auto-casts it)
        if (!$land->location) {
            return false; // No geofence data
        }

        // Get GPS coordinates
        $coordinates = $this->getGpsCoordinates();
        if (!$coordinates) {
            return false; // No GPS data available
        }

        // Location is already an array, no need to json_decode
        $locationData = $land->location;
        if (!isset($locationData['geojson'])) {
            return false;
        }

        // Extract the geojson from the location data
        $geojsonData = $locationData['geojson'];

        // Use the GeofencingService
        $geofencingService = new GeofencingService();
        return $geofencingService->isPointInsidePolygon(
            $coordinates['lat'], 
            $coordinates['lng'], 
            $geojsonData
        );
    }

    private function getGpsCoordinates(): ?array
    {
        if ($this->sensor_type === 'gps_latitude' || $this->sensor_type === 'gps_longitude') {
            // Get the most recent GPS sensors from the same device
            $latSensor = $this->device->sensors()
                ->where('sensor_type', 'gps_latitude')
                ->orderBy('reading_timestamp', 'desc')
                ->orderBy('id', 'desc')
                ->first();
                
            $lngSensor = $this->device->sensors()
                ->where('sensor_type', 'gps_longitude')
                ->orderBy('reading_timestamp', 'desc')
                ->orderBy('id', 'desc')
                ->first();
            
            if (!$latSensor || !$lngSensor || !$latSensor->value || !$lngSensor->value) {
                return null;
            }
            
            return [
                'lat' => (float)$latSensor->value,
                'lng' => (float)$lngSensor->value
            ];
        }
        
        return null;
    }

    public function needsCalibration(): bool
    {
        if (!$this->last_calibration) {
            return true;
        }

        // Check if calibration is older than 30 days
        return $this->last_calibration->lt(now()->subDays(30));
    }

    public function getCalibratedValue(): float
    {
        if ($this->value === null) {
            return 0;
        }

        return $this->value + ($this->calibration_offset ?? 0);
    }

    /**
     * Get geofence status for GPS sensors
     */
    public function getGeofenceStatus(): array
    {
        if (!str_starts_with($this->sensor_type, 'gps')) {
            return ['applicable' => false];
        }

        if (!$this->device || !$this->device->land) {
            return [
                'applicable' => true,
                'has_geofence' => false,
                'message' => 'No land assigned'
            ];
        }

        $isInside = $this->isInsideGeofence();
        
        return [
            'applicable' => true,
            'has_geofence' => true,
            'is_inside' => $isInside,
            'land_name' => $this->device->land->land_name,
            'message' => $isInside ? 'Inside boundary' : 'Outside boundary'
        ];
    }

    /**
     * Check if sensor has any violations (threshold or geofence)
     */
    public function hasViolations(): bool
    {
        if (str_starts_with($this->sensor_type, 'gps')) {
            return !$this->isInsideGeofence();
        }

        return !$this->isWithinThresholds();
    }

    /**
     * Get violation message for display
     */
    public function getViolationMessage(): ?string
    {
        if (str_starts_with($this->sensor_type, 'gps')) {
            if (!$this->isInsideGeofence()) {
                return 'Outside land boundary';
            }
            return null;
        }

        if (!$this->isWithinThresholds() && $this->thresholds && $this->value !== null) {
            $min = $this->thresholds['min'] ?? null;
            $max = $this->thresholds['max'] ?? null;
            
            if ($min !== null && $this->value < $min) {
                return "Below minimum ({$min})";
            } elseif ($max !== null && $this->value > $max) {
                return "Above maximum ({$max})";
            }
        }

        return null;
    }
}
