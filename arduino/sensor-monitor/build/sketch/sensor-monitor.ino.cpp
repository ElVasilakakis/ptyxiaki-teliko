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
MQTTClient client;
unsigned long lastMillis = 0;

// Device Configuration
const String device_id = "ESP32-DEV-001";
const String device_name = "Environmental Sensor Monitor";

// Sensor variables
int temperature;
int humidity;
int lightLevel;

#line 27 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void connect();
#line 49 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void messageReceived(String &topic, String &payload);
#line 76 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishDeviceDiscovery();
#line 116 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void setup();
#line 139 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void loop();
#line 161 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishSensorData();
#line 185 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishDeviceStatus();
#line 27 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void connect() {
    Serial.print("checking wifi...");
    while (WiFi.status() != WL_CONNECTED) {
        Serial.print(".");
        delay(1000);
    }

    Serial.print("\nconnecting to MQTT...");
    while (!client.connect(device_id.c_str(), mqtt_username, mqtt_password)) {
        Serial.print(".");
        delay(1000);
    }

    Serial.println("\nconnected!");

    // Subscribe to device control topics
    client.subscribe("devices/" + device_id + "/control/#");
    client.subscribe("devices/" + device_id + "/config/#");
    client.subscribe("devices/" + device_id + "/discover");  // Device-specific discovery
    client.subscribe("devices/discover/all");                // Global discovery
}

void messageReceived(String &topic, String &payload) {
    Serial.println("incoming: " + topic + " - " + payload);
    
    // Handle LED control
    if(topic == "devices/" + device_id + "/control/led") {
        digitalWrite(2, payload.toInt());
        Serial.println("LED set to: " + payload);
    }
    
    // Handle device configuration
    if(topic == "devices/" + device_id + "/config/interval") {
        Serial.println("Config update: " + payload);
    }
    
    // Handle device discovery request
    if(topic == "devices/" + device_id + "/discover") {
        Serial.println("Discovery request received");
        publishDeviceDiscovery();
    }
    
    // Handle global discovery request
    if(topic == "devices/discover/all") {
        Serial.println("Global discovery request received");
        publishDeviceDiscovery();
    }
}

void publishDeviceDiscovery() {
    DynamicJsonDocument doc(1024);
    
    // Device information
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["device_type"] = "SENSOR_MONITOR";
    // ... device details
    
    // Available sensors - THIS IS WHERE SENSOR DATA IS SENT
    JsonArray sensors = doc.createNestedArray("available_sensors");
    
    // Temperature sensor definition
    JsonObject tempSensor = sensors.createNestedObject();
    tempSensor["sensor_type"] = "temperature";
    tempSensor["sensor_name"] = "DHT22 Temperature";
    tempSensor["unit"] = "celsius";
    tempSensor["description"] = "Digital temperature sensor";
    tempSensor["location"] = "main_board";
    tempSensor["accuracy"] = 0.5;
    tempSensor["thresholds"]["min"] = -10;
    tempSensor["thresholds"]["max"] = 50;
    
    // Humidity sensor definition
    JsonObject humSensor = sensors.createNestedObject();
    humSensor["sensor_type"] = "humidity";
    // ... humidity sensor details
    
    // Light sensor definition
    JsonObject lightSensor = sensors.createNestedObject();
    lightSensor["sensor_type"] = "light";
    // ... light sensor details
    
    // WiFi signal sensor definition
    JsonObject wifiSensor = sensors.createNestedObject();
    wifiSensor["sensor_type"] = "wifi_signal";
    // ... wifi sensor details
}


void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("=== ESP32 MQTT Device Starting ===");
    
    // Initialize pins
    pinMode(2, OUTPUT);
    digitalWrite(2, LOW);
    
    // Connect to WiFi
    WiFi.begin(ssid, pass);
    
    // Setup MQTT client with broker and port
    client.begin(mqtt_broker, mqtt_port, net);
    client.onMessage(messageReceived);
    
    connect();
    
    Serial.println("=== Setup Complete ===");
    digitalWrite(2, HIGH);
}

void loop() {
    client.loop();
    delay(10);
    
    // Read sensors
    temperature = random(20, 35);
    humidity = random(40, 80);
    lightLevel = analogRead(32);
    
    // Reconnect if disconnected
    if (!client.connected()) {
        connect();
    }
    
    // Publish sensor data every 10 seconds
    if (millis() - lastMillis > 10000) {
        lastMillis = millis();
        publishSensorData();
        publishDeviceStatus();
    }
}

void publishSensorData() {
    DynamicJsonDocument doc(512);
    
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["timestamp"] = millis() / 1000;
    
    JsonObject sensors = doc.createNestedObject("sensors");
    sensors["temperature"] = temperature;
    sensors["humidity"] = humidity;
    sensors["light_level"] = map(lightLevel, 0, 4095, 0, 100);
    sensors["wifi_signal"] = WiFi.RSSI();
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String dataTopic = "devices/" + device_id + "/data";
    client.publish(dataTopic, jsonString);
    
    Serial.println("Published sensor data:");
    Serial.println("Topic: " + dataTopic);
    Serial.println("Data: " + jsonString);
}

void publishDeviceStatus() {
    DynamicJsonDocument doc(256);
    
    doc["device_id"] = device_id;
    doc["status"] = "online";
    doc["uptime"] = millis() / 1000;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["timestamp"] = millis() / 1000;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String statusTopic = "devices/" + device_id + "/status";
    client.publish(statusTopic, jsonString);
    
    Serial.println("Published device status to: " + statusTopic);
}

