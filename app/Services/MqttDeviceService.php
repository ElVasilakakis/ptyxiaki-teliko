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
            
            // Subscribe to device topics with correct parameter order: topic, callback, qos
            $mqtt->subscribe(
                'devices/+/discovery/response', 
                \Closure::fromCallable([$this, 'handleDeviceDiscovery']),  // callback second
                $this->qos  // qos third
            );
            
            $mqtt->subscribe(
                'devices/+/data', 
                \Closure::fromCallable([$this, 'handleDeviceData']),
                $this->qos
            );
            
            $mqtt->subscribe(
                'devices/+/status', 
                \Closure::fromCallable([$this, 'handleDeviceStatus']),
                $this->qos
            );
            
            $mqtt->subscribe(
                'devices/discover/all', 
                \Closure::fromCallable([$this, 'handleGlobalDiscovery']),
                $this->qos
            );
            
            Log::info('MQTT subscriber started successfully');
            $mqtt->loop(true);
            
        } catch (\Exception $e) {
            Log::error('MQTT subscription error: ' . $e->getMessage());
            throw $e;
        }
    }


    
    public function handleDeviceDiscovery(string $topic, string $message)
    {
        $startTime = microtime(true);
        $processingSteps = [];
        
        try {
            // Step 1: Log raw message
            $processingSteps[] = 'raw_message_received';
            Log::channel('mqtt')->info("Raw discovery message received", [
                'topic' => $topic,
                'message' => $message,
                'message_size' => strlen($message),
                'timestamp' => now()->toIso8601String()
            ]);
            
            // Step 2: Extract device ID from topic (format: devices/{device_id}/discovery/response)
            $processingSteps[] = 'topic_analysis';
            $topicParts = explode('/', $topic);
            $topicDeviceId = $topicParts[1] ?? null;
            
            Log::channel('mqtt')->info("Topic analysis", [
                'topic' => $topic,
                'parts' => $topicParts,
                'extracted_device_id' => $topicDeviceId,
                'valid_format' => (count($topicParts) >= 4 && $topicParts[0] === 'devices' && $topicParts[2] === 'discovery')
            ]);
            
            // Step 3: Parse JSON message
            $processingSteps[] = 'json_parsing';
            $data = json_decode($message, true);
            
            if (!$data) {
                Log::channel('mqtt')->error("Failed to decode JSON message", [
                    'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''), // Truncate long messages
                    'json_error' => json_last_error_msg(),
                    'json_error_code' => json_last_error()
                ]);
                return;
            }
            
            // Step 4: Validate device ID
            $processingSteps[] = 'device_id_validation';
            $deviceId = $data['device_id'] ?? $topicDeviceId;
            
            if (!$deviceId) {
                Log::channel('mqtt')->error("No device ID found in message or topic", [
                    'topic_parts' => $topicParts,
                    'data_keys' => array_keys($data)
                ]);
                return;
            }
            
            // Step 5: Log device discovery details
            $processingSteps[] = 'device_discovery_logging';
            Log::channel('mqtt')->info("Device discovery received for: {$deviceId}", [
                'device_id' => $deviceId,
                'device_name' => $data['device_name'] ?? 'Unknown',
                'device_type' => $data['device_type'] ?? 'Unknown',
                'firmware_version' => $data['firmware_version'] ?? 'Unknown',
                'mac_address' => $data['mac_address'] ?? 'Unknown',
                'ip_address' => $data['ip_address'] ?? 'Unknown',
                'sensor_count' => count($data['available_sensors'] ?? []),
                'sensor_types' => isset($data['available_sensors']) ? array_column($data['available_sensors'], 'sensor_type') : []
            ]);
            
            // Step 6: Get user context
            $processingSteps[] = 'user_context_retrieval';
            $userId = cache()->get("mqtt_user_context") ?? 
                    auth()->id() ?? 
                    \App\Models\User::first()?->id ?? 1;
            
            Log::channel('mqtt')->info("Using user ID for device assignment", [
                'user_id' => $userId,
                'from_cache' => (bool)cache()->get("mqtt_user_context"),
                'auth_id' => auth()->id(),
                'fallback_used' => !cache()->get("mqtt_user_context") && !auth()->id()
            ]);
            
            // Step 7: Create or update device with proper user assignment
            $processingSteps[] = 'device_creation';
            
            // Prepare device data with defaults and fallbacks
            $deviceData = [
                'user_id' => $userId,
                'land_id' => 1,
                'name' => $data['device_name'] ?? "Device {$deviceId}",
                'device_type' => $data['device_type'] ?? 'ESP32_DEVICE',
                'firmware_version' => $data['firmware_version'] ?? null,
                'mac_address' => $data['mac_address'] ?? null,
                'status' => 'online',
                'last_seen_at' => now(),
                'enabled' => true,
                'health_percentage' => 100,
                'application_data' => [
                    'ip_address' => $data['ip_address'] ?? null,
                    'capabilities' => $data['capabilities'] ?? [],
                    'chip_model' => $data['chip_model'] ?? 'ESP32',
                    'discovery_timestamp' => now()->toIso8601String(),
                    'discovery_source' => $topic,
                ],
                'status_details' => [
                    'wifi_connected' => true,
                    'wifi_rssi' => $data['wifi_rssi'] ?? null,
                    'uptime_seconds' => $data['uptime'] ?? null,
                    'free_heap' => $data['free_heap'] ?? null,
                    'last_discovery' => now()->toIso8601String(),
                ]
            ];
            
            // Create or update the device
            $device = Device::updateOrCreate(
                ['device_unique_id' => $deviceId],
                $deviceData
            );
            
            Log::channel('mqtt')->info("Device created/updated", [
                'device_id' => $device->id,
                'device_unique_id' => $device->device_unique_id,
                'user_id' => $userId,
                'was_recently_created' => $device->wasRecentlyCreated,
                'device_type' => $device->device_type,
                'firmware_version' => $device->firmware_version
            ]);
            
            // Step 8: Process sensors
            $processingSteps[] = 'sensor_processing';
            if (isset($data['available_sensors']) && is_array($data['available_sensors'])) {
                Log::channel('mqtt')->info("Processing sensors", [
                    'sensor_count' => count($data['available_sensors']),
                    'sensor_types' => array_column($data['available_sensors'], 'sensor_type'),
                    'device_id' => $device->id
                ]);
                
                // Process sensors with detailed logging
                $sensorResults = $this->syncSensorsFromArduino($device, $data['available_sensors']);
                
                Log::channel('mqtt')->info("Sensor processing results", [
                    'created' => $sensorResults['created'],
                    'updated' => $sensorResults['updated'],
                    'failed' => $sensorResults['failed'],
                    'total_processed' => $sensorResults['total']
                ]);
            } else {
                Log::channel('mqtt')->warning("No available_sensors found in discovery data or invalid format", [
                    'available_sensors' => $data['available_sensors'] ?? null,
                    'data_keys' => array_keys($data)
                ]);
            }
            
            // Step 9: Clean up and finalize
            $processingSteps[] = 'finalization';
            
            // Clear the user context cache after successful processing
            cache()->forget("mqtt_user_context");
            
            // Calculate processing time
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::channel('mqtt')->info("Device {$deviceId} registered successfully", [
                'sensor_count' => count($data['available_sensors'] ?? []),
                'device_db_id' => $device->id,
                'processing_time_ms' => $processingTime,
                'processing_steps' => $processingSteps
            ]);
            
        } catch (\Exception $e) {
            // Log detailed error information
            Log::channel('mqtt')->error("Error processing device discovery: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'topic' => $topic,
                'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''), // Truncate long messages
                'processing_steps_completed' => $processingSteps,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
            // Try to extract device ID for error reporting
            $deviceId = null;
            try {
                $deviceId = $data['device_id'] ?? $topicParts[1] ?? 'unknown';
            } catch (\Throwable $th) {
                // Ignore any errors in error handling
            }
            
            // Log a summary error message
            Log::channel('mqtt')->error("Device discovery failed for " . ($deviceId ?? 'unknown device'), [
                'error' => $e->getMessage(),
                'step' => end($processingSteps) ?? 'unknown'
            ]);
        }
    }




    
    public function handleDeviceData(string $topic, string $message)
    {
        $startTime = microtime(true);
        $processingSteps = [];
        
        try {
            // Step 1: Log raw message
            $processingSteps[] = 'raw_message_received';
            Log::channel('mqtt')->info("Raw sensor data message received", [
                'topic' => $topic,
                'message_size' => strlen($message),
                'timestamp' => now()->toIso8601String()
            ]);
            
            // Step 2: Parse JSON message
            $processingSteps[] = 'json_parsing';
            $data = json_decode($message, true);
            
            if (!$data) {
                Log::channel('mqtt')->error("Failed to decode sensor data JSON message", [
                    'topic' => $topic,
                    'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''),
                    'json_error' => json_last_error_msg(),
                    'json_error_code' => json_last_error()
                ]);
                return;
            }
            
            // Step 3: Validate device ID
            $processingSteps[] = 'device_id_validation';
            $deviceId = $data['device_id'] ?? null;
            
            if (!$deviceId) {
                Log::channel('mqtt')->error("No device ID found in sensor data message", [
                    'topic' => $topic,
                    'data_keys' => array_keys($data)
                ]);
                return;
            }
            
            // Step 4: Find the device
            $processingSteps[] = 'device_lookup';
            $device = Device::where('device_unique_id', $deviceId)->first();
            
            if (!$device) {
                Log::channel('mqtt')->warning("Device not found for sensor data: {$deviceId}", [
                    'topic' => $topic,
                    'device_id' => $deviceId,
                    'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ]);
                return;
            }
            
            // Step 5: Calculate device health
            $processingSteps[] = 'health_calculation';
            $health = $this->calculateHealthFromSensorData($data);
            
            // Step 6: Update device status
            $processingSteps[] = 'device_update';
            $device->update([
                'status' => 'online',
                'last_seen_at' => now(),
                'health_percentage' => $health,
                'status_details' => array_merge($device->status_details ?? [], [
                    'last_data_update' => now()->toIso8601String(),
                ])
            ]);
            
            // Step 7: Update sensor readings
            $processingSteps[] = 'sensor_readings_update';
            $sensorCount = 0;
            
            if (isset($data['sensors']) && is_array($data['sensors'])) {
                $sensorCount = count($data['sensors']);
                
                Log::channel('mqtt')->info("Processing sensor readings", [
                    'device_id' => $deviceId,
                    'sensor_count' => $sensorCount,
                    'sensor_types' => array_keys($data['sensors'])
                ]);
                
                $this->updateSensorReadings($device, $data['sensors'], $data['timestamp'] ?? time());
            } else {
                Log::channel('mqtt')->warning("No sensor data found in message", [
                    'device_id' => $deviceId,
                    'data_keys' => array_keys($data)
                ]);
            }
            
            // Step 8: Finalize
            $processingSteps[] = 'finalization';
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::channel('mqtt')->info("Updated sensor data for device: {$deviceId}", [
                'device_id' => $device->id,
                'sensor_count' => $sensorCount,
                'health_percentage' => $health,
                'processing_time_ms' => $processingTime,
                'processing_steps' => $processingSteps
            ]);
            
        } catch (\Exception $e) {
            // Log detailed error information
            Log::channel('mqtt')->error("Error processing device data: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'topic' => $topic,
                'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''),
                'processing_steps_completed' => $processingSteps,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
    }
    
    public function handleDeviceStatus(string $topic, string $message)
    {
        $startTime = microtime(true);
        
        try {
            // Parse JSON message
            $data = json_decode($message, true);
            
            if (!$data) {
                Log::channel('mqtt')->error("Failed to decode status JSON message", [
                    'topic' => $topic,
                    'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''),
                    'json_error' => json_last_error_msg()
                ]);
                return;
            }
            
            $deviceId = $data['device_id'] ?? null;
            
            if (!$deviceId) {
                Log::channel('mqtt')->error("No device ID found in status message", [
                    'topic' => $topic,
                    'data_keys' => array_keys($data)
                ]);
                return;
            }
            
            // Find the device
            $device = Device::where('device_unique_id', $deviceId)->first();
            
            if (!$device) {
                Log::channel('mqtt')->warning("Device not found for status update: {$deviceId}", [
                    'topic' => $topic,
                    'status' => $data['status'] ?? 'unknown'
                ]);
                return;
            }
            
            // Update device status
            $device->update([
                'status' => $data['status'] === 'online' ? 'online' : 'offline',
                'last_seen_at' => now(),
                'status_details' => array_merge($device->status_details ?? [], [
                    'uptime_seconds' => $data['uptime'] ?? null,
                    'free_heap' => $data['free_heap'] ?? null,
                    'wifi_rssi' => $data['wifi_rssi'] ?? null,
                    'last_status_update' => now()->toIso8601String(),
                ])
            ]);
            
            Log::channel('mqtt')->info("Device status updated for {$deviceId}", [
                'device_id' => $device->id,
                'status' => $data['status'] ?? 'unknown',
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('mqtt')->error("Error processing device status: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'topic' => $topic,
                'message' => substr($message, 0, 500) . (strlen($message) > 500 ? '...' : ''),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
    }
    
    public function handleGlobalDiscovery(string $topic, string $message)
    {
        $startTime = microtime(true);
        
        try {
            // Parse the message if it's JSON
            $data = json_decode($message, true);
            
            // Log the global discovery request
            Log::channel('mqtt')->info("Global discovery request received", [
                'topic' => $topic,
                'message' => $message,
                'is_json' => (bool)$data,
                'timestamp' => now()->toIso8601String()
            ]);
            
            // We don't need to do anything here as the devices will respond directly
            // to the global discovery request, but we can log some additional information
            
            if ($data) {
                Log::channel('mqtt')->info("Global discovery details", [
                    'initiated_by' => $data['initiated_by'] ?? 'unknown',
                    'timestamp' => $data['timestamp'] ?? time(),
                    'request_id' => $data['request_id'] ?? 'unknown',
                    'action' => $data['action'] ?? 'discover'
                ]);
            }
            
            // Log processing time
            Log::channel('mqtt')->info("Global discovery request processed", [
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('mqtt')->error("Error processing global discovery request: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'topic' => $topic,
                'message' => $message,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
    }
    
    private function syncSensorsFromArduino(Device $device, array $sensorsData)
    {
        $startTime = microtime(true);
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'total' => count($sensorsData),
            'sensor_types' => []
        ];
        
        Log::channel('mqtt')->info("Starting sensor sync for device {$device->device_unique_id}", [
            'device_id' => $device->id,
            'sensor_count' => count($sensorsData),
            'device_type' => $device->device_type
        ]);
        
        foreach ($sensorsData as $index => $sensorData) {
            $sensorStartTime = microtime(true);
            $sensorLog = [
                'index' => $index,
                'sensor_type' => $sensorData['sensor_type'] ?? 'unknown',
                'sensor_name' => $sensorData['sensor_name'] ?? 'Unnamed Sensor',
                'status' => 'processing'
            ];
            
            Log::channel('mqtt')->info("Processing sensor {$index}", $sensorLog);
            
            // Validate sensor data
            if (!isset($sensorData['sensor_type'])) {
                $sensorLog['status'] = 'failed';
                $sensorLog['reason'] = 'missing_sensor_type';
                
                Log::channel('mqtt')->warning("Sensor missing required sensor_type field", [
                    'sensor_data' => $sensorData,
                    'index' => $index
                ]);
                
                $results['failed']++;
                continue;
            }
            
            // Map Arduino sensor types to your sensor types
            $sensorType = $this->mapArduinoSensorType($sensorData['sensor_type']);
            $results['sensor_types'][] = $sensorType;
            $sensorLog['mapped_type'] = $sensorType;
            
            try {
                // Extract thresholds safely
                $thresholds = null;
                $minThreshold = null;
                $maxThreshold = null;
                
                if (isset($sensorData['thresholds']) && is_array($sensorData['thresholds'])) {
                    $thresholds = $sensorData['thresholds'];
                    $minThreshold = $thresholds['min'] ?? null;
                    $maxThreshold = $thresholds['max'] ?? null;
                    
                    $sensorLog['has_thresholds'] = true;
                    $sensorLog['min_threshold'] = $minThreshold;
                    $sensorLog['max_threshold'] = $maxThreshold;
                } else {
                    $sensorLog['has_thresholds'] = false;
                }
                
                // CRITICAL FIX: Separate search criteria from update attributes
                $searchCriteria = [
                    'device_id' => $device->id,
                    'sensor_type' => $sensorType
                ];
                
                // Prepare sensor data WITHOUT search criteria fields
                $sensorAttributes = [
                    'sensor_name' => $sensorData['sensor_name'] ?? "Sensor {$sensorType}",
                    'description' => $sensorData['description'] ?? "Auto-discovered {$sensorType} sensor",
                    'location' => $sensorData['location'] ?? 'main_board',
                    'unit' => $sensorData['unit'] ?? $this->getDefaultUnit($sensorType),
                    'accuracy' => $sensorData['accuracy'] ?? 1.0,
                    'thresholds' => $thresholds,
                    'value' => null, // Initialize with null
                    'reading_timestamp' => now(),
                    'enabled' => $sensorData['enabled'] ?? true,
                    'calibration_offset' => 0.0,
                    'last_calibration' => now(),
                    'alert_enabled' => true,
                    'alert_threshold_min' => $minThreshold,
                    'alert_threshold_max' => $maxThreshold,
                ];
                
                // Log what we're about to create/update
                Log::channel('mqtt')->debug("Sensor creation attempt", [
                    'device_id' => $device->id,
                    'search_criteria' => $searchCriteria,
                    'attributes' => $sensorAttributes,
                    'sensor_type' => $sensorType
                ]);
                
                // Check if sensor already exists
                $existingSensor = Sensor::where('device_id', $device->id)
                                    ->where('sensor_type', $sensorType)
                                    ->first();
                
                if ($existingSensor) {
                    Log::channel('mqtt')->info("Updating existing sensor", [
                        'sensor_id' => $existingSensor->id,
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType
                    ]);
                    
                    $existingSensor->update($sensorAttributes);
                    $sensor = $existingSensor;
                    $wasRecentlyCreated = false;
                } else {
                    Log::channel('mqtt')->info("Creating new sensor", [
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType
                    ]);
                    
                    // Merge search criteria with attributes for creation
                    $createAttributes = array_merge($searchCriteria, $sensorAttributes);
                    $sensor = Sensor::create($createAttributes);
                    $wasRecentlyCreated = true;
                }
                
                // Verify the sensor was actually saved
                if (!$sensor || !$sensor->exists) {
                    throw new \Exception("Sensor was not saved to database");
                }
                
                // Refresh the sensor to get latest data
                $sensor->refresh();
                
                // Update results
                if ($wasRecentlyCreated) {
                    $results['created']++;
                    $sensorLog['status'] = 'created';
                    Log::channel('mqtt')->info("NEW sensor created successfully", [
                        'sensor_id' => $sensor->id,
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType,
                        'sensor_name' => $sensor->sensor_name,
                        'created_at' => $sensor->created_at,
                        'updated_at' => $sensor->updated_at
                    ]);
                } else {
                    $results['updated']++;
                    $sensorLog['status'] = 'updated';
                    Log::channel('mqtt')->info("Existing sensor updated successfully", [
                        'sensor_id' => $sensor->id,
                        'device_id' => $device->id,
                        'sensor_type' => $sensorType,
                        'sensor_name' => $sensor->sensor_name,
                        'updated_at' => $sensor->updated_at
                    ]);
                }
                
                $sensorLog['sensor_id'] = $sensor->id;
                $sensorLog['processing_time_ms'] = round((microtime(true) - $sensorStartTime) * 1000, 2);
                
                Log::channel('mqtt')->info("Sensor {$sensorLog['status']}", $sensorLog);
                
            } catch (\Exception $e) {
                $results['failed']++;
                $sensorLog['status'] = 'error';
                $sensorLog['error'] = $e->getMessage();
                $sensorLog['processing_time_ms'] = round((microtime(true) - $sensorStartTime) * 1000, 2);
                
                Log::channel('mqtt')->error("Failed to create/update sensor", [
                    'device_id' => $device->id,
                    'sensor_type' => $sensorData['sensor_type'] ?? 'unknown',
                    'mapped_type' => $sensorType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'sensor_data' => $sensorData,
                    'search_criteria' => $searchCriteria ?? null,
                    'attributes_attempted' => $sensorAttributes ?? null
                ]);
                
                // Additional debugging
                Log::channel('mqtt')->debug("Database debugging info", [
                    'device_exists' => $device->exists,
                    'device_id' => $device->id,
                    'fillable_fields' => (new Sensor())->getFillable(),
                    'sensor_table_exists' => \Schema::hasTable('sensors'),
                    'device_sensors_count' => $device->sensors()->count(),
                    'total_sensors_count' => Sensor::count()
                ]);
            }
        }
        
        // Calculate processing time
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Final verification - count actual sensors in database
        $actualSensorCount = $device->sensors()->count();
        
        Log::channel('mqtt')->info("Completed sensor sync for device", [
            'device_id' => $device->id,
            'device_unique_id' => $device->device_unique_id,
            'processed_sensors' => count($sensorsData),
            'created' => $results['created'],
            'updated' => $results['updated'],
            'failed' => $results['failed'],
            'actual_db_sensor_count' => $actualSensorCount,
            'processing_time_ms' => $totalTime,
            'sensor_types' => array_unique($results['sensor_types'])
        ]);
        
        return $results;
    }


    private function getDefaultUnit(string $sensorType): ?string
    {
        return match($sensorType) {
            'temperature' => 'celsius',
            'humidity' => 'percent',
            'light' => 'percent',
            'wifi_signal' => 'dBm',
            'battery' => 'percent',
            default => null
        };
    }
    private function updateSensorReadings(Device $device, array $sensorsData, int $timestamp)
    {
        $startTime = microtime(true);
        $results = [
            'updated' => 0,
            'skipped' => 0,
            'warnings' => 0,
            'total' => count($sensorsData)
        ];
        
        Log::channel('mqtt')->info("Updating sensor readings for device {$device->device_unique_id}", [
            'device_id' => $device->id,
            'sensor_count' => count($sensorsData),
            'timestamp' => Carbon::createFromTimestamp($timestamp)->toIso8601String()
        ]);
        
        foreach ($sensorsData as $sensorType => $value) {
            $sensorStartTime = microtime(true);
            
            try {
                // Map Arduino sensor types
                $mappedSensorType = $this->mapArduinoSensorType($sensorType);
                
                // Find the sensor
                $sensor = $device->sensors()->where('sensor_type', $mappedSensorType)->first();
                
                if (!$sensor) {
                    Log::channel('mqtt')->warning("Sensor not found for reading update", [
                        'device_id' => $device->id,
                        'device_unique_id' => $device->device_unique_id,
                        'sensor_type' => $sensorType,
                        'mapped_type' => $mappedSensorType,
                        'value' => $value
                    ]);
                    
                    $results['skipped']++;
                    continue;
                }
                
                // Apply calibration offset if exists
                $calibratedValue = $value + ($sensor->calibration_offset ?? 0);
                $previousValue = $sensor->value;
                
                // Calculate change percentage if previous value exists
                $changePercentage = null;
                if ($previousValue !== null && $previousValue != 0) {
                    $changePercentage = round((($calibratedValue - $previousValue) / abs($previousValue)) * 100, 2);
                }
                
                // Update sensor with new reading
                $sensor->update([
                    'value' => $calibratedValue,
                    'reading_timestamp' => Carbon::createFromTimestamp($timestamp)
                ]);
                
                // Determine sensor status based on thresholds
                $status = 'normal';
                $withinThresholds = $sensor->isWithinThresholds();
                
                if (!$withinThresholds) {
                    $status = 'warning';
                    $results['warnings']++;
                    
                    Log::channel('mqtt')->warning("Sensor value outside thresholds", [
                        'device_id' => $device->device_unique_id,
                        'sensor_id' => $sensor->id,
                        'sensor_name' => $sensor->sensor_name,
                        'sensor_type' => $sensor->sensor_type,
                        'value' => $calibratedValue,
                        'previous_value' => $previousValue,
                        'change_percentage' => $changePercentage,
                        'min_threshold' => $sensor->alert_threshold_min,
                        'max_threshold' => $sensor->alert_threshold_max,
                        'unit' => $sensor->unit
                    ]);
                }
                
                $results['updated']++;
                
                Log::channel('mqtt')->info("Sensor reading updated", [
                    'sensor_id' => $sensor->id,
                    'sensor_name' => $sensor->sensor_name,
                    'sensor_type' => $sensor->sensor_type,
                    'value' => $calibratedValue,
                    'previous_value' => $previousValue,
                    'change_percentage' => $changePercentage,
                    'status' => $status,
                    'processing_time_ms' => round((microtime(true) - $sensorStartTime) * 1000, 2)
                ]);
                
            } catch (\Exception $e) {
                Log::channel('mqtt')->error("Error updating sensor reading", [
                    'device_id' => $device->id,
                    'sensor_type' => $sensorType,
                    'value' => $value,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $results['skipped']++;
            }
        }
        
        // Calculate processing time
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        
        Log::channel('mqtt')->info("Completed sensor readings update for device", [
            'device_id' => $device->id,
            'device_unique_id' => $device->device_unique_id,
            'updated' => $results['updated'],
            'skipped' => $results['skipped'],
            'warnings' => $results['warnings'],
            'total' => $results['total'],
            'processing_time_ms' => $totalTime
        ]);
        
        return $results;
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
        $startTime = microtime(true);
        
        try {
            // Validate inputs
            if (empty($deviceId)) {
                throw new \InvalidArgumentException("Device ID cannot be empty");
            }
            
            if (empty($command)) {
                throw new \InvalidArgumentException("Command cannot be empty");
            }
            
            // Connect to MQTT broker
            $mqtt = MQTT::connection();
            
            // Prepare topic and message
            $topic = "devices/{$deviceId}/control/{$command}";
            $message = json_encode($payload);
            
            if ($message === false) {
                throw new \RuntimeException("Failed to encode payload: " . json_last_error_msg());
            }
            
            // Log the command before sending
            Log::channel('mqtt')->info("Sending command to device", [
                'device_id' => $deviceId,
                'command' => $command,
                'topic' => $topic,
                'payload' => $payload,
                'payload_size' => strlen($message),
                'timestamp' => now()->toIso8601String()
            ]);
            
            // Publish the command
            $mqtt->publish($topic, $message, $this->qos);
            
            // Log success
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('mqtt')->info("Command sent successfully", [
                'device_id' => $deviceId,
                'command' => $command,
                'topic' => $topic,
                'processing_time_ms' => $processingTime
            ]);
            
            return true;
        } catch (\Exception $e) {
            // Log detailed error information
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('mqtt')->error("Error sending command to device: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'device_id' => $deviceId,
                'command' => $command,
                'payload' => $payload,
                'processing_time_ms' => $processingTime,
                'mqtt_config' => [
                    'host' => env('MQTT_HOST'),
                    'port' => env('MQTT_PORT'),
                    'client_id' => env('MQTT_CLIENT_ID'),
                    'qos' => $this->qos
                ]
            ]);
            
            throw $e;
        }
    }
    
    public function discoverAllDevices()
    {
        $startTime = microtime(true);
        
        try {
            // Store current user context before sending discovery
            $userId = auth()->id() ?? \App\Models\User::first()?->id ?? 1;
            cache()->put("mqtt_user_context", $userId, now()->addMinutes(5));
            
            Log::channel('mqtt')->info("=== STARTING GLOBAL DEVICE DISCOVERY ===", [
                'user_id' => $userId,
                'initiated_by' => auth()->user()?->email ?? 'system',
                'timestamp' => now()->toISOString()
            ]);
            
            // Use a fresh connection for discovery
            $mqtt = MQTT::connection();
            
            // Connect with retry logic (REMOVED PING CALLS)
            $connectionTimeout = 15;
            $connectionStart = time();
            $connected = false;
            
            while (!$connected && (time() - $connectionStart) < $connectionTimeout) {
                try {
                    $mqtt->connect();
                    $connected = $mqtt->isConnected();
                    
                    if (!$connected) {
                        Log::channel('mqtt')->warning("Connection attempt failed, retrying...");
                        sleep(1);
                    }
                } catch (\Exception $e) {
                    Log::channel('mqtt')->warning("Connection error: " . $e->getMessage());
                    sleep(2);
                }
            }
            
            if (!$connected) {
                throw new \Exception("Failed to establish MQTT connection within {$connectionTimeout} seconds");
            }
            
            Log::channel('mqtt')->info("✅ MQTT connection established");
            
            // Subscribe to discovery responses BEFORE sending the request
            $responseReceived = false;
            $discoveryResponses = [];
            
            $mqtt->subscribe('devices/+/discovery/response', function ($topic, $message) use (&$responseReceived, &$discoveryResponses) {
                Log::channel('mqtt')->info("📨 Received discovery response", [
                    'topic' => $topic,
                    'message_preview' => substr($message, 0, 200) . '...'
                ]);
                
                $responseReceived = true;
                $discoveryResponses[] = [
                    'topic' => $topic,
                    'message' => $message,
                    'received_at' => now()->toISOString()
                ];
                
                // Process the response immediately
                $this->handleDeviceDiscovery($topic, $message);
            }, 1);
            
            Log::channel('mqtt')->info("📡 Subscribed to discovery responses");
            
            // Prepare and send discovery request
            $discoveryPayload = json_encode([
                'action' => 'discover',
                'timestamp' => time(),
                'initiated_by' => auth()->user()?->email ?? 'system',
                'request_id' => uniqid('disc_', true)
            ]);
            
            Log::channel('mqtt')->info("📤 Publishing global discovery request", [
                'topic' => 'devices/discover/all',
                'payload' => $discoveryPayload
            ]);
            
            // CRITICAL FIX: Check the return value properly
            $published = $mqtt->publish('devices/discover/all', $discoveryPayload, 0);
            
            // The publish method might return void, so we check connection instead
            if (!$mqtt->isConnected()) {
                throw new \Exception("MQTT connection lost during publish");
            }
            
            Log::channel('mqtt')->info("✅ Discovery request published successfully");
            
            // Wait for responses with timeout
            $waitTimeout = 10; // Wait 10 seconds for responses
            $waitStart = time();
            $loopCount = 0;
            
            Log::channel('mqtt')->info("⏳ Waiting for discovery responses...");
            
            while ((time() - $waitStart) < $waitTimeout) {
                // Process incoming messages
                $mqtt->loop(false, true); // Non-blocking
                
                $loopCount++;
                
                // Log progress every 2 seconds
                if ($loopCount % 20 === 0) {
                    $elapsed = time() - $waitStart;
                    $remaining = $waitTimeout - $elapsed;
                    Log::channel('mqtt')->info("⏱️ Waiting for responses... {$elapsed}s elapsed, {$remaining}s remaining");
                }
                
                usleep(100000); // 100ms delay
            }
            
            // Disconnect
            $mqtt->disconnect();
            
            $totalResponses = count($discoveryResponses);
            
            Log::channel('mqtt')->info("=== DISCOVERY COMPLETED ===", [
                'responses_received' => $totalResponses,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'responses' => $discoveryResponses
            ]);
            
            if ($totalResponses === 0) {
                Log::channel('mqtt')->warning("⚠️ No discovery responses received. Check if Arduino devices are online and subscribed to 'devices/discover/all'");
            }
            
            return [
                'success' => true,
                'responses_received' => $totalResponses,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
            
        } catch (\Exception $e) {
            Log::channel('mqtt')->error("=== DISCOVERY FAILED ===", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
            throw $e;
        }
    }





}
