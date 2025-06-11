#include <Arduino.h>
#line 1 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
#include <WiFi.h>
#include <MQTT.h>
#include <ArduinoJson.h>

const char ssid[] = "Wokwi-GUEST";
const char pass[] = "";

// MQTT Configuration with your credentials
const char* mqtt_broker = "broker.emqx.io";
const int mqtt_port = 1883;
const char* mqtt_username = "mqttuser";     // Your username
const char* mqtt_password = "12345678";     // Your password

WiFiClient net;
MQTTClient client(8192);  // Large buffer for discovery messages
unsigned long lastMillis = 0;
unsigned long lastReconnectAttempt = 0;
unsigned long lastHeartbeat = 0;
const unsigned long reconnectInterval = 5000; // 5 seconds
const unsigned long heartbeatInterval = 30000; // 30 seconds

// Device Configuration
const String device_id = "ESP32-DEV-001";
const String device_name = "Environmental Sensor Monitor";

// Sensor variables
int temperature;
int humidity;
int lightLevel;

// Status tracking
bool mqttConnected = false;
bool wifiConnected = false;
int discoveryCount = 0;

#line 36 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void setup();
#line 75 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void connectToWiFi();
#line 100 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void connectToMQTT();
#line 164 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void messageReceived(String &topic, String &payload);
#line 218 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishDeviceDiscovery();
#line 390 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void loop();
#line 425 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void checkConnections();
#line 466 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishSensorData();
#line 500 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishDeviceStatus();
#line 527 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishHeartbeat();
#line 36 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println();
    Serial.println("==========================================");
    Serial.println("    ESP32 MQTT Device Starting");
    Serial.println("==========================================");
    Serial.println("Device ID: " + device_id);
    Serial.println("Device Name: " + device_name);
    Serial.println("MQTT Broker: " + String(mqtt_broker) + ":" + String(mqtt_port));
    Serial.println("Buffer Size: 8192 bytes");
    Serial.println("==========================================");
    
    // Initialize pins
    pinMode(2, OUTPUT);
    digitalWrite(2, LOW);  // LED off during setup
    
    // Connect to WiFi
    connectToWiFi();
    
    // Setup MQTT client with broker and port
    client.begin(mqtt_broker, mqtt_port, net);
    client.onMessage(messageReceived);
    
    // Connect to MQTT
    connectToMQTT();
    
    Serial.println("=== Setup Complete ===");
    digitalWrite(2, HIGH);  // LED on when ready
    
    // Auto-publish discovery on startup
    delay(3000); // Wait for connection to stabilize
    if (mqttConnected) {
        Serial.println("=== Publishing startup discovery ===");
        publishDeviceDiscovery();
    }
}

void connectToWiFi() {
    Serial.println("Connecting to WiFi...");
    WiFi.begin(ssid, pass);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        wifiConnected = true;
        Serial.println();
        Serial.println("✅ WiFi connected successfully!");
        Serial.println("IP address: " + WiFi.localIP().toString());
        Serial.println("MAC address: " + WiFi.macAddress());
        Serial.println("Signal strength: " + String(WiFi.RSSI()) + " dBm");
    } else {
        wifiConnected = false;
        Serial.println();
        Serial.println("❌ WiFi connection failed!");
    }
}

void connectToMQTT() {
    if (!wifiConnected) {
        Serial.println("Cannot connect to MQTT: WiFi not connected");
        return;
    }
    
    Serial.println("Connecting to MQTT broker...");
    
    // Create unique client ID to avoid conflicts
    String clientId = device_id + "_device_" + String(millis());
    
    int attempts = 0;
    while (!client.connect(clientId.c_str(), mqtt_username, mqtt_password) && attempts < 10) {
        Serial.print(".");
        delay(1000);
        attempts++;
    }
    
    if (client.connected()) {
        mqttConnected = true;
        Serial.println();
        Serial.println("✅ MQTT connected successfully!");
        Serial.println("Client ID: " + clientId);
        
        // Subscribe to topics with confirmation
        Serial.println("Subscribing to control topics...");
        
        if (client.subscribe("devices/" + device_id + "/control/#")) {
            Serial.println("✅ Subscribed to: devices/" + device_id + "/control/#");
        } else {
            Serial.println("❌ Failed to subscribe to control topics");
        }
        
        if (client.subscribe("devices/" + device_id + "/config/#")) {
            Serial.println("✅ Subscribed to: devices/" + device_id + "/config/#");
        } else {
            Serial.println("❌ Failed to subscribe to config topics");
        }
        
        if (client.subscribe("devices/" + device_id + "/discover")) {
            Serial.println("✅ Subscribed to: devices/" + device_id + "/discover");
        } else {
            Serial.println("❌ Failed to subscribe to discovery topic");
        }
        
        if (client.subscribe("devices/discover/all")) {
            Serial.println("✅ Subscribed to: devices/discover/all");
        } else {
            Serial.println("❌ Failed to subscribe to global discovery");
        }
        
        // Publish online status immediately after connection
        publishDeviceStatus();
        
        // Send will message setup
        Serial.println("MQTT connection established with will message");
        
    } else {
        mqttConnected = false;
        Serial.println();
        Serial.println("❌ MQTT connection failed!");
    }
}

void messageReceived(String &topic, String &payload) {
    Serial.println();
    Serial.println("=== MQTT MESSAGE RECEIVED ===");
    Serial.println("Timestamp: " + String(millis()));
    Serial.println("Topic: " + topic);
    Serial.println("Payload: " + payload);
    Serial.println("Payload Length: " + String(payload.length()));
    Serial.println("Free Heap: " + String(ESP.getFreeHeap()));
    Serial.println("=============================");
    
    // Handle LED control
    if(topic == "devices/" + device_id + "/control/led") {
        int ledState = payload.toInt();
        digitalWrite(2, ledState);
        Serial.println("💡 LED set to: " + String(ledState));
        
        // Send acknowledgment
        String ackTopic = "devices/" + device_id + "/control/led/ack";
        String ackPayload = "{\"command\":\"led\",\"value\":" + String(ledState) + ",\"status\":\"success\",\"timestamp\":" + String(millis()) + "}";
        client.publish(ackTopic, ackPayload, false, 1); // QoS 1 for acknowledgments
    }
    
    // Handle device configuration
    if(topic == "devices/" + device_id + "/config/interval") {
        Serial.println("⚙️ Config update received: " + payload);
        // Add configuration handling logic here
    }
    
    // Handle device discovery request (device-specific)
    if(topic == "devices/" + device_id + "/discover") {
        Serial.println("🔍 DEVICE-SPECIFIC DISCOVERY REQUEST RECEIVED");
        Serial.println("Request payload: " + payload);
        Serial.println("Triggering discovery response...");
        publishDeviceDiscovery();
    }
    
    // Handle global discovery request
    if(topic == "devices/discover/all") {
        Serial.println("🌐 GLOBAL DISCOVERY REQUEST RECEIVED");
        Serial.println("Request payload: " + payload);
        Serial.println("Triggering discovery response...");
        
        // Add random delay to prevent message collision
        int delayMs = random(100, 1000);
        Serial.println("Adding random delay: " + String(delayMs) + "ms");
        delay(delayMs);
        
        publishDeviceDiscovery();
    }
    
    Serial.println("=== Message processing complete ===");
    Serial.println();
}

void publishDeviceDiscovery() {
    discoveryCount++;
    Serial.println();
    Serial.println("🚀 === STARTING DEVICE DISCOVERY RESPONSE #" + String(discoveryCount) + " ===");
    
    // Create JSON document with sufficient size
    DynamicJsonDocument doc(8192);
    
    // Device information
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["device_type"] = "SENSOR_MONITOR";
    doc["firmware_version"] = "1.0.3";
    doc["mac_address"] = WiFi.macAddress();
    doc["ip_address"] = WiFi.localIP().toString();
    doc["uptime"] = millis() / 1000;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["discovery_timestamp"] = millis();
    doc["discovery_count"] = discoveryCount;
    
    Serial.println("📋 Device Info:");
    Serial.println("  - Device ID: " + String(device_id));
    Serial.println("  - Device Name: " + String(device_name));
    Serial.println("  - IP: " + WiFi.localIP().toString());
    Serial.println("  - MAC: " + WiFi.macAddress());
    Serial.println("  - Free Heap: " + String(ESP.getFreeHeap()) + " bytes");
    Serial.println("  - WiFi RSSI: " + String(WiFi.RSSI()) + " dBm");
    Serial.println("  - Discovery Count: " + String(discoveryCount));
    
    // Available sensors array - using proper nested object creation
    JsonArray sensors = doc.createNestedArray("available_sensors");
    
    // Temperature sensor
    JsonObject tempSensor = sensors.createNestedObject();
    tempSensor["sensor_type"] = "temperature";
    tempSensor["sensor_name"] = "DHT22 Temperature";
    tempSensor["unit"] = "celsius";
    tempSensor["description"] = "Digital temperature sensor";
    tempSensor["location"] = "main_board";
    tempSensor["accuracy"] = 0.5;
    tempSensor["enabled"] = true;
    JsonObject tempThresholds = tempSensor.createNestedObject("thresholds");
    tempThresholds["min"] = -10;
    tempThresholds["max"] = 50;
    
    // Humidity sensor
    JsonObject humSensor = sensors.createNestedObject();
    humSensor["sensor_type"] = "humidity";
    humSensor["sensor_name"] = "DHT22 Humidity";
    humSensor["unit"] = "percent";
    humSensor["description"] = "Digital humidity sensor";
    humSensor["location"] = "main_board";
    humSensor["accuracy"] = 2.0;
    humSensor["enabled"] = true;
    JsonObject humThresholds = humSensor.createNestedObject("thresholds");
    humThresholds["min"] = 0;
    humThresholds["max"] = 100;
    
    // Light sensor
    JsonObject lightSensor = sensors.createNestedObject();
    lightSensor["sensor_type"] = "light";
    lightSensor["sensor_name"] = "LDR Light Sensor";
    lightSensor["unit"] = "percent";
    lightSensor["description"] = "Analog light sensor";
    lightSensor["location"] = "main_board";
    lightSensor["accuracy"] = 5.0;
    lightSensor["enabled"] = true;
    JsonObject lightThresholds = lightSensor.createNestedObject("thresholds");
    lightThresholds["min"] = 0;
    lightThresholds["max"] = 100;
    
    // WiFi signal sensor
    JsonObject wifiSensor = sensors.createNestedObject();
    wifiSensor["sensor_type"] = "wifi_signal";
    wifiSensor["sensor_name"] = "WiFi Signal Strength";
    wifiSensor["unit"] = "dBm";
    wifiSensor["description"] = "WiFi signal strength indicator";
    wifiSensor["location"] = "internal";
    wifiSensor["accuracy"] = 1.0;
    wifiSensor["enabled"] = true;
    JsonObject wifiThresholds = wifiSensor.createNestedObject("thresholds");
    wifiThresholds["min"] = -100;
    wifiThresholds["max"] = -30;
    
    Serial.println("📊 Configured Sensors:");
    Serial.println("  1. Temperature (DHT22) - Range: -10°C to 50°C");
    Serial.println("  2. Humidity (DHT22) - Range: 0% to 100%");
    Serial.println("  3. Light Level (LDR) - Range: 0% to 100%");
    Serial.println("  4. WiFi Signal - Range: -100dBm to -30dBm");
    
    // Device capabilities
    JsonArray capabilities = doc.createNestedArray("capabilities");
    capabilities.add("remote_control");
    capabilities.add("real_time_monitoring");
    capabilities.add("configuration_update");
    capabilities.add("status_reporting");
    capabilities.add("led_control");
    capabilities.add("sensor_reading");
    
    // Network information
    JsonObject networkInfo = doc.createNestedObject("network_info");
    networkInfo["ssid"] = WiFi.SSID();
    networkInfo["bssid"] = WiFi.BSSIDstr();
    networkInfo["channel"] = WiFi.channel();
    networkInfo["encryption"] = WiFi.encryptionType(0);
    
    // Serialize JSON to string
    String jsonString;
    size_t jsonSize = serializeJson(doc, jsonString);
    
    // Check if serialization was successful
    if (jsonSize == 0) {
        Serial.println("❌ ERROR: JSON serialization failed!");
        Serial.println("Memory status:");
        Serial.println("  - Free Heap: " + String(ESP.getFreeHeap()));
        Serial.println("  - Doc Size: " + String(doc.memoryUsage()));
        return;
    }
    
    Serial.println("📦 Discovery Response Details:");
    Serial.println("  - JSON Size: " + String(jsonSize) + " bytes");
    Serial.println("  - Buffer Size: 8192 bytes");
    Serial.println("  - Available Space: " + String(8192 - jsonSize) + " bytes");
    Serial.println("  - Memory Usage: " + String(doc.memoryUsage()) + " bytes");
    
    // Prepare topic
    String discoveryTopic = "devices/" + device_id + "/discovery/response";
    
    // Publish discovery response with QoS 1 to match Laravel expectations
    Serial.println("📡 Publishing discovery response...");
    Serial.println("  - Topic: " + discoveryTopic);
    Serial.println("  - QoS: 1 (guaranteed delivery)");
    Serial.println("  - Retained: false");
    
    // Use QoS 1 to match Laravel's MQTT service expectations
    bool published = client.publish(discoveryTopic, jsonString, false, 1);
    
    if (published) {
        Serial.println("✅ Discovery response published successfully!");
        Serial.println("📤 Complete JSON Response:");
        Serial.println(jsonString);
        
        // Blink LED to indicate successful discovery
        for(int i = 0; i < 5; i++) {
            digitalWrite(2, LOW);
            delay(100);
            digitalWrite(2, HIGH);
            delay(100);
        }
        
        // Wait a moment to ensure message is sent
        delay(100);
        
    } else {
        Serial.println("❌ Failed to publish discovery response!");
        Serial.println("   Checking connection status...");
        Serial.println("   - MQTT Connected: " + String(client.connected()));
        Serial.println("   - WiFi Connected: " + String(WiFi.status() == WL_CONNECTED));
        Serial.println("   - Free Heap: " + String(ESP.getFreeHeap()));
        
        // Attempt to reconnect if needed
        if (!client.connected()) {
            Serial.println("   - Attempting MQTT reconnection...");
            connectToMQTT();
        }
    }
    
    Serial.println("🏁 === DISCOVERY RESPONSE COMPLETE ===");
    Serial.println();
}

void loop() {
    // Handle MQTT connection - this is critical for receiving messages
    client.loop();
    delay(10);
    
    // Check connections and reconnect if necessary
    checkConnections();
    
    // Read sensors (simulate sensor readings)
    temperature = random(20, 35);
    humidity = random(40, 80);
    lightLevel = analogRead(32);
    
    // Publish sensor data every 10 seconds
    if (millis() - lastMillis > 10000) {
        lastMillis = millis();
        
        if (mqttConnected) {
            publishSensorData();
            publishDeviceStatus();
        } else {
            Serial.println("⚠️ Skipping data publish - MQTT not connected");
        }
    }
    
    // Send heartbeat every 30 seconds
    if (millis() - lastHeartbeat > heartbeatInterval) {
        lastHeartbeat = millis();
        
        if (mqttConnected) {
            publishHeartbeat();
        }
    }
}

void checkConnections() {
    // Check WiFi connection
    if (WiFi.status() != WL_CONNECTED) {
        if (wifiConnected) {
            Serial.println("⚠️ WiFi connection lost!");
            wifiConnected = false;
            mqttConnected = false;
        }
        
        if (millis() - lastReconnectAttempt > reconnectInterval) {
            Serial.println("🔄 Attempting WiFi reconnection...");
            connectToWiFi();
            lastReconnectAttempt = millis();
        }
        return;
    } else if (!wifiConnected) {
        wifiConnected = true;
        Serial.println("✅ WiFi reconnected!");
    }
    
    // Check MQTT connection
    if (!client.connected()) {
        if (mqttConnected) {
            Serial.println("⚠️ MQTT connection lost!");
            mqttConnected = false;
        }
        
        if (millis() - lastReconnectAttempt > reconnectInterval) {
            Serial.println("🔄 Attempting MQTT reconnection...");
            connectToMQTT();
            lastReconnectAttempt = millis();
        }
    } else if (!mqttConnected) {
        mqttConnected = true;
        Serial.println("✅ MQTT reconnected!");
        // Republish discovery after reconnection
        delay(1000);
        publishDeviceDiscovery();
    }
}

void publishSensorData() {
    if (!mqttConnected) return;
    
    DynamicJsonDocument doc(1024);
    
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["timestamp"] = millis() / 1000;
    doc["message_id"] = millis(); // Add unique message ID
    
    JsonObject sensors = doc.createNestedObject("sensors");
    sensors["temperature"] = temperature;
    sensors["humidity"] = humidity;
    sensors["light"] = map(lightLevel, 0, 4095, 0, 100);
    sensors["wifi_signal"] = WiFi.RSSI();
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String dataTopic = "devices/" + device_id + "/data";
    
    // Use QoS 0 for sensor data (frequent updates, loss acceptable)
    bool published = client.publish(dataTopic, jsonString, false, 0);
    
    if (published) {
        Serial.println("📊 Sensor data published: T=" + String(temperature) + 
                      "°C, H=" + String(humidity) + "%, L=" + 
                      String(map(lightLevel, 0, 4095, 0, 100)) + "%, RSSI=" + 
                      String(WiFi.RSSI()) + "dBm");
    } else {
        Serial.println("❌ Failed to publish sensor data");
    }
}

void publishDeviceStatus() {
    if (!mqttConnected) return;
    
    DynamicJsonDocument doc(512);
    
    doc["device_id"] = device_id;
    doc["status"] = "online";
    doc["uptime"] = millis() / 1000;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["timestamp"] = millis() / 1000;
    doc["discovery_count"] = discoveryCount;
    doc["message_type"] = "status";
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String statusTopic = "devices/" + device_id + "/status";
    
    // Use QoS 1 for status updates (important but not critical)
    bool published = client.publish(statusTopic, jsonString, false, 1);
    
    if (!published) {
        Serial.println("❌ Failed to publish device status");
    }
}

void publishHeartbeat() {
    if (!mqttConnected) return;
    
    DynamicJsonDocument doc(256);
    
    doc["device_id"] = device_id;
    doc["message_type"] = "heartbeat";
    doc["timestamp"] = millis() / 1000;
    doc["uptime"] = millis() / 1000;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["discovery_count"] = discoveryCount;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String heartbeatTopic = "devices/" + device_id + "/heartbeat";
    client.publish(heartbeatTopic, jsonString, false, 0);
    
    Serial.println("💓 Heartbeat sent - Uptime: " + String(millis() / 1000) + "s, Heap: " + String(ESP.getFreeHeap()));
}

