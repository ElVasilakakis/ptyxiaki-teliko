<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class MqttBroker extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'host',
        'port',
        'protocol',
        'username',
        'password',
        'tls_enabled',
        'tls_options',
        'keep_alive',
        'connect_timeout',
        'clean_session',
        'qos',
        'client_id_prefix',
        'auto_reconnect',
        'max_reconnect_attempts',
        'reconnect_delay',
        'last_will_topic',
        'last_will_message',
        'last_will_qos',
        'last_will_retain',
        'status',
        'last_connected_at',
        'connection_error',
        'statistics',
        'is_default',
        'description',
    ];

    protected $casts = [
        'tls_enabled' => 'boolean',
        'tls_options' => 'json',
        'clean_session' => 'boolean',
        'auto_reconnect' => 'boolean',
        'last_will_retain' => 'boolean',
        'last_connected_at' => 'datetime',
        'statistics' => 'json',
        'is_default' => 'boolean',
    ];

    // Constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ERROR = 'error';

    public const PROTOCOLS = [
        'mqtt' => 'MQTT',
        'mqtts' => 'MQTT over SSL/TLS',
        'ws' => 'MQTT over WebSocket',
        'wss' => 'MQTT over Secure WebSocket',
    ];

    // Relationships
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // Methods
    public function getConnectionStringAttribute(): string
    {
        return $this->protocol . '://' . $this->host . ':' . $this->port;
    }

    public function generateClientId(string $suffix = null): string
    {
        $suffix = $suffix ?? uniqid();
        return $this->client_id_prefix . '_' . $suffix;
    }

    public function updateConnectionStatus(bool $connected, string $error = null): void
    {
        $this->update([
            'status' => $connected ? self::STATUS_ACTIVE : self::STATUS_ERROR,
            'last_connected_at' => $connected ? now() : $this->last_connected_at,
            'connection_error' => $error,
        ]);
    }

    public function updateStatistics(array $stats): void
    {
        $currentStats = $this->statistics ?? [];
        $this->update([
            'statistics' => array_merge($currentStats, $stats)
        ]);
    }

    public function getConnectionConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'username' => $this->username,
            'password' => $this->password,
            'tls_enabled' => $this->tls_enabled,
            'tls_options' => $this->tls_options,
            'keep_alive' => $this->keep_alive,
            'connect_timeout' => $this->connect_timeout,
            'clean_session' => $this->clean_session,
            'qos' => $this->qos,
            'auto_reconnect' => $this->auto_reconnect,
            'max_reconnect_attempts' => $this->max_reconnect_attempts,
            'reconnect_delay' => $this->reconnect_delay,
            'last_will' => [
                'topic' => $this->last_will_topic,
                'message' => $this->last_will_message,
                'qos' => $this->last_will_qos,
                'retain' => $this->last_will_retain,
            ],
        ];
    }
}
