<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Sensor;
use PhpMqtt\Client\Facades\MQTT;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class MqttDeviceService
{
    private $qos;
    
    public function __construct()
    {
        $this->qos = env('MQTT_QOS', 1);
    }
    
    public function subscribeToDeviceTopics()
    {
        try {
            $mqtt = MQTT::connection();
            
            // Subscribe to device topics
            $mqtt->subscribe('devices/+/discovery/response', [$this, 'handleDeviceDiscovery'], $this->qos);
            $mqtt->subscribe('devices/+/data', [$this, 'handleDeviceData'], $this->qos);
            $mqtt->subscribe('devices/+/status', [$this, 'handleDeviceStatus'], $this->qos);
            $mqtt->subscribe('devices/discover/all', [$this, 'handleGlobalDiscovery'], $this->qos);
            
            Log::info('MQTT subscriber started successfully');
            $mqtt->loop(true);
            
        } catch (\Exception $e) {
            Log::error('MQTT subscription error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function handleDeviceDiscovery(string $topic, string $message)
    {
        try {
            Log::info("Raw discovery message received", [
                'topic' => $topic,
                'message' => $message
            ]);
            
            $data = json_decode($message, true);
            
            if (!$data) {
                Log::error("Failed to decode JSON message", ['message' => $message]);
                return;
            }
            
            $deviceId = $data['device_id'];
            
            Log::info("Device discovery received for: {$deviceId}", $data);
            
            // Get user from cache (set by Filament action) or fallback
            $userId = cache()->get("mqtt_user_context") ?? 
                    auth()->id() ?? 
                    \App\Models\User::first()?->id ?? 1;
            
            // Create or update device with proper user assignment
            $device = Device::updateOrCreate(
                ['device_unique_id' => $deviceId],
                [
                    'user_id' => $userId,
                    'name' => $data['device_name'],
                    'device_type' => $data['device_type'],
                    'firmware_version' => $data['firmware_version'] ?? null,
                    'mac_address' => $data['mac_address'] ?? null,
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'enabled' => true,
                    'health_percentage' => 100,
                    'application_data' => [
                        'ip_address' => $data['ip_address'] ?? null,
                        'capabilities' => $data['capabilities'] ?? [],
                        'chip_model' => 'ESP32',
                    ],
                    'status_details' => [
                        'wifi_connected' => true,
                        'wifi_rssi' => $data['wifi_rssi'] ?? null,
                        'uptime_seconds' => $data['uptime'] ?? null,
                        'free_heap' => $data['free_heap'] ?? null,
                    ]
                ]
            );
            
            Log::info("Device created/updated", [
                'device_id' => $device->id,
                'user_id' => $userId,
                'was_recently_created' => $device->wasRecentlyCreated
            ]);
            
            // Create sensors using your existing model structure
            if (isset($data['available_sensors'])) {
                Log::info("Processing sensors", ['sensor_count' => count($data['available_sensors'])]);
                $this->syncSensorsFromArduino($device, $data['available_sensors']);
            } else {
                Log::warning("No available_sensors found in discovery data");
            }
            
            // Clear the user context cache after successful processing
            cache()->forget("mqtt_user_context");
            
            Log::info("Device {$deviceId} registered with " . count($data['available_sensors'] ?? []) . " sensors");
            
        } catch (\Exception $e) {
            Log::error("Error processing device discovery: " . $e->getMessage(), [
                'exception' => $e,
                'topic' => $topic,
                'message' => $message
            ]);
        }
    }




    
    public function handleDeviceData(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            $deviceId = $data['device_id'];
            
            $device = Device::where('device_unique_id', $deviceId)->first();
            if (!$device) {
                Log::warning("Device not found: {$deviceId}");
                return;
            }
            
            // Update device status
            $device->update([
                'status' => 'online',
                'last_seen_at' => now(),
                'health_percentage' => $this->calculateHealthFromSensorData($data)
            ]);
            
            // Update sensor readings using your existing model
            if (isset($data['sensors'])) {
                $this->updateSensorReadings($device, $data['sensors'], $data['timestamp'] ?? time());
            }
            
            Log::info("Updated sensor data for device: {$deviceId}");
            
        } catch (\Exception $e) {
            Log::error("Error processing device data: " . $e->getMessage());
        }
    }
    
    public function handleDeviceStatus(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            $deviceId = $data['device_id'];
            
            $device = Device::where('device_unique_id', $deviceId)->first();
            if ($device) {
                $device->update([
                    'status' => $data['status'] === 'online' ? 'online' : 'offline',
                    'last_seen_at' => now(),
                    'status_details' => array_merge($device->status_details ?? [], [
                        'uptime_seconds' => $data['uptime'] ?? null,
                        'free_heap' => $data['free_heap'] ?? null,
                        'wifi_rssi' => $data['wifi_rssi'] ?? null,
                    ])
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error("Error processing device status: " . $e->getMessage());
        }
    }
    
    private function syncSensorsFromArduino(Device $device, array $sensorsData)
    {
        Log::info("Starting sensor sync for device {$device->device_unique_id}");
        
        foreach ($sensorsData as $index => $sensorData) {
            Log::info("Processing sensor {$index}", $sensorData);
            
            // Map Arduino sensor types to your sensor types
            $sensorType = $this->mapArduinoSensorType($sensorData['sensor_type']);
            
            try {
                $sensor = Sensor::updateOrCreate(
                    [
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType
                    ],
                    [
                        'sensor_name' => $sensorData['sensor_name'],
                        'description' => $sensorData['description'],
                        'location' => $sensorData['location'],
                        'unit' => $sensorData['unit'],
                        'accuracy' => $sensorData['accuracy'],
                        'thresholds' => $sensorData['thresholds'] ?? null,
                        'enabled' => true,
                        'alert_enabled' => true,
                        'alert_threshold_min' => $sensorData['thresholds']['min'] ?? null,
                        'alert_threshold_max' => $sensorData['thresholds']['max'] ?? null,
                        'last_calibration' => now(),
                    ]
                );
                
                Log::info("Sensor created/updated", [
                    'sensor_id' => $sensor->id,
                    'sensor_type' => $sensorType,
                    'sensor_name' => $sensorData['sensor_name']
                ]);
                
            } catch (\Exception $e) {
                Log::error("Failed to create/update sensor", [
                    'sensor_data' => $sensorData,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    
    private function updateSensorReadings(Device $device, array $sensorsData, int $timestamp)
    {
        foreach ($sensorsData as $sensorType => $value) {
            // Map Arduino sensor types
            $mappedSensorType = $this->mapArduinoSensorType($sensorType);
            
            $sensor = $device->sensors()->where('sensor_type', $mappedSensorType)->first();
            if ($sensor) {
                // Apply calibration offset if exists
                $calibratedValue = $value + ($sensor->calibration_offset ?? 0);
                
                // Update sensor with new reading
                $sensor->update([
                    'value' => $calibratedValue,
                    'reading_timestamp' => Carbon::createFromTimestamp($timestamp)
                ]);
                
                // Log if sensor is out of thresholds
                if (!$sensor->isWithinThresholds()) {
                    Log::warning("Sensor {$sensor->sensor_name} value {$calibratedValue} is outside thresholds", [
                        'device_id' => $device->device_unique_id,
                        'sensor_type' => $sensor->sensor_type,
                        'value' => $calibratedValue,
                        'thresholds' => $sensor->thresholds
                    ]);
                }
            }
        }
    }
    
    private function mapArduinoSensorType(string $arduinoType): string
    {
        // Map Arduino sensor types to your system's sensor types
        return match($arduinoType) {
            'temperature' => 'temperature',
            'humidity' => 'humidity',
            'light', 'light_level' => 'light',
            'wifi_signal' => 'wifi_signal', // Changed from 'signal' to 'wifi_signal'
            'battery' => 'battery',
            default => $arduinoType
        };
    }

    
    private function calculateHealthFromSensorData(array $data): int
    {
        $health = 100;
        
        // Check WiFi signal strength
        if (isset($data['sensors']['wifi_signal'])) {
            $rssi = $data['sensors']['wifi_signal'];
            if ($rssi < -80) $health -= 30;
            elseif ($rssi < -70) $health -= 15;
            elseif ($rssi < -60) $health -= 5;
        }
        
        // Check if device has been running for a reasonable time
        if (isset($data['uptime']) && $data['uptime'] < 300) { // Less than 5 minutes
            $health -= 20; // Recent restart
        }
        
        // Check free heap memory
        if (isset($data['free_heap']) && $data['free_heap'] < 10000) {
            $health -= 25; // Low memory
        }
        
        return max(0, min(100, $health));
    }
    
    public function publishDeviceCommand(string $deviceId, string $command, array $payload = [])
    {
        try {
            $mqtt = MQTT::connection();
            $topic = "devices/{$deviceId}/control/{$command}";
            $message = json_encode($payload);
            
            $mqtt->publish($topic, $message, $this->qos);
            Log::info("Command sent to device {$deviceId}: {$command}", $payload);
            
        } catch (\Exception $e) {
            Log::error("Error sending command to device: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function discoverAllDevices()
    {
        try {
            $mqtt = MQTT::connection();
            $mqtt->publish('devices/discover/all', 'discover', $this->qos);
            Log::info("Global device discovery request sent");
            
        } catch (\Exception $e) {
            Log::error("Error sending discovery request: " . $e->getMessage());
            throw $e;
        }
    }
}
