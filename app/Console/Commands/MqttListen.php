<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MqttDeviceService;
use App\Models\MqttBroker;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen {--timeout=0 : Timeout in seconds (0 = no timeout)} {--broker= : Specific broker ID to connect to} {--debug : Enable debug output}';
    protected $description = 'Start the MQTT subscriber loop for all available brokers';

    private array $connections = [];
    private array $services = [];

    public function handle(): int
    {
        $this->info('🚀 Starting MQTT listener...');
        
        $timeout = (int) $this->option('timeout');
        $debug = $this->option('debug');
        $specificBroker = $this->option('broker');
        
        if ($debug) {
            $this->info('🔧 Debug mode enabled - will show all received messages');
        }
        
        $this->info('⏹️  Press Ctrl+C to stop');
        
        if ($timeout > 0) {
            $this->info("⏰ Listener will run for {$timeout} seconds");
        }
        
        try {
            // Connect to brokers
            if ($specificBroker) {
                $this->connectToSpecificBroker((int) $specificBroker);
            } else {
                $this->connectToAllBrokers();
            }
            
            if (empty($this->connections)) {
                $this->error('❌ No MQTT broker connections established');
                return self::FAILURE;
            }
            
            // Start monitoring
            if ($timeout > 0) {
                $this->handleWithTimeout($timeout, time());
            } else {
                $this->handleWithoutTimeout();
            }
            
        } catch (\Exception $e) {
            $this->error('❌ MQTT listener failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
    
    private function connectToSpecificBroker(int $brokerId): void
    {
        $broker = MqttBroker::active()->find($brokerId);
        
        if (!$broker) {
            throw new \Exception("Broker with ID {$brokerId} not found or not active");
        }
        
        $this->connectToBroker($broker);
    }
    
    private function connectToAllBrokers(): void
    {
        $brokers = MqttBroker::active()->get();
        
        if ($brokers->isEmpty()) {
            throw new \Exception('No active MQTT brokers found in database');
        }
        
        $this->info("📡 Found {$brokers->count()} active broker(s) in database");
        
        foreach ($brokers as $broker) {
            try {
                $this->connectToBroker($broker);
            } catch (\Exception $e) {
                $this->error("Failed to connect to broker {$broker->name}: " . $e->getMessage());
                Log::channel('mqtt')->error("Broker connection failed", [
                    'broker_id' => $broker->id,
                    'broker_name' => $broker->name,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function connectToBroker(MqttBroker $broker)
    {
        $this->info("🔌 Connecting to broker: {$broker->name} ({$broker->connection_string})");
        
        // Create connection settings
        $connectionSettings = new ConnectionSettings();
        
        if ($broker->username) {
            $connectionSettings->setUsername($broker->username);
            $connectionSettings->setPassword($broker->password);
        }
        
        $connectionSettings->setKeepAliveInterval($broker->keep_alive);
        $connectionSettings->setConnectTimeout($broker->connect_timeout);
        $connectionSettings->setUseTls($broker->tls_enabled);
        
        // REMOVE THIS LINE - it doesn't exist in the ConnectionSettings class
        // $connectionSettings->setUseCleanSession($broker->clean_session);
        
        // Last will configuration
        if ($broker->last_will_topic) {
            $connectionSettings->setLastWillTopic($broker->last_will_topic);
            $connectionSettings->setLastWillMessage($broker->last_will_message);
            $connectionSettings->setLastWillQualityOfService($broker->last_will_qos);
            $connectionSettings->setRetainLastWill($broker->last_will_retain);
        }
        
        // Auto-reconnect settings - use correct method names
        if ($broker->auto_reconnect) {
            $connectionSettings->setReconnectAutomatically(true);
            $connectionSettings->setMaxReconnectAttempts($broker->max_reconnect_attempts);
            $connectionSettings->setDelayBetweenReconnectAttempts($broker->reconnect_delay * 1000); // Convert to milliseconds
        }
        
        // Create MQTT client
        $clientId = $broker->generateClientId('listener_' . getmypid());
        $mqtt = new MqttClient($broker->host, $broker->port, $clientId);
        
        // Connect with clean session as second parameter
        $mqtt->connect($connectionSettings, $broker->clean_session);
        
        // Store connection and service
        $this->connections[$broker->id] = $mqtt;
        $this->services[$broker->id] = new MqttDeviceService();
        
        // Subscribe to topics for devices using this broker
        $this->subscribeToTopicsForBroker($broker, $mqtt, $this->services[$broker->id]);
        
        // Update broker connection status
        $broker->updateConnectionStatus(true);
        
        $this->info("✅ Connected to broker: {$broker->name}");
        
        Log::channel('mqtt')->info('MQTT broker connection established', [
            'broker_id' => $broker->id,
            'broker_name' => $broker->name,
            'client_id' => $clientId,
            'devices_count' => $broker->devices()->count()
        ]);
    }


    private function subscribeToTopicsForBroker(MqttBroker $broker, MqttClient $mqtt, MqttDeviceService $service): void
    {
        $debug = $this->option('debug');
        $devices = $broker->devices()->enabled()->get();
        
        $this->info("📡 Subscribing to topics for {$devices->count()} devices on broker {$broker->name}");
        
        // Subscribe to device-specific patterns
        $topicPatterns = [
            'devices/+/discovery/response' => 'discovery',
            'devices/+/data' => 'data', 
            'devices/+/status' => 'status',
            'devices/+/gps' => 'gps',
            'devices/+/control/response' => 'control_response',
            'devices/discover/all' => 'global_discovery'
        ];
        
        foreach ($topicPatterns as $pattern => $type) {
            $mqtt->subscribe($pattern, function($topic, $message) use ($debug, $service, $type, $broker) {
                $this->handleMessage($type, $topic, $message, $service, $broker, $debug);
            }, $broker->qos);
        }
        
        // Subscribe to custom device topics if any
        foreach ($devices as $device) {
            $customTopics = $device->mqtt_topics_config ?? [];
            foreach ($customTopics as $topicName => $topicPath) {
                if (!in_array($topicPath, array_keys($topicPatterns))) {
                    $mqtt->subscribe($topicPath, function($topic, $message) use ($debug, $service, $device, $broker) {
                        $this->handleMessage('custom', $topic, $message, $service, $broker, $debug, $device);
                    }, $broker->qos);
                }
            }
        }
    }
    
    private function handleMessage(string $type, string $topic, string $message, MqttDeviceService $service, MqttBroker $broker, bool $debug, $device = null): void
    {
        if ($debug) {
            $emoji = match($type) {
                'discovery' => '📥',
                'data' => '📊', 
                'status' => '💓',
                'gps' => '📍',
                'control_response' => '🎛️',
                'global_discovery' => '🔍',
                'custom' => '🔧',
                default => '📨'
            };
            
            $this->info("{$emoji} [{$broker->name}] {$type}: {$topic}");
            
            if ($type === 'gps') {
                $this->displayGpsDebugInfo($message);
            } else {
                $this->line("   " . substr($message, 0, 100) . (strlen($message) > 100 ? "..." : ""));
            }
        }
        
        // Log message
        Log::channel('mqtt')->info("MQTT message received", [
            'type' => $type,
            'topic' => $topic,
            'message' => $message,
            'broker_id' => $broker->id,
            'broker_name' => $broker->name,
            'timestamp' => now()->toISOString()
        ]);
        
        // Route to appropriate service method
        match($type) {
            'discovery' => $service->handleDeviceDiscovery($topic, $message),
            'data' => $service->handleDeviceData($topic, $message),
            'status' => $service->handleDeviceStatus($topic, $message),
            'gps' => $service->handleDeviceGPS($topic, $message),
            'global_discovery' => $service->handleGlobalDiscovery($topic, $message),
            'custom' => $this->handleCustomMessage($topic, $message, $device),
            default => null
        };
    }
    
    private function displayGpsDebugInfo(string $message): void
    {
        $data = json_decode($message, true);
        if ($data && isset($data['location'])) {
            $lat = $data['location']['latitude'] ?? 'N/A';
            $lng = $data['location']['longitude'] ?? 'N/A';
            $alt = $data['location']['altitude'] ?? 'N/A';
            $speed = $data['location']['speed_kmh'] ?? 'N/A';
            $sats = $data['location']['satellites'] ?? 'N/A';
            
            $this->line("   📍 Lat: {$lat}°, Lng: {$lng}°");
            $this->line("   🏔️  Alt: {$alt}m, Speed: {$speed} km/h");
            $this->line("   🛰️  Satellites: {$sats}");
        }
    }
    
    private function handleCustomMessage(string $topic, string $message, $device): void
    {
        Log::channel('mqtt')->info("Custom topic message received", [
            'topic' => $topic,
            'device_id' => $device?->device_unique_id ?? 'unknown',
            'message' => $message
        ]);
    }
    
    private function handleWithTimeout(int $timeout, int $startTime): void
    {
        $this->info('✅ Subscribed to all MQTT topics on all brokers');
        
        // Windows-compatible timeout loop
        while (true) {
            // Check if timeout reached
            if (time() - $startTime >= $timeout) {
                $this->info('⏰ Timeout reached, stopping listener...');
                break;
            }
            
            // Process MQTT messages for all connections
            foreach ($this->connections as $brokerId => $mqtt) {
                try {
                    $mqtt->loop(false, true); // Non-blocking mode
                } catch (\Exception $e) {
                    $broker = MqttBroker::find($brokerId);
                    $this->error("Connection lost to broker {$broker?->name}: " . $e->getMessage());
                    
                    // Update broker status
                    $broker?->updateConnectionStatus(false, $e->getMessage());
                    
                    // Remove failed connection
                    unset($this->connections[$brokerId]);
                    unset($this->services[$brokerId]);
                    
                    // Try to reconnect if auto-reconnect is enabled
                    if ($broker && $broker->auto_reconnect) {
                        $this->info("🔄 Attempting to reconnect to {$broker->name}...");
                        try {
                            $this->connectToBroker($broker);
                        } catch (\Exception $reconnectException) {
                            $this->error("Reconnection failed: " . $reconnectException->getMessage());
                        }
                    }
                }
            }
            
            // Break if no connections remain
            if (empty($this->connections)) {
                $this->error("All MQTT connections lost");
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(100000); // 100ms
            
            // Show progress every 30 seconds
            $elapsed = time() - $startTime;
            if ($elapsed > 0 && $elapsed % 30 === 0) {
                $remaining = $timeout - $elapsed;
                $activeConnections = count($this->connections);
                $this->info("⏱️  Running for {$elapsed}s, {$remaining}s remaining... ({$activeConnections} active connections)");
            }
        }
        
        // Disconnect all connections
        $this->disconnectAll();
    }
    
    private function handleWithoutTimeout(): void
    {
        $this->info('✅ Subscribed to all MQTT topics on all brokers');
        
        // Use pcntl_signal for graceful shutdown if available
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function() {
                $this->info("🛑 Shutdown signal received");
                $this->disconnectAll();
                exit(0);
            });
        }
        
        // Run indefinitely without timeout
        while (true) {
            foreach ($this->connections as $brokerId => $mqtt) {
                try {
                    $mqtt->loop(false, true); // Non-blocking mode
                } catch (\Exception $e) {
                    $broker = MqttBroker::find($brokerId);
                    $this->error("Connection lost to broker {$broker?->name}: " . $e->getMessage());
                    
                    // Update broker status
                    $broker?->updateConnectionStatus(false, $e->getMessage());
                    
                    // Remove failed connection
                    unset($this->connections[$brokerId]);
                    unset($this->services[$brokerId]);
                    
                    // Try to reconnect if auto-reconnect is enabled
                    if ($broker && $broker->auto_reconnect) {
                        $this->info("🔄 Attempting to reconnect to {$broker->name}...");
                        try {
                            $this->connectToBroker($broker);
                        } catch (\Exception $reconnectException) {
                            $this->error("Reconnection failed: " . $reconnectException->getMessage());
                        }
                    }
                }
            }
            
            // Break if no connections remain
            if (empty($this->connections)) {
                $this->error("All MQTT connections lost");
                break;
            }
            
            usleep(100000); // 100ms delay between loops
        }
    }
    
    private function disconnectAll(): void
    {
        foreach ($this->connections as $brokerId => $mqtt) {
            try {
                $broker = MqttBroker::find($brokerId);
                $this->info("🔌 Disconnecting from broker: {$broker?->name}");
                $mqtt->disconnect();
            } catch (\Exception $e) {
                // Ignore disconnect errors
            }
        }
        $this->connections = [];
        $this->services = [];
    }
    
    public function __destruct()
    {
        $this->disconnectAll();
    }
}
