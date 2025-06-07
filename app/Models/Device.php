<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Device extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_unique_id',
        'device_number',
        'mac_address',
        'api_url',
        'serial_number',
        'name',
        'device_type',
        'firmware_version',
        'hardware_revision',
        'power_source',
        'status',
        'last_error',
        'last_seen_at',
        'enabled',
        'wifi_connected',
        'wifi_rssi',
        'uptime_seconds',
        'free_heap',
        'last_status_check',
        'valid_sensors',
        'total_sensors',
        'health_percentage',
        'wifi_strength',
        'memory_status',
        'sensor_status',
        'chip_model',
        'chip_revision',
        'cpu_frequency',
        'flash_size',
        'user_id',
        'land_id',
        'notes',
        'application_data',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'wifi_connected' => 'boolean',
        'wifi_rssi' => 'integer',
        'uptime_seconds' => 'integer',
        'free_heap' => 'integer',
        'last_status_check' => 'integer',
        'valid_sensors' => 'integer',
        'total_sensors' => 'integer',
        'health_percentage' => 'integer',
        'chip_revision' => 'integer',
        'cpu_frequency' => 'integer',
        'flash_size' => 'integer',
        'application_data' => 'json',
        'last_seen_at' => 'datetime',
    ];

    // Constants for device status
    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_ERROR = 'error';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_ONLINE,
        self::STATUS_OFFLINE,
        self::STATUS_ERROR,
        self::STATUS_MAINTENANCE,
    ];

    // Relationships
    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }

    public function activeSensors(): HasMany
    {
        return $this->hasMany(Sensor::class)->where('enabled', true);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }

    // Query Scopes
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    public function scopeWithRecentSensors(Builder $query): Builder
    {
        return $query->with(['sensors' => function ($q) {
            $q->where('reading_timestamp', '>', now()->subHour());
        }]);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('device_type', $type);
    }

    // Accessors & Mutators
    public function getFormattedUptimeAttribute(): string
    {
        $seconds = $this->uptime_seconds;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    public function getFormattedFreeHeapAttribute(): string
    {
        $bytes = $this->free_heap;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getIsHealthyAttribute(): bool
    {
        return $this->status === self::STATUS_ONLINE && 
               $this->health_percentage >= 80 &&
               $this->wifi_connected;
    }

    public function getWifiSignalQualityAttribute(): string
    {
        if (!$this->wifi_rssi) return 'unknown';
        
        return match (true) {
            $this->wifi_rssi >= -50 => 'excellent',
            $this->wifi_rssi >= -60 => 'good',
            $this->wifi_rssi >= -70 => 'fair',
            $this->wifi_rssi >= -80 => 'poor',
            default => 'very poor'
        };
    }

    // Business Logic Methods
    public function updateFromApiData(array $deviceData): self
    {
        $statusDetails = $deviceData['status_details'] ?? [];
        $sensorHealth = $statusDetails['sensor_health'] ?? [];
        $statusIndicators = $statusDetails['status_indicators'] ?? [];
        $deviceInfo = $deviceData['device_data'] ?? [];

        $this->fill([
            'device_unique_id' => $deviceData['device_unique_id'] ?? $this->device_unique_id,
            'device_number' => $deviceData['device_number'] ?? $this->device_number,
            'mac_address' => $deviceData['mac_address'] ?? $this->mac_address,
            'serial_number' => $deviceData['serial_number'] ?? $this->serial_number,
            'name' => $deviceData['name'] ?? $this->name,
            'device_type' => $deviceData['device_type'] ?? $this->device_type,
            'firmware_version' => $deviceData['firmware_version'] ?? $this->firmware_version,
            'hardware_revision' => $deviceData['hardware_revision'] ?? $this->hardware_revision,
            'power_source' => $deviceData['power_source'] ?? $this->power_source,
            'status' => $deviceData['status'] ?? $this->status,
            'enabled' => $deviceData['enabled'] ?? $this->enabled,
            'wifi_connected' => $statusDetails['wifi_connected'] ?? $this->wifi_connected,
            'wifi_rssi' => $statusDetails['wifi_rssi'] ?? $this->wifi_rssi,
            'uptime_seconds' => $statusDetails['uptime_seconds'] ?? $this->uptime_seconds,
            'free_heap' => $statusDetails['free_heap'] ?? $this->free_heap,
            'last_status_check' => $statusDetails['last_status_check'] ?? $this->last_status_check,
            'valid_sensors' => $sensorHealth['valid_sensors'] ?? $this->valid_sensors,
            'total_sensors' => $sensorHealth['total_sensors'] ?? $this->total_sensors,
            'health_percentage' => $sensorHealth['health_percentage'] ?? $this->health_percentage,
            'wifi_strength' => $statusIndicators['wifi_strength'] ?? $this->wifi_strength,
            'memory_status' => $statusIndicators['memory_status'] ?? $this->memory_status,
            'sensor_status' => $statusIndicators['sensor_status'] ?? $this->sensor_status,
            'chip_model' => $deviceInfo['chip_model'] ?? $this->chip_model,
            'chip_revision' => $deviceInfo['chip_revision'] ?? $this->chip_revision,
            'cpu_frequency' => $deviceInfo['cpu_frequency'] ?? $this->cpu_frequency,
            'flash_size' => $deviceInfo['flash_size'] ?? $this->flash_size,
        ]);

        return $this;
    }

    public function syncSensorsFromApi(array $sensorsData): void
    {
        foreach ($sensorsData as $sensorData) {
            $this->sensors()->updateOrCreate(
                ['sensor_type' => $sensorData['type']],
                [
                    'sensor_name' => $sensorData['name'] ?? $sensorData['type'],
                    'unit' => $sensorData['unit'] ?? null,
                    'value' => $sensorData['current_value'] ?? null,
                    'thresholds' => $sensorData['thresholds'] ?? null,
                    'reading_timestamp' => now(),
                    'enabled' => $sensorData['is_active'] ?? true,
                    'description' => $sensorData['model'] ?? null,
                    'location' => $sensorData['location'] ?? null,
                ]
            );
        }
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    public function needsMaintenance(): bool
    {
        return $this->health_percentage < 50 || 
               $this->status === self::STATUS_ERROR ||
               !$this->wifi_connected;
    }
}
