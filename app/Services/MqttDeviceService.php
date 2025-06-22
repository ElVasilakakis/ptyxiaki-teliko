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
            $mqtt->subscribe('devices/+/gps', \Closure::fromCallable([$this, 'handleDeviceGPS']), $this->qos);
            $mqtt->subscribe('devices/discover/all', \Closure::fromCallable([$this, 'handleGlobalDiscovery']), $this->qos);

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

        } catch (\Exception $e) {
            Log::error('Error processing device status.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * Handle GPS data from devices - treat GPS as sensor data
     */
    public function handleDeviceGPS(string $topic, string $message)
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

            // Update device status
            $device->update(['status' => 'online', 'last_seen_at' => now()]);

            // Process GPS location data as sensor readings
            if (isset($data['location']) && is_array($data['location'])) {
                $this->storeGPSAsSensorData($device, $data['location'], $data['timestamp'] ?? null);
            }

        } catch (\Exception $e) {
            Log::error('Error processing device GPS sensor data.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * Store GPS data as individual sensor readings
     */
    private function storeGPSAsSensorData(Device $device, array $locationData, $timestamp = null)
    {
        try {
            $readingTimestamp = $timestamp ? Carbon::parse($timestamp) : now();
            
            // Define GPS sensor mappings
            $gpsSensorMappings = [
                'gps_latitude' => [
                    'name' => 'GPS Latitude',
                    'unit' => 'degrees',
                    'value' => $locationData['latitude'] ?? null
                ],
                'gps_longitude' => [
                    'name' => 'GPS Longitude', 
                    'unit' => 'degrees',
                    'value' => $locationData['longitude'] ?? null
                ],
                'gps_altitude' => [
                    'name' => 'GPS Altitude',
                    'unit' => 'meters',
                    'value' => $locationData['altitude'] ?? null
                ],
                'gps_speed' => [
                    'name' => 'GPS Speed',
                    'unit' => 'km/h',
                    'value' => $locationData['speed_kmh'] ?? null
                ],
                'gps_satellites' => [
                    'name' => 'GPS Satellites',
                    'unit' => 'count',
                    'value' => $locationData['satellites'] ?? null
                ],
                'gps_valid' => [
                    'name' => 'GPS Signal Valid',
                    'unit' => 'boolean',
                    'value' => ($locationData['valid'] ?? false) ? 1 : 0
                ]
            ];

            foreach ($gpsSensorMappings as $sensorType => $sensorConfig) {
                if ($sensorConfig['value'] !== null) {
                    // Find or create the sensor
                    $sensor = $device->sensors()->where('sensor_type', $sensorType)->first();

                    if (!$sensor) {
                        $sensor = Sensor::create([
                            'device_id' => $device->id,
                            'sensor_type' => $sensorType,
                            'sensor_name' => $sensorConfig['name'],
                            'unit' => $sensorConfig['unit'],
                            'value' => $sensorConfig['value'],
                            'reading_timestamp' => $readingTimestamp,
                            'enabled' => true,
                        ]);
                    } else {
                        // Update existing sensor
                        $sensor->update([
                            'value' => $sensorConfig['value'],
                            'reading_timestamp' => $readingTimestamp
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Error storing GPS sensor data.', [
                'device_id' => $device->device_unique_id,
                'exception' => $e->getMessage()
            ]);
        }
    }

    private function syncSensorsFromArduino(Device $device, array $sensorsData)
    {
        foreach ($sensorsData as $sensorData) {
            if (!isset($sensorData['sensor_type'])) {
                continue;
            }
            
            // Handle GPS sensor specially in discovery
            if ($sensorData['sensor_type'] === 'gps') {
                // Create GPS-related sensors if they don't exist
                $this->createGPSSensorsFromDiscovery($device, $sensorData);
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
        }
    }

    /**
     * Create GPS sensors from discovery response
     */
    private function createGPSSensorsFromDiscovery(Device $device, array $gpsData)
    {
        $gpsSensorTypes = [
            'gps_latitude' => ['name' => 'GPS Latitude', 'unit' => 'degrees'],
            'gps_longitude' => ['name' => 'GPS Longitude', 'unit' => 'degrees'],
            'gps_altitude' => ['name' => 'GPS Altitude', 'unit' => 'meters'],
            'gps_speed' => ['name' => 'GPS Speed', 'unit' => 'km/h'],
            'gps_satellites' => ['name' => 'GPS Satellites', 'unit' => 'count'],
            'gps_valid' => ['name' => 'GPS Signal Valid', 'unit' => 'boolean']
        ];

        foreach ($gpsSensorTypes as $sensorType => $config) {
            $sensor = Sensor::updateOrCreate(
                ['device_id' => $device->id, 'sensor_type' => $sensorType],
                [
                    'sensor_name' => $config['name'],
                    'unit' => $config['unit'],
                    'value' => $this->getGPSValueFromDiscovery($gpsData, $sensorType),
                    'reading_timestamp' => now(),
                    'enabled' => true,
                ]
            );
        }
    }

    /**
     * Extract GPS values from discovery data
     */
    private function getGPSValueFromDiscovery(array $gpsData, string $sensorType)
    {
        switch ($sensorType) {
            case 'gps_latitude':
                return $gpsData['latitude'] ?? null;
            case 'gps_longitude':
                return $gpsData['longitude'] ?? null;
            case 'gps_altitude':
                return null; // Not available in discovery
            case 'gps_speed':
                return null; // Not available in discovery
            case 'gps_satellites':
                return null; // Not available in discovery
            case 'gps_valid':
                return ($gpsData['valid'] ?? false) ? 1 : 0;
            default:
                return null;
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
            'gps_latitude' => 'degrees',
            'gps_longitude' => 'degrees',
            'gps_altitude' => 'meters',
            'gps_speed' => 'km/h',
            'gps_satellites' => 'count',
            'gps_valid' => 'boolean',
        ];

        return $unitMap[$sensorType] ?? null;
    }
    
    public function handleGlobalDiscovery(string $topic, string $message)
    {
        // Optionally broadcast discovery to all known devices
        $devices = Device::where('enabled', true)->get();
        foreach ($devices as $device) {
            $this->publishDeviceDiscovery($device->device_unique_id);
        }
    }
}
