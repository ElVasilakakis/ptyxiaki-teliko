<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Sensor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

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
            
            $mqtt->subscribe('devices/+/discovery/response', \Closure::fromCallable([$this, 'handleDeviceDiscovery']), $this->qos);
            $mqtt->subscribe('devices/+/data', \Closure::fromCallable([$this, 'handleDeviceData']), $this->qos);
            $mqtt->subscribe('devices/+/status', \Closure::fromCallable([$this, 'handleDeviceStatus']), $this->qos);
            $mqtt->subscribe('devices/discover/all', \Closure::fromCallable([$this, 'handleGlobalDiscovery']), $this->qos);

            Log::info('MQTT subscriber started and listening for device messages.');
            $mqtt->loop(true);

        } catch (\Exception $e) {
            Log::error('MQTT subscription failed to start.', ['exception' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Publish a command to a specific device
     */
    public function publishDeviceCommand($deviceId, $command, $parameters = [])
    {
        try {
            $topic = "devices/{$deviceId}/commands";
            $payload = json_encode([
                'command' => $command,
                'parameters' => $parameters,
                'timestamp' => time(),
                'request_id' => uniqid('cmd_')
            ]);

            $mqtt = MQTT::connection();
            $mqtt->publish($topic, $payload, $this->qos);

            Log::info('Device command published', [
                'device_id' => $deviceId,
                'command' => $command,
                'topic' => $topic
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to publish device command', [
                'device_id' => $deviceId,
                'command' => $command,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Publish device discovery request
     */
    public function publishDeviceDiscovery($deviceId, $userId = null, $userEmail = null)
    {
        try {
            $topic = "devices/{$deviceId}/discover";
            $payload = json_encode([
                'action' => 'discover',
                'timestamp' => time(),
                'initiated_by' => $userEmail ?? auth()->user()->email ?? 'system',
                'request_id' => uniqid('disc_')
            ]);

            // Store user context for discovery response handling
            if ($userId) {
                cache()->put("mqtt_user_context", $userId, 300); // 5 minutes
            }

            $mqtt = MQTT::connection();
            $mqtt->publish($topic, $payload, $this->qos);

            Log::info('Device discovery request published', [
                'device_id' => $deviceId,
                'topic' => $topic
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to publish device discovery', [
                'device_id' => $deviceId,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function handleDeviceDiscovery(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['device_id'])) {
                Log::warning('Received discovery message with invalid format.', ['topic' => $topic]);
                return;
            }

            $device = Device::updateOrCreate(
                ['device_unique_id' => $data['device_id']],
                [
                    'user_id' => cache()->get("mqtt_user_context") ?? auth()->id() ?? 1,
                    'name' => $data['device_name'] ?? "Device " . $data['device_id'],
                    'device_type' => $data['device_type'] ?? 'ESP32_GENERIC',
                    'firmware_version' => $data['firmware_version'] ?? null,
                    'mac_address' => $data['mac_address'] ?? null,
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'enabled' => true
                ]
            );

            if (isset($data['available_sensors']) && is_array($data['available_sensors'])) {
                $this->syncSensorsFromArduino($device, $data['available_sensors']);
            }

            cache()->forget("mqtt_user_context");
            Log::info("Device processed successfully from discovery.", ['device_id' => $device->device_unique_id]);

        } catch (\Exception $e) {
            Log::error('Error processing device discovery.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    public function handleDeviceData(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['device_id'])) {
                return;
            }
            
            $device = Device::where('device_unique_id', $data['device_id'])->first();
            if (!$device) {
                Log::debug('Data received for unknown device', ['device_id' => $data['device_id']]);
                return;
            }

            $device->update(['status' => 'online', 'last_seen_at' => now()]);

            if (isset($data['sensors']) && is_array($data['sensors'])) {
                $this->updateSensorReadings($device, $data['sensors']);
            }

        } catch (\Exception $e) {
            Log::error('Error processing device data.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }
    
    public function handleDeviceStatus(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['device_id'])) {
                return;
            }
            
            $device = Device::where('device_unique_id', $data['device_id'])->first();
            if (!$device) {
                return;
            }
            
            $device->update([
                'status' => $data['status'] === 'online' ? 'online' : 'offline',
                'last_seen_at' => now(),
            ]);

            Log::debug('Device status updated', [
                'device_id' => $device->device_unique_id,
                'status' => $data['status']
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing device status.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    private function syncSensorsFromArduino(Device $device, array $sensorsData)
    {
        foreach ($sensorsData as $sensorData) {
            if (!isset($sensorData['sensor_type'])) {
                continue;
            }
            
            $sensor = Sensor::updateOrCreate(
                ['device_id' => $device->id, 'sensor_type' => $sensorData['sensor_type']],
                [
                    'sensor_name' => $sensorData['sensor_name'] ?? ucfirst($sensorData['sensor_type']),
                    'unit' => $sensorData['unit'] ?? null,
                    'value' => $sensorData['value'] ?? null,
                    'reading_timestamp' => now(),
                    'enabled' => true,
                ]
            );

            Log::debug('Sensor synced', [
                'device_id' => $device->device_unique_id,
                'sensor_type' => $sensorData['sensor_type'],
                'sensor_id' => $sensor->id
            ]);
        }
    }
    
    private function updateSensorReadings(Device $device, array $sensorsData)
    {
        foreach ($sensorsData as $sensorType => $value) {
            try {
                $sensor = $device->sensors()->where('sensor_type', $sensorType)->first();

                if (!$sensor) {
                    // Auto-create missing sensors instead of just warning
                    $sensor = Sensor::create([
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType,
                        'sensor_name' => ucfirst(str_replace('_', ' ', $sensorType)),
                        'unit' => $this->guessUnit($sensorType),
                        'value' => $value,
                        'reading_timestamp' => now(),
                        'enabled' => true,
                    ]);

                    Log::info('Auto-created missing sensor', [
                        'device_id' => $device->device_unique_id,
                        'sensor_type' => $sensorType,
                        'sensor_id' => $sensor->id
                    ]);
                } else {
                    $sensor->update([
                        'value' => $value + ($sensor->calibration_offset ?? 0),
                        'reading_timestamp' => now()
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Error updating sensor reading.', [
                    'device_id' => $device->device_unique_id,
                    'sensor_type' => $sensorType,
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Guess appropriate unit based on sensor type
     */
    private function guessUnit($sensorType)
    {
        $unitMap = [
            'temperature' => 'celsius',
            'humidity' => 'percent',
            'light' => 'percent',
            'potentiometer' => 'percent',
            'wifi_signal' => 'dBm',
            'battery' => 'percent',
            'pressure' => 'hPa',
            'voltage' => 'V',
            'current' => 'A',
        ];

        return $unitMap[$sensorType] ?? null;
    }
    
    public function handleGlobalDiscovery(string $topic, string $message)
    {
        Log::info("Global discovery request received via MQTT.");
        
        // Optionally broadcast discovery to all known devices
        $devices = Device::where('enabled', true)->get();
        foreach ($devices as $device) {
            $this->publishDeviceDiscovery($device->device_unique_id);
        }
    }
}
