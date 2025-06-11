<?php

declare(strict_types=1);

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | Default MQTT Connection
    |--------------------------------------------------------------------------
    |
    | This setting defines the default MQTT connection returned when requesting
    | a connection without name from the facade.
    |
    */

    'default_connection' => 'default',

    /*
    |--------------------------------------------------------------------------
    | MQTT Connections
    |--------------------------------------------------------------------------
    |
    | These are the MQTT connections used by the application. You can also open
    | an individual connection from the application itself, but all connections
    | defined here can be accessed via name conveniently.
    |
    */

    'connections' => [
        'default' => [
            'host' => env('MQTT_HOST', 'broker.emqx.io'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            
            // Generate truly unique client ID to prevent conflicts
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_' . uniqid() . '_' . getmypid()),
            
            // CRITICAL FIX: Use persistent session for auto-reconnect
            'use_clean_session' => env('MQTT_CLEAN_SESSION', false), // Changed to false
            
            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'mqtt'),
            'repository' => MemoryRepository::class,
            
            'connection_settings' => [
                'tls' => [
                    'enabled' => env('MQTT_TLS_ENABLED', false),
                ],
                
                'auth' => [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ],
                
                'last_will' => [
                    'topic' => env('MQTT_LAST_WILL_TOPIC', 'devices/laravel_client/status'),
                    'message' => env('MQTT_LAST_WILL_MESSAGE', 'offline'),
                    'quality_of_service' => env('MQTT_LAST_WILL_QUALITY_OF_SERVICE', 1),
                    'retain' => env('MQTT_LAST_WILL_RETAIN', true),
                ],
                
                // Optimized timeouts for persistent sessions
                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 60),
                'socket_timeout' => env('MQTT_SOCKET_TIMEOUT', 5),
                'resend_timeout' => env('MQTT_RESEND_TIMEOUT', 10),
                'keep_alive_interval' => env('MQTT_KEEP_ALIVE', 60),
                
                // Enable auto-reconnect with persistent sessions
                'auto_reconnect' => [
                    'enabled' => env('MQTT_AUTO_RECONNECT_ENABLED', true),
                    'max_reconnect_attempts' => env('MQTT_AUTO_RECONNECT_MAX_RECONNECT_ATTEMPTS', 5),
                    'delay_between_reconnect_attempts' => env('MQTT_AUTO_RECONNECT_DELAY_BETWEEN_RECONNECT_ATTEMPTS', 5),
                ],
            ],
        ],
        
        // Separate connection for commands (can use clean session)
        'device_control' => [
            'host' => env('MQTT_HOST', 'broker.emqx.io'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_client') . '_control_' . uniqid(),
            'use_clean_session' => true, // Clean session OK for commands
            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'mqtt'),
            'repository' => MemoryRepository::class,
            
            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ],
                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 30),
                'socket_timeout' => env('MQTT_SOCKET_TIMEOUT', 5),
                'keep_alive_interval' => env('MQTT_KEEP_ALIVE', 30),
                'auto_reconnect' => [
                    'enabled' => false, // No auto-reconnect for command connection
                ],
            ],
        ],
    ],

];
