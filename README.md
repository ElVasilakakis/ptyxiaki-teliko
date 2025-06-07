# IoT Farm Management System

A Laravel-based system for managing IoT devices and sensors in agricultural settings, using MQTT for real-time communication.

## Device Discovery and Sensor Management

This system allows you to discover and manage IoT devices and their sensors through MQTT communication. The system uses the MQTT protocol to communicate with ESP32 and other IoT devices.

### MQTT Configuration

The system is configured to use the EMQX MQTT broker by default. You can change the broker settings in the `.env` file:

```
MQTT_HOST=broker.emqx.io
MQTT_PORT=1883
MQTT_CLIENT_ID=laravel_client
MQTT_USERNAME=mqttuser
MQTT_PASSWORD=12345678
```

### Device Discovery

There are several ways to discover devices:

1. **Global Discovery**: Click the "Discover All Devices" button in the Devices section of the admin panel. This sends a broadcast message to all devices.

2. **Individual Device Discovery**: For a specific device, click the "Discover Device" button on the device's row in the table.

3. **Command Line Discovery**: Run the test discovery command to manually discover devices:

```bash
php artisan mqtt:test-discovery
```

Or specify a device ID:

```bash
php artisan mqtt:test-discovery ESP32-DEV-001
```

### MQTT Subscriber Service

To receive device updates and discovery responses, you need to run the MQTT subscriber service:

```bash
php artisan mqtt:subscribe
```

This command will start a long-running process that listens for MQTT messages from devices. It's recommended to run this as a background service using Supervisor or a similar tool in production.

### Troubleshooting Device Discovery

If device discovery is not working:

1. **Check MQTT Connection**: Use the "MQTT Status" button in the admin panel to verify the connection to the MQTT broker.

2. **Check Logs**: Review the MQTT logs at `storage/logs/mqtt.log` for detailed information about discovery attempts and responses.

3. **Test Discovery**: Use the `mqtt:test-discovery` command to manually test device discovery and see detailed output.

4. **Verify Device Configuration**: Ensure your IoT devices are properly configured to respond to discovery requests on the correct topics.

5. **Topic Structure**: Devices should listen for discovery requests on `devices/{device_id}/discover` and respond on `devices/{device_id}/discovery/response`.

### Device Response Format

Devices should respond to discovery requests with a JSON payload containing device information and available sensors:

```json
{
  "device_id": "ESP32-DEV-001",
  "device_name": "Environmental Sensor Monitor",
  "device_type": "SENSOR_MONITOR",
  "firmware_version": "1.0.1",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "ip_address": "192.168.1.100",
  "uptime": 3600,
  "free_heap": 120000,
  "wifi_rssi": -65,
  "available_sensors": [
    {
      "sensor_type": "temperature",
      "sensor_name": "DHT22 Temperature",
      "unit": "celsius",
      "description": "Digital temperature sensor",
      "location": "main_board",
      "accuracy": 0.5,
      "thresholds": {
        "min": -10,
        "max": 50
      }
    },
    {
      "sensor_type": "humidity",
      "sensor_name": "DHT22 Humidity",
      "unit": "percent",
      "description": "Digital humidity sensor",
      "location": "main_board",
      "accuracy": 2.0,
      "thresholds": {
        "min": 0,
        "max": 100
      }
    }
  ],
  "capabilities": [
    "remote_control",
    "real_time_monitoring",
    "configuration_update"
  ]
}
```

## License

This project is licensed under the MIT License.
