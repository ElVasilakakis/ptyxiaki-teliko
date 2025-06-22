<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

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
    public function getFormattedValueAttribute()
    {
        switch ($this->sensor_type) {
            case 'gps_latitude':
            case 'gps_longitude':
                // GPS coordinates need 6-8 decimal places for accuracy
                return number_format((float)$this->value, 6, '.', '');
                
            case 'gps_altitude':
            case 'gps_speed':
                // GPS altitude and speed with 2 decimal places
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
                // For other sensors, use appropriate formatting
                if (is_numeric($this->value)) {
                    return number_format((float)$this->value, 2, '.', '');
                }
                return $this->value;
        }
    }

    public function getStatusAttribute(): string
    {
        // For GPS sensors, check geofencing first
        if ($this->sensor_type === 'gps' && $this->device && $this->device->land) {
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

    // Business Logic Methods
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
        if ($this->sensor_type !== 'gps' || !$this->device || !$this->device->land) {
            return true; // Not applicable
        }

        $land = $this->device->land;
        if (!$land->geojson || !$this->value) {
            return true; // No geofence data or GPS data
        }

        // Parse GPS coordinates from JSON value
        $gpsData = json_decode($this->value, true);
        if (!$gpsData || !isset($gpsData['lat']) || !isset($gpsData['lng'])) {
            return true; // Invalid GPS data
        }

        return $this->pointInPolygon(
            $gpsData['lat'], 
            $gpsData['lng'], 
            $land->geojson
        );
    }

    /**
     * Check if a GPS coordinate is inside a polygon (geofence)
     */
    private function pointInPolygon($latitude, $longitude, $polygon)
    {
        if (!$polygon || !is_array($polygon)) {
            return false;
        }

        $vertices = [];

        // Extract coordinates from GeoJSON polygon
        if (isset($polygon['features'][0]['geometry']['coordinates'][0])) {
            $coordinates = $polygon['features'][0]['geometry']['coordinates'][0];
            foreach ($coordinates as $coord) {
                $vertices[] = ['lng' => $coord[0], 'lat' => $coord[1]];
            }
        } else {
            return false;
        }

        return $this->raycastingAlgorithm($latitude, $longitude, $vertices);
    }

    /**
     * Ray casting algorithm to determine if point is inside polygon
     */
    private function raycastingAlgorithm($lat, $lng, $vertices)
    {
        $intersections = 0;
        $verticesCount = count($vertices);

        for ($i = 1; $i < $verticesCount; $i++) {
            $vertex1 = $vertices[$i - 1];
            $vertex2 = $vertices[$i];

            // Check if point is on horizontal boundary
            if ($vertex1['lat'] == $vertex2['lat'] && 
                $vertex1['lat'] == $lat && 
                $lng > min($vertex1['lng'], $vertex2['lng']) && 
                $lng < max($vertex1['lng'], $vertex2['lng'])) {
                return true; // On boundary
            }

            // Ray casting algorithm
            if ($lat > min($vertex1['lat'], $vertex2['lat']) && 
                $lat <= max($vertex1['lat'], $vertex2['lat']) && 
                $lng <= max($vertex1['lng'], $vertex2['lng']) && 
                $vertex1['lat'] != $vertex2['lat']) {
                
                $xinters = ($lat - $vertex1['lat']) * 
                          ($vertex2['lng'] - $vertex1['lng']) / 
                          ($vertex2['lat'] - $vertex1['lat']) + $vertex1['lng'];

                if ($xinters == $lng) {
                    return true; // On boundary
                }

                if ($vertex1['lng'] == $vertex2['lng'] || $lng <= $xinters) {
                    $intersections++;
                }
            }
        }

        // Odd number of intersections = inside
        return ($intersections % 2 != 0);
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
        if ($this->sensor_type !== 'gps') {
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
        if ($this->sensor_type === 'gps') {
            return !$this->isInsideGeofence();
        }

        return !$this->isWithinThresholds();
    }

    /**
     * Get violation message for display
     */
    public function getViolationMessage(): ?string
    {
        if ($this->sensor_type === 'gps') {
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
