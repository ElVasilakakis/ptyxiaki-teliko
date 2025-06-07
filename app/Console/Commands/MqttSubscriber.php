<?php

namespace App\Console\Commands;

use App\Services\MqttDeviceService;
use Illuminate\Console\Command;

class MqttSubscriber extends Command
{
    protected $signature = 'mqtt:subscribe {--timeout=0 : Timeout in seconds (0 for infinite)}';
    protected $description = 'Subscribe to MQTT topics for device management';
    
    public function handle()
    {
        $this->info('Starting MQTT subscriber for device management...');
        $this->info('MQTT Broker: ' . env('MQTT_HOST') . ':' . env('MQTT_PORT'));
        $this->info('Client ID: ' . env('MQTT_CLIENT_ID'));
        
        try {
            $service = new MqttDeviceService();
            $service->subscribeToDeviceTopics();
            
        } catch (\Exception $e) {
            $this->error('MQTT Subscriber failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
}
