<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;

class SubscribeToTopic extends Command
{
    protected $signature = 'mqtt:subscribe';
    protected $description = 'Subscribe To MQTT topic';

    public function handle()
    {
        $mqtt = MQTT::connection();
        
        $mqtt->subscribe('devices/+/status', function(string $topic, string $message) {
            echo sprintf('Received message on topic [%s]: %s', $topic, $message);
            // Process your message here (save to database, etc.)
        });
        
        $mqtt->loop(true);
        return Command::SUCCESS;
    }
}
