<?php

declare(strict_types=1);

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

return [
    'default_connection' => 'fallback',

    'connections' => [
        'fallback' => [
            'host' => env('MQTT_HOST', 'broker.emqx.io'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_fallback_' . uniqid()),
            'use_clean_session' => env('MQTT_CLEAN_SESSION', false),
            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'mqtt'),
            'repository' => MemoryRepository::class,
            
            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ],
                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 60),
                'keep_alive_interval' => env('MQTT_KEEP_ALIVE', 60),
            ],
        ],
    ],
];
