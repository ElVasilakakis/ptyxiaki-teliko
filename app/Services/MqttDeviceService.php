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
                return; // Silently ignore data for unknown devices
            }

            $device->update(['status' => 'online', 'last_seen_at' => now()]);

            if (isset($data['sensors']) && is_array($data['sensors'])) {
                // *** FIX: Do not pass the unreliable device timestamp. ***
                $this->updateSensorReadings($device, $data['sensors']);
            }

        } catch (\Exception $e) {
            Log::error('Error processing device data.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }
    
    public function handleDeviceStatus(string $topic, string $message)
    {
        // ... Unchanged ...
        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['device_id'])) return;
            $device = Device::where('device_unique_id', $data['device_id'])->first();
            if (!$device) return;
            $device->update([
                'status' => $data['status'] === 'online' ? 'online' : 'offline',
                'last_seen_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing device status.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    private function syncSensorsFromArduino(Device $device, array $sensorsData)
    {
        foreach ($sensorsData as $sensorData) {
            if (!isset($sensorData['sensor_type'])) continue;
            
            Sensor::updateOrCreate(
                ['device_id' => $device->id, 'sensor_type' => $sensorData['sensor_type']],
                [
                    'sensor_name' => $sensorData['sensor_name'] ?? ucfirst($sensorData['sensor_type']),
                    'unit' => $sensorData['unit'] ?? null,
                    'value' => $sensorData['value'] ?? null,
                    'reading_timestamp' => now(), // Use server time for initial reading
                    'enabled' => true,
                ]
            );
        }
    }
    
    // *** FIX: Removed the $timestamp parameter and now use now() for all updates. ***
    private function updateSensorReadings(Device $device, array $sensorsData)
    {
        foreach ($sensorsData as $sensorType => $value) {
            try {
                $sensor = $device->sensors()->where('sensor_type', $sensorType)->first();

                if (!$sensor) {
                    Log::warning('Data received for an unsynced sensor.', [
                        'device_id' => $device->device_unique_id,
                        'sensor_type' => $sensorType,
                    ]);
                    continue;
                }
                
                $sensor->update([
                    'value' => $value + ($sensor->calibration_offset ?? 0),
                    'reading_timestamp' => now() // Use the reliable server time
                ]);

            } catch (\Exception $e) {
                // Now we log the actual error with context
                Log::error('Error updating a single sensor reading.', [
                    'device_id' => $device->id,
                    'sensor_type' => $sensorType,
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }
    
    public function handleGlobalDiscovery(string $topic, string $message)
    {
        Log::info("Global discovery request received via MQTT.");
    }
}
