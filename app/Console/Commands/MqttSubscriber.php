<?php

namespace App\Console\Commands;

use App\Services\MqttDeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class MqttSubscriber extends Command
{
    protected $signature = 'mqtt:subscribe {--timeout=0 : Timeout in seconds (0 for infinite)} {--debug : Enable debug output}';
    protected $description = 'Subscribe to MQTT topics for device management';
    
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
            $service->handleDeviceDiscovery($topic, $message);
        });
        
        // Subscribe to device data
        $mqtt->subscribe('devices/+/data', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("📊 Data: {$topic}");
                $this->line("   " . $message);
            }
            $service->handleDeviceData($topic, $message);
        });
        
        // Subscribe to device status
        $mqtt->subscribe('devices/+/status', function($topic, $message) use ($debug, $service) {
            if ($debug) {
                $this->info("💓 Status: {$topic}");
                $this->line("   " . $message);
            }
            $service->handleDeviceStatus($topic, $message);
        });
        
        // Subscribe to global discovery
        $mqtt->subscribe('devices/discover/all', function($topic, $message) use ($debug) {
            if ($debug) {
                $this->info("🔍 Global Discovery Request: {$topic}");
            }
        });
        
        $this->info('🚀 MQTT subscriber running. Press Ctrl+C to stop.');
        $this->newLine();
        
        // Start the loop
        $mqtt->loop(true);
    }
}
