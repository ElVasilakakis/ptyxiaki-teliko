<?php

namespace App\Console\Commands;

use App\Services\MqttDeviceService;
use App\Models\MqttBroker;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttSubscriber extends Command
{
    protected $signature = 'mqtt:subscribe {--timeout=0 : Timeout in seconds (0 for infinite)} {--debug : Enable debug output} {--broker= : Specific broker ID to connect to}';
    protected $description = 'Subscribe to MQTT topics for device management including GPS data';
    
    private array $connections = [];
    private array $services = [];
    
    public function handle()
    {
        Log::channel('mqtt')->info('MQTT subscriber service starting', [
            'timeout' => $this->option('timeout'),
            'debug' => $this->option('debug'),
            'specific_broker' => $this->option('broker')
        ]);
        
        try {
            if ($this->option('broker')) {
                // Connect to specific broker
                $this->connectToSpecificBroker($this->option('broker'));
            } else {
                // Connect to all active brokers
                $this->connectToAllBrokers();
            }
            
            // Start monitoring all connections
            $this->startMonitoring();
            
        } catch (\Exception $e) {
            $this->error('❌ MQTT Subscriber failed: ' . $e->getMessage());
            Log::channel('mqtt')->error('MQTT subscriber failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
    
    private function connectToSpecificBroker(int $brokerId)
    {
        $broker = MqttBroker::active()->find($brokerId);
        
        if (!$broker) {
            throw new \Exception("Broker with ID {$brokerId} not found or not active");
        }
        
        $this->connectToBroker($broker);
    }
    
    private function connectToAllBrokers()
    {
        $brokers = MqttBroker::active()->get();
        
        if ($brokers->isEmpty()) {
            throw new \Exception('No active MQTT brokers found');
        }
        
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
        
        if (empty($this->connections)) {
            throw new \Exception('No MQTT broker connections established');
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
        $clientId = $broker->generateClientId('subscriber_' . getmypid());
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

    
    private function subscribeToTopicsForBroker(MqttBroker $broker, MqttClient $mqtt, MqttDeviceService $service)
    {
        $debug = $this->option('debug');
        $devices = $broker->devices()->enabled()->get();
        
        $this->info("📡 Subscribing to topics for {$devices->count()} devices on broker {$broker->name}");
        
        // Get all unique topics from devices using this broker
        $allTopics = collect();
        
        foreach ($devices as $device) {
            $topics = $device->mqtt_topics;
            foreach ($topics as $topicType => $topic) {
                if (!$allTopics->contains($topic)) {
                    $allTopics->push($topic);
                }
            }
        }
        
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
                if (!in_array($topicPath, $topicPatterns)) {
                    $mqtt->subscribe($topicPath, function($topic, $message) use ($debug, $service, $device, $broker) {
                        $this->handleMessage('custom', $topic, $message, $service, $broker, $debug, $device);
                    }, $broker->qos);
                }
            }
        }
    }
    
    private function handleMessage(string $type, string $topic, string $message, MqttDeviceService $service, MqttBroker $broker, bool $debug, Device $device = null)
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
        
        $this->logMessage($type, $topic, $message, $broker);
        
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
    
    private function displayGpsDebugInfo(string $message)
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
    
    private function handleCustomMessage(string $topic, string $message, ?Device $device)
    {
        // Handle custom topic messages
        Log::channel('mqtt')->info("Custom topic message received", [
            'topic' => $topic,
            'device_id' => $device?->device_unique_id,
            'message' => $message
        ]);
    }
    
    private function startMonitoring()
    {
        $this->info("🚀 Starting MQTT monitoring for " . count($this->connections) . " broker(s)");
        
        // Use pcntl_signal for graceful shutdown if available
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function() {
                $this->info("🛑 Shutdown signal received");
                foreach ($this->connections as $brokerId => $mqtt) {
                    $mqtt->interrupt();
                }
            });
        }
        
        // Monitor all connections in parallel
        while (true) {
            foreach ($this->connections as $brokerId => $mqtt) {
                try {
                    $mqtt->loop(false, true); // Non-blocking loop
                } catch (\Exception $e) {
                    $broker = MqttBroker::find($brokerId);
                    $this->error("Connection lost to broker {$broker?->name}: " . $e->getMessage());
                    
                    // Update broker status
                    $broker?->updateConnectionStatus(false, $e->getMessage());
                    
                    // Remove failed connection
                    unset($this->connections[$brokerId]);
                    unset($this->services[$brokerId]);
                    
                    // Try to reconnect
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
    
    private function logMessage(string $type, string $topic, string $message, MqttBroker $broker)
    {
        Log::channel('mqtt')->info("MQTT message received", [
            'type' => $type,
            'topic' => $topic,
            'message' => $message,
            'broker_id' => $broker->id,
            'broker_name' => $broker->name,
            'timestamp' => now()->toISOString()
        ]);
    }
    
    public function __destruct()
    {
        // Clean up connections
        foreach ($this->connections as $mqtt) {
            try {
                $mqtt->disconnect();
            } catch (\Exception $e) {
                // Ignore disconnect errors
            }
        }
    }
}
