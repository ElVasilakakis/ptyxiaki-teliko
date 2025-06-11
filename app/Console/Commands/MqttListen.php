<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MqttDeviceService;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen {--timeout=0 : Timeout in seconds (0 = no timeout)}';
    protected $description = 'Start the MQTT subscriber loop with Windows compatibility';

    public function handle(): int
    {
        $this->info('🚀 Starting MQTT listener...');
        $this->info('🔧 Debug mode enabled - will show all received messages');
        $this->info('⏹️  Press Ctrl+C to stop');
        
        $timeout = (int) $this->option('timeout');
        $startTime = time();
        
        if ($timeout > 0) {
            $this->info("⏰ Listener will run for {$timeout} seconds");
        }
        
        try {
            $service = new MqttDeviceService();
            
            // Windows-compatible timeout handling
            if ($timeout > 0) {
                $this->handleWithTimeout($service, $timeout, $startTime);
            } else {
                $this->handleWithoutTimeout($service);
            }
            
        } catch (\Exception $e) {
            $this->error('❌ MQTT listener failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
    
    private function handleWithTimeout(MqttDeviceService $service, int $timeout, int $startTime): void
    {
        // Create a wrapper that checks timeout periodically
        $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
        
        // Subscribe to topics
        $mqtt->subscribe(
            'devices/+/discovery/response', 
            \Closure::fromCallable([$service, 'handleDeviceDiscovery']),
            1
        );
        
        $mqtt->subscribe(
            'devices/+/data', 
            \Closure::fromCallable([$service, 'handleDeviceData']),
            1
        );
        
        $mqtt->subscribe(
            'devices/+/status', 
            \Closure::fromCallable([$service, 'handleDeviceStatus']),
            1
        );
        
        $mqtt->subscribe(
            'devices/discover/all', 
            \Closure::fromCallable([$service, 'handleGlobalDiscovery']),
            1
        );
        
        $this->info('✅ Subscribed to all MQTT topics');
        
        // Windows-compatible timeout loop
        while (true) {
            // Check if timeout reached
            if (time() - $startTime >= $timeout) {
                $this->info('⏰ Timeout reached, stopping listener...');
                break;
            }
            
            // Process MQTT messages for 1 second
            $mqtt->loop(false, true); // Non-blocking mode
            
            // Small delay to prevent CPU spinning
            usleep(100000); // 100ms
            
            // Show progress every 30 seconds
            $elapsed = time() - $startTime;
            if ($elapsed > 0 && $elapsed % 30 === 0) {
                $remaining = $timeout - $elapsed;
                $this->info("⏱️  Running for {$elapsed}s, {$remaining}s remaining...");
            }
        }
        
        $mqtt->disconnect();
    }
    
    private function handleWithoutTimeout(MqttDeviceService $service): void
    {
        // Run indefinitely without timeout
        $service->subscribeToDeviceTopics();
    }
}
