<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\MqttBroker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttDeviceService
{
    private $connections = [];
    private $defaultQos;

    public function __construct()
    {
        $this->defaultQos = env('MQTT_QOS', 1);
    }

    /**
     * Get MQTT connection for a specific device
     */
    private function getConnectionForDevice(Device $device): MqttClient
    {
        $broker = $device->effective_mqtt_broker;
        
        if (!$broker) {
            throw new \Exception("No MQTT broker configured for device {$device->device_unique_id}");
        }

        $connectionKey = $broker->id . '_' . $device->id;

        if (!isset($this->connections[$connectionKey])) {
            $this->connections[$connectionKey] = $this->createConnection($broker, $device);
        }

        return $this->connections[$connectionKey];
    }

    /**
     * Create MQTT connection for broker and device
     */
    private function createConnection(MqttBroker $broker, Device $device): MqttClient
    {
        $connectionSettings = new ConnectionSettings();
        
        // Authentication
        if ($broker->username) {
            $connectionSettings->setUsername($broker->username);
            $connectionSettings->setPassword($broker->password);
        }

        // Connection settings
        $connectionSettings->setKeepAliveInterval($broker->keep_alive);
        $connectionSettings->setConnectTimeout($broker->connect_timeout);
        $connectionSettings->setUseTls($broker->tls_enabled);
        $connectionSettings->setUseCleanSession($broker->clean_session);

        // Last will
        if ($broker->last_will_topic) {
            $connectionSettings->setLastWillTopic($broker->last_will_topic);
            $connectionSettings->setLastWillMessage($broker->last_will_message);
            $connectionSettings->setLastWillQualityOfService($broker->last_will_qos);
            $connectionSettings->setRetainLastWill($broker->last_will_retain);
        }

        // Auto-reconnect
        if ($broker->auto_reconnect) {
            $connectionSettings->setAutoReconnect(
                $broker->max_reconnect_attempts,
                $broker->reconnect_delay
            );
        }

        $client = new MqttClient(
            $broker->host,
            $broker->port,
            $device->mqtt_client_id,
            MqttClient::MQTT_3_1_1
        );

        try {
            $client->connect($connectionSettings);
            $broker->updateConnectionStatus(true);
            
            Log::channel('mqtt')->info('MQTT connection established', [
                'broker' => $broker->name,
                'device' => $device->device_unique_id,
                'client_id' => $device->mqtt_client_id
            ]);
            
        } catch (\Exception $e) {
            $broker->updateConnectionStatus(false, $e->getMessage());
            throw $e;
        }

        return $client;
    }

    /**
     * Publish a command to a specific device using its broker
     */
    public function publishDeviceCommand($deviceId, $command, $parameters = [])
    {
        try {
            $device = Device::where('device_unique_id', $deviceId)->first();
            if (!$device) {
                throw new \Exception("Device {$deviceId} not found");
            }

            $topics = $device->mqtt_topics;
            $topic = $topics['commands'] ?? "devices/{$deviceId}/commands";
            
            $payload = json_encode([
                'command' => $command,
                'parameters' => $parameters,
                'timestamp' => time(),
                'request_id' => uniqid('cmd_')
            ]);

            $mqtt = $this->getConnectionForDevice($device);
            $qos = $device->effective_mqtt_broker->qos ?? $this->defaultQos;
            $mqtt->publish($topic, $payload, $qos);

            Log::channel('mqtt')->info('Device command published', [
                'device_id' => $deviceId,
                'command' => $command,
                'topic' => $topic,
                'broker' => $device->effective_mqtt_broker->name
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
     * Publish device discovery request using device's broker
     */
    public function publishDeviceDiscovery($deviceId, $userId = null, $userEmail = null)
    {
        try {
            $device = Device::where('device_unique_id', $deviceId)->first();
            if (!$device) {
                throw new \Exception("Device {$deviceId} not found");
            }

            $topics = $device->mqtt_topics;
            $topic = $topics['discovery_request'] ?? "devices/{$deviceId}/discover";
            
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

            $mqtt = $this->getConnectionForDevice($device);
            $qos = $device->effective_mqtt_broker->qos ?? $this->defaultQos;
            $mqtt->publish($topic, $payload, $qos);

            Log::channel('mqtt')->info('Device discovery published', [
                'device_id' => $deviceId,
                'topic' => $topic,
                'broker' => $device->effective_mqtt_broker->name
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

    /**
     * Publish to all devices on a specific broker
     */
    public function publishToBrokerDevices(MqttBroker $broker, string $topic, string $message)
    {
        try {
            // Create a temporary connection for broker-wide publishing
            $connectionSettings = new ConnectionSettings();
            
            if ($broker->username) {
                $connectionSettings->setUsername($broker->username);
                $connectionSettings->setPassword($broker->password);
            }

            $connectionSettings->setKeepAliveInterval($broker->keep_alive);
            $connectionSettings->setConnectTimeout($broker->connect_timeout);
            $connectionSettings->setUseTls($broker->tls_enabled);

            $client = new MqttClient(
                $broker->host,
                $broker->port,
                $broker->generateClientId('publisher'),
                MqttClient::MQTT_3_1_1
            );

            $client->connect($connectionSettings);
            $client->publish($topic, $message, $broker->qos);
            $client->disconnect();

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to publish to broker devices', [
                'broker_id' => $broker->id,
                'topic' => $topic,
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

            // Find device and get its broker info
            $existingDevice = Device::where('device_unique_id', $data['device_id'])->first();
            
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
                    'enabled' => true,
                    // Preserve existing broker assignment or use default
                    'mqtt_broker_id' => $existingDevice?->mqtt_broker_id ?? null
                ]
            );

            // If no broker assigned, assign to default
            if (!$device->mqtt_broker_id) {
                $defaultBroker = MqttBroker::where('is_default', true)->first();
                if ($defaultBroker) {
                    $device->assignToMqttBroker($defaultBroker, $device->device_unique_id);
                }
            }

            if (isset($data['available_sensors']) && is_array($data['available_sensors'])) {
                $this->syncSensorsFromArduino($device, $data['available_sensors']);
            }

            cache()->forget("mqtt_user_context");

            Log::channel('mqtt')->info('Device discovery processed', [
                'device_id' => $data['device_id'],
                'broker' => $device->effective_mqtt_broker?->name ?? 'none'
            ]);

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
                // Check if sensors is an array of objects (Arduino format)
                if (isset($data['sensors'][0]) && is_array($data['sensors'][0])) {
                    $this->updateSensorReadingsFromArray($device, $data['sensors']);
                } else {
                    // Handle the key-value format
                    $this->updateSensorReadings($device, $data['sensors']);
                }
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

            // Update device status and location in application_data
            $device->update(['status' => 'online', 'last_seen_at' => now()]);
            
            if (isset($data['location']) && is_array($data['location'])) {
                $device->updateLocationFromMqtt($data['location']);
                $device->save();
                
                // Also store as sensor data
                $this->storeGPSAsSensorData($device, $data['location'], $data['timestamp'] ?? null);
            }

        } catch (\Exception $e) {
            Log::error('Error processing device GPS sensor data.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    public function handleGlobalDiscovery(string $topic, string $message)
    {
        try {
            // Get all enabled devices and publish discovery to each using their respective brokers
            $devices = Device::where('enabled', true)->with('mqttBroker')->get();
            
            foreach ($devices as $device) {
                $this->publishDeviceDiscovery($device->device_unique_id);
            }

            Log::channel('mqtt')->info('Global discovery processed', [
                'devices_count' => $devices->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing global discovery.', ['topic' => $topic, 'exception' => $e->getMessage()]);
        }
    }

    // Keep all your existing private methods unchanged
    private function updateSensorReadingsFromArray(Device $device, array $sensorsArray)
    {
        foreach ($sensorsArray as $sensorData) {
            try {
                if (!isset($sensorData['sensor_type']) || !isset($sensorData['value'])) {
                    continue;
                }

                $sensorType = $sensorData['sensor_type'];
                $value = $sensorData['value'];
                
                $sensor = $device->sensors()->where('sensor_type', $sensorType)->first();

                if (!$sensor) {
                    // Auto-create missing sensors
                    $sensor = Sensor::create([
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType,
                        'sensor_name' => $sensorData['sensor_name'] ?? ucfirst(str_replace('_', ' ', $sensorType)),
                        'unit' => $sensorData['unit'] ?? $this->guessUnit($sensorType),
                        'value' => $value,
                        'reading_timestamp' => isset($sensorData['reading_timestamp']) ? 
                            Carbon::parse($sensorData['reading_timestamp']) : now(),
                        'enabled' => $sensorData['enabled'] ?? true,
                    ]);
                } else {
                    $sensor->update([
                        'value' => $value + ($sensor->calibration_offset ?? 0),
                        'reading_timestamp' => isset($sensorData['reading_timestamp']) ? 
                            Carbon::parse($sensorData['reading_timestamp']) : now()
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Error updating sensor reading from array.', [
                    'device_id' => $device->device_unique_id,
                    'sensor_data' => $sensorData,
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }

    // ... Keep all other existing private methods unchanged ...
    // (storeGPSAsSensorData, syncSensorsFromArduino, createGPSSensorsFromDiscovery, 
    //  getGPSValueFromDiscovery, updateSensorReadings, guessUnit)

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

    /**
     * Close connection for a specific device
     */
    public function closeConnectionForDevice(Device $device): void
    {
        $connectionKey = $device->mqtt_broker_id . '_' . $device->id;
        
        if (isset($this->connections[$connectionKey])) {
            try {
                $this->connections[$connectionKey]->disconnect();
            } catch (\Exception $e) {
                // Ignore disconnect errors
            }
            unset($this->connections[$connectionKey]);
        }
    }

    /**
     * Close all connections
     */
    public function closeAllConnections(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Ignore disconnect errors
            }
        }
        $this->connections = [];
    }
}
