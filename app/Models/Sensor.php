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
    public function getFormattedValueAttribute(): string
    {
        if ($this->value === null) {
            return '--';
        }

        return match ($this->sensor_type) {
            'temperature' => number_format($this->value, 1) . '°C',
            'humidity' => number_format($this->value, 0) . '%',
            'light' => number_format($this->value, 0) . '%',
            'signal' => number_format($this->value, 0) . ' dBm',
            'battery' => number_format($this->value, 0) . '%',
            default => number_format($this->value, 2)
        };
    }

    public function getStatusAttribute(): string
    {
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
}
