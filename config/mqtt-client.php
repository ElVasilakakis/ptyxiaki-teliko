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

            // The host and port to which the client shall connect.
            'host' => env('MQTT_HOST', 'broker.emqx.io'),
            'port' => env('MQTT_PORT', 1883),

            // Updated to MQTT 3.1.1 for better compatibility with IoT devices
            'protocol' => MqttClient::MQTT_3_1_1,

            // A specific client id to be used for the connection.
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_client'),

            // Set to false for persistent sessions (better for IoT)
            'use_clean_session' => env('MQTT_CLEAN_SESSION', false),

            // Enable logging for debugging
            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),

            // Dedicated MQTT log channel
            'log_channel' => env('MQTT_LOG_CHANNEL', 'mqtt'),

            // Repository for message storage
            'repository' => MemoryRepository::class,

            // Additional settings used for the connection to the broker.
            'connection_settings' => [

                // TLS settings (disabled for broker.emqx.io:1883)
                'tls' => [
                    'enabled' => env('MQTT_TLS_ENABLED', false),
                    'allow_self_signed_certificate' => env('MQTT_TLS_ALLOW_SELF_SIGNED_CERT', false),
                    'verify_peer' => env('MQTT_TLS_VERIFY_PEER', true),
                    'verify_peer_name' => env('MQTT_TLS_VERIFY_PEER_NAME', true),
                    'ca_file' => env('MQTT_TLS_CA_FILE'),
                    'ca_path' => env('MQTT_TLS_CA_PATH'),
                    'client_certificate_file' => env('MQTT_TLS_CLIENT_CERT_FILE'),
                    'client_certificate_key_file' => env('MQTT_TLS_CLIENT_CERT_KEY_FILE'),
                    'client_certificate_key_passphrase' => env('MQTT_TLS_CLIENT_CERT_KEY_PASSPHRASE'),
                ],

                // Authentication credentials
                'auth' => [
                    'username' => env('MQTT_USERNAME', env('MQTT_AUTH_USERNAME')),
                    'password' => env('MQTT_PASSWORD', env('MQTT_AUTH_PASSWORD')),
                ],

                // Last will for device disconnection detection
                'last_will' => [
                    'topic' => env('MQTT_LAST_WILL_TOPIC', 'devices/laravel_client/status'),
                    'message' => env('MQTT_LAST_WILL_MESSAGE', 'offline'),
                    'quality_of_service' => env('MQTT_LAST_WILL_QUALITY_OF_SERVICE', 1),
                    'retain' => env('MQTT_LAST_WILL_RETAIN', true),
                ],

                // Timeout settings optimized for IoT
                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 60),
                'socket_timeout' => env('MQTT_SOCKET_TIMEOUT', 5),
                'resend_timeout' => env('MQTT_RESEND_TIMEOUT', 10),

                // Keep alive interval for persistent connections
                'keep_alive_interval' => env('MQTT_KEEP_ALIVE', env('MQTT_KEEP_ALIVE_INTERVAL', 60)),

                // Auto-reconnect settings (enabled for IoT reliability)
                'auto_reconnect' => [
                    'enabled' => env('MQTT_AUTO_RECONNECT_ENABLED', true),
                    'max_reconnect_attempts' => env('MQTT_AUTO_RECONNECT_MAX_RECONNECT_ATTEMPTS', 5),
                    'delay_between_reconnect_attempts' => env('MQTT_AUTO_RECONNECT_DELAY_BETWEEN_RECONNECT_ATTEMPTS', 5),
                ],

            ],

        ],

        // Optional: Separate connection for device commands
        'device_control' => [
            'host' => env('MQTT_HOST', 'broker.emqx.io'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_client') . '_control',
            'use_clean_session' => true, // Clean session for commands
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
