<?php

namespace App\Console\Commands;

use App\Services\MqttDeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class MqttSubscriber extends Command
{
    protected $signature = 'mqtt:subscribe {--timeout=0 : Timeout in seconds (0 for infinite)} {--debug : Enable debug output}';
    protected $description = 'Subscribe to MQTT topics for device management including GPS data';
    
    public function handle()
    {
        $this->info('Starting MQTT subscriber for IoT device management...');
        $this->info('MQTT Broker: ' . env('MQTT_HOST') . ':' . env('MQTT_PORT'));
        $this->info('Client ID: ' . env('MQTT_CLIENT_ID'));
        
        if ($this->option('debug')) {
            $this->info('🔍 Debug mode enabled - will show all received messages');
        }
        
        // Log startup to MQTT channel
        Log::channel('mqtt')->info('MQTT subscriber service starting', [
            'broker' => env('MQTT_HOST') . ':' . env('MQTT_PORT'),
            'client_id' => env('MQTT_CLIENT_ID'),
            'timeout' => $this->option('timeout'),
            'debug' => $this->option('debug')
        ]);
        
        try {
            // Test MQTT connection first
            $mqtt = MQTT::connection();
            $this->info('✓ Successfully connected to MQTT broker');
            Log::channel('mqtt')->info('MQTT connection successful');
            
            // Create service and subscribe to topics
            $service = new MqttDeviceService();
            
            // Subscribe to topics with debug output
            $this->info('📡 Subscribing to device topics...');
            $this->newLine();
            $this->info('Listening for:');
            $this->line('  • devices/+/discovery/response (Device registration)');
            $this->line('  • devices/+/data (Sensor data)');
            $this->line('  • devices/+/status (Device status)');
            $this->line('  • devices/+/gps (GPS sensor data)');
            $this->line('  • devices/+/control/response (Control responses)');
            $this->line('  • devices/discover/all (Global discovery)');
            $this->newLine();
            
            // Start subscription with debug callback
            $this->subscribeWithDebug($service);
            
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
    
    private function subscribeWithDebug(MqttDeviceService $service)
    {
        $mqtt = MQTT::connection();
        $debug = $this->option('debug');
        
        // Subscribe to discovery responses
        $mqtt->subscribe('devices/+/discovery/response', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("📥 Discovery: {$topic}");
                $this->line("   Data: " . substr($message, 0, 100) . "...");
            }
            $this->logMessage('discovery', $topic, $message);
            $service->handleDeviceDiscovery($topic, $message);
        });
        
        // Subscribe to device data
        $mqtt->subscribe('devices/+/data', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("📊 Data: {$topic}");
                $this->line("   " . $message);
            }
            $this->logMessage('data', $topic, $message);
            $service->handleDeviceData($topic, $message);
        });
        
        // Subscribe to device status
        $mqtt->subscribe('devices/+/status', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("💓 Status: {$topic}");
                $this->line("   " . $message);
            }
            $this->logMessage('status', $topic, $message);
            $service->handleDeviceStatus($topic, $message);
        });
        
        // Subscribe to GPS sensor data
        $mqtt->subscribe('devices/+/gps', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("📍 GPS: {$topic}");
                $this->line("   " . $message);
                
                // Show GPS coordinates in debug mode
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
            $this->logMessage('gps', $topic, $message);
            $service->handleDeviceGPS($topic, $message);
        });
        
        // Subscribe to control responses
        $mqtt->subscribe('devices/+/control/response', function($topic, $message) use ($debug) {
            if ($debug) {
                $this->info("🎛️  Control Response: {$topic}");
                $this->line("   " . $message);
            }
            $this->logMessage('control_response', $topic, $message);
        });
        
        // Subscribe to global discovery
        $mqtt->subscribe('devices/discover/all', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("🔍 Global Discovery Request: {$topic}");
                $this->line("   " . $message);
            }
            $this->logMessage('global_discovery', $topic, $message);
            $service->handleGlobalDiscovery($topic, $message);
        });
        
        $this->info('🚀 MQTT subscriber running. Press Ctrl+C to stop.');
        $this->info('💡 Use --debug flag to see real-time message details');
        $this->newLine();
        
        // Show statistics periodically
        $this->showStatistics();
        
        // Start the loop
        $mqtt->loop(true);
    }
    
    /**
     * Log MQTT messages to dedicated channel
     */
    private function logMessage($type, $topic, $message)
    {
        Log::channel('mqtt')->info("MQTT message received", [
            'type' => $type,
            'topic' => $topic,
            'message' => $message,
            'timestamp' => now()->toISOString()
        ]);
    }
    
    /**
     * Show periodic statistics
     */
    private function showStatistics()
    {
        // This could be enhanced to show real statistics
        $this->info('📈 MQTT Subscriber Statistics:');
        $this->line('   • Service started at: ' . now()->format('Y-m-d H:i:s'));
        $this->line('   • Monitoring 6 topic patterns');
        $this->line('   • GPS tracking: Enabled');
        $this->newLine();
    }
}
