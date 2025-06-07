<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Illuminate\Support\Facades\Log;
use App\Services\MqttDeviceService;
use Illuminate\Support\Facades\Cache;

class TestMqttDiscovery extends Command
{
    protected $signature = 'mqtt:test-discovery 
                            {device_id? : Specific device ID to discover}
                            {--timeout=30 : Timeout in seconds to wait for responses}
                            {--global : Send a global discovery request instead of device-specific}
                            {--json : Output detailed JSON response}';
                            
    protected $description = 'Test MQTT discovery with detailed logging and analysis';

    public function handle()
    {
        $deviceId = $this->argument('device_id') ?? 'ESP32-DEV-001';
        $timeout = (int)$this->option('timeout');
        $useGlobal = $this->option('global');
        $showJson = $this->option('json');
        
        $startTime = microtime(true);
        
        $this->components->info('Starting MQTT discovery test...');
        
        // Log test parameters
        Log::channel('mqtt')->info('MQTT discovery test started', [
            'device_id' => $deviceId,
            'timeout' => $timeout,
            'global_mode' => $useGlobal,
            'broker' => env('MQTT_HOST') . ':' . env('MQTT_PORT'),
            'client_id' => env('MQTT_CLIENT_ID') . '_test',
            'timestamp' => now()->toIso8601String()
        ]);
        
        try {
            // Store current user context for discovery
            Cache::put("mqtt_user_context", 1, now()->addMinutes(5));
            
            // Create a new MQTT connection specifically for this test
            $mqtt = MQTT::connection();
            
            // Create device service
            $deviceService = new MqttDeviceService();
            
            // Subscribe to the appropriate discovery response topic
            if ($useGlobal) {
                $this->components->info('Running in GLOBAL discovery mode');
                $this->components->info('Subscribing to all device discovery responses');
                
                // Subscribe to all device discovery responses
                $mqtt->subscribe('devices/+/discovery/response', function(string $topic, string $message) use ($showJson) {
                    $this->handleDiscoveryResponse($topic, $message, $showJson);
                });
                
                // Send global discovery request using the service
                $this->components->task('Sending global discovery request', function() use ($deviceService) {
                    $deviceService->discoverAllDevices();
                    return true;
                });
                
            } else {
                $this->components->info('Running in DEVICE-SPECIFIC discovery mode');
                
                // Subscribe to the specific device discovery response
                $responseTopic = "devices/{$deviceId}/discovery/response";
                $this->components->info("Subscribing to: {$responseTopic}");
                
                $mqtt->subscribe($responseTopic, function(string $topic, string $message) use ($showJson) {
                    $this->handleDiscoveryResponse($topic, $message, $showJson);
                });
                
                // Send device-specific discovery request
                $discoveryTopic = "devices/{$deviceId}/discover";
                $this->components->task("Sending discovery request to {$deviceId}", function() use ($mqtt, $discoveryTopic) {
                    // Create a more detailed discovery payload
                    $payload = json_encode([
                        'action' => 'discover',
                        'timestamp' => time(),
                        'initiated_by' => 'test_command',
                        'request_id' => uniqid('test_', true)
                    ]);
                    
                    $mqtt->publish($discoveryTopic, $payload);
                    return true;
                });
            }
            
            // Keep the connection open for a while to receive responses
            $this->components->info("Waiting for responses ({$timeout} seconds)...");
            $waitStart = time();
            $responseCount = 0;
            
            $this->output->progressStart($timeout);
            
            while (time() - $waitStart < $timeout) {
                $mqtt->loop(false);
                usleep(100000); // 100ms pause
                
                // Update progress bar every second
                if ((time() - $waitStart) > $this->output->getProgressBar()->getProgress()) {
                    $this->output->progressAdvance();
                }
            }
            
            $this->output->progressFinish();
            
            // Calculate and log test results
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->newLine();
            $this->components->info("Test completed in {$duration} seconds");
            $this->components->info("Check the mqtt.log file for detailed logs");
            
            // Log test completion
            Log::channel('mqtt')->info('MQTT discovery test completed', [
                'duration_seconds' => $duration,
                'device_id' => $deviceId,
                'global_mode' => $useGlobal,
                'timestamp' => now()->toIso8601String()
            ]);
            
        } catch (\Exception $e) {
            $this->components->error('MQTT Test failed: ' . $e->getMessage());
            
            Log::channel('mqtt')->error('MQTT discovery test failed', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'device_id' => $deviceId,
                'global_mode' => $useGlobal,
                'duration_seconds' => round(microtime(true) - $startTime, 2)
            ]);
            
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
    
    private function handleDiscoveryResponse(string $topic, string $message, bool $showJson = false)
    {
        // Extract device ID from topic (format: devices/{device_id}/discovery/response)
        $topicParts = explode('/', $topic);
        $deviceId = $topicParts[1] ?? 'unknown';
        
        $this->components->info("✅ RECEIVED RESPONSE from device: {$deviceId}");
        
        // Log the raw message for analysis
        Log::channel('mqtt')->info("Discovery response received", [
            'topic' => $topic,
            'device_id' => $deviceId,
            'message_size' => strlen($message),
            'timestamp' => now()->toIso8601String()
        ]);
        
        // Try to decode the JSON
        $data = json_decode($message, true);
        if ($data) {
            $this->components->info("✅ JSON successfully decoded");
            
            // Display basic device info
            $this->components->twoColumnDetail('Device ID', $data['device_id'] ?? 'Not found');
            $this->components->twoColumnDetail('Device Name', $data['device_name'] ?? 'Not found');
            $this->components->twoColumnDetail('Device Type', $data['device_type'] ?? 'Not found');
            $this->components->twoColumnDetail('Firmware', $data['firmware_version'] ?? 'Not found');
            $this->components->twoColumnDetail('IP Address', $data['ip_address'] ?? 'Not found');
            $this->components->twoColumnDetail('MAC Address', $data['mac_address'] ?? 'Not found');
            $this->components->twoColumnDetail('Uptime', ($data['uptime'] ?? 0) . ' seconds');
            $this->components->twoColumnDetail('Sensors', count($data['available_sensors'] ?? []) . ' found');
            
            // Show sensor details
            if (!empty($data['available_sensors'])) {
                $this->newLine();
                $this->components->info('Sensor Information:');
                
                $sensorRows = [];
                foreach ($data['available_sensors'] as $sensor) {
                    $sensorRows[] = [
                        $sensor['sensor_type'] ?? 'unknown',
                        $sensor['sensor_name'] ?? 'Unnamed',
                        $sensor['unit'] ?? '-',
                        isset($sensor['thresholds']) ? 
                            "min: " . ($sensor['thresholds']['min'] ?? 'N/A') . ", max: " . ($sensor['thresholds']['max'] ?? 'N/A') : 
                            'No thresholds'
                    ];
                }
                
                $this->table(
                    ['Type', 'Name', 'Unit', 'Thresholds'],
                    $sensorRows
                );
            }
            
            // Show full JSON if requested
            if ($showJson) {
                $this->newLine();
                $this->components->info('Full JSON Response:');
                $this->line(json_encode($data, JSON_PRETTY_PRINT));
            }
            
        } else {
            $this->components->error("❌ Failed to decode JSON message");
            $this->line("Raw message: " . substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''));
            
            Log::channel('mqtt')->warning("Failed to decode discovery JSON", [
                'topic' => $topic,
                'device_id' => $deviceId,
                'json_error' => json_last_error_msg(),
                'message_preview' => substr($message, 0, 500)
            ]);
        }
        
        $this->newLine();
    }
}
