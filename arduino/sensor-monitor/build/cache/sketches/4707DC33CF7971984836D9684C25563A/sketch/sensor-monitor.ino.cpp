#include <Arduino.h>
#line 1 "C:\\Users\\tsigk\\Desktop\\Apps\\Laravel\\ptyxiaki Inertia\\arduino\\sensor-monitor\\sensor-monitor.ino"
#include <WiFi.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include "DHT.h"

// DHT22 sensor setup
#define DHT_PIN 15
#define DHT_TYPE DHT22
DHT dht(DHT_PIN, DHT_TYPE);

// Photoresistor setup
#define LDR_PIN 32

// WiFi credentials for Wokwi
const char* ssid = "Wokwi-GUEST";
const char* password = "";

// Create WebServer object
WebServer server(80);

// Sensor structure definition
struct SensorConfig {
    String id;
    String name;
    String model;
    String type;
    String unit;
    float minThreshold;
    float maxThreshold;
    String location;
    int pin;
    bool isActive;
    
    // Add these fields:
    String serialNumber;        // Unique hardware identifier
    String firmwareVersion;     // For tracking updates
    String manufacturer;        // Device manufacturer
    float calibrationOffset;    // For sensor calibration
    int samplingRate;          // How often sensor reads (seconds)
    String description;        // Human-readable description
    String category;           // Group sensors (environmental, security, etc.)
};

// Sensor data structure
struct SensorData {
    String id;
    float value;
    String status;
    unsigned long lastRead;
    bool isValid;
    
    // Add these fields:
    float rawValue;            // Before calibration/processing
    int signalStrength;        // WiFi/connection quality
    float batteryLevel;        // If battery powered
    String errorMessage;       // Detailed error info
    int readingCount;          // Total readings taken
    float averageValue;        // Rolling average
    float minValue;           // Min value in current period
    float maxValue;           // Max value in current period
};


SensorConfig sensors[] = {
    {
        "temp_01",
        "Temperature Sensor",
        "DHT22",
        "temperature",
        "celsius",
        -10.0,     // min threshold
        50.0,      // max threshold
        "Room A",
        DHT_PIN,
        true,
        
        // New fields:
        "DHT22-001-TEMP",           // serialNumber
        "1.0.0",                    // firmwareVersion
        "Adafruit",                 // manufacturer
        0.0,                        // calibrationOffset
        2,                          // samplingRate (seconds)
        "High precision temperature sensor for indoor monitoring", // description
        "environmental"             // category
    },
    {
        "hum_01",
        "Humidity Sensor",
        "DHT22",
        "humidity",
        "percent",
        20.0,      // min threshold
        80.0,      // max threshold
        "Room A",
        DHT_PIN,
        true,
        
        // New fields:
        "DHT22-001-HUM",           // serialNumber
        "1.0.0",                   // firmwareVersion
        "Adafruit",                // manufacturer
        0.0,                       // calibrationOffset
        2,                         // samplingRate (seconds)
        "High precision humidity sensor for indoor climate monitoring", // description
        "environmental"            // category
    },
    {
        "light_01",
        "Light Sensor",
        "LDR GL5528",
        "light",
        "percent",
        0.0,       // min threshold
        100.0,     // max threshold
        "Window",
        LDR_PIN,
        true,
        
        // New fields:
        "GL5528-001",              // serialNumber
        "1.0.0",                   // firmwareVersion
        "Generic",                 // manufacturer
        0.0,                       // calibrationOffset
        2,                         // samplingRate (seconds)
        "Photoresistor for ambient light level detection", // description
        "environmental"            // category
    }
};


const int NUM_SENSORS = sizeof(sensors) / sizeof(sensors[0]);
SensorData sensorData[NUM_SENSORS];

unsigned long lastSensorRead = 0;
const unsigned long sensorInterval = 2000;

void setup() {
    Serial.begin(115200);
    delay(2000);
    
    Serial.println("=== ESP32 Sensor Registry Starting ===");
    
    // Initialize sensors
    initializeSensors();
    
    // Connect to WiFi
    connectToWiFi();
    
    if (WiFi.status() == WL_CONNECTED) {
        // Setup web server routes
        setupWebServer();
        
        // Start the server
        server.begin();
        Serial.println("HTTP server started");
        Serial.println("=== Available Routes ===");
        printAvailableRoutes();
    }
    
    Serial.println("=== Setup Complete ===");
    
    // Initial sensor reading
    readAllSensors();
}

void loop() {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi disconnected, attempting reconnection...");
        WiFi.reconnect();
        delay(5000);
        return;
    }
    
    server.handleClient();
    
    // Read sensors periodically
    if (millis() - lastSensorRead > sensorInterval) {
        readAllSensors();
        lastSensorRead = millis();
    }
    
    delay(10);
}

void initializeSensors() {
    dht.begin();
    Serial.println("Sensors initialized");
    
    // Initialize sensor data
    for (int i = 0; i < NUM_SENSORS; i++) {
        sensorData[i].id = sensors[i].id;
        sensorData[i].value = 0.0;
        sensorData[i].status = "initializing";
        sensorData[i].lastRead = 0;
        sensorData[i].isValid = false;
        
        // Initialize new fields:
        sensorData[i].rawValue = 0.0;
        sensorData[i].signalStrength = WiFi.RSSI();
        sensorData[i].batteryLevel = 100.0;  // Assuming powered device
        sensorData[i].errorMessage = "";
        sensorData[i].readingCount = 0;
        sensorData[i].averageValue = 0.0;
        sensorData[i].minValue = 999999.0;
        sensorData[i].maxValue = -999999.0;
    }
    
    Serial.printf("Registered %d sensors\n", NUM_SENSORS);
}


void connectToWiFi() {
    Serial.println("Connecting to WiFi...");
    WiFi.mode(WIFI_STA);
    WiFi.begin(ssid, password);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("");
        Serial.println("WiFi connected!");
        Serial.print("IP address: ");
        Serial.println(WiFi.localIP());
    } else {
        Serial.println("");
        Serial.println("WiFi connection failed!");
    }
}

void readAllSensors() {
    unsigned long currentTime = millis();
    
    // Read DHT22 for temperature and humidity
    float temp = dht.readTemperature();
    float hum = dht.readHumidity();
    
    // Read LDR
    int rawLDR = analogRead(LDR_PIN);
    int lightLevel = map(rawLDR, 0, 4095, 0, 100);
    
    // Update sensor data
    for (int i = 0; i < NUM_SENSORS; i++) {
        if (sensors[i].type == "temperature" && !isnan(temp)) {
            updateSensorData(i, temp, currentTime);
        } else if (sensors[i].type == "humidity" && !isnan(hum)) {
            updateSensorData(i, hum, currentTime);
        } else if (sensors[i].type == "light") {
            updateSensorData(i, lightLevel, currentTime);
        }
    }
}

void updateSensorData(int index, float value, unsigned long timestamp) {
    // Store raw value before any processing
    sensorData[index].rawValue = value;
    
    // Apply calibration offset
    float calibratedValue = value + sensors[index].calibrationOffset;
    sensorData[index].value = calibratedValue;
    
    sensorData[index].lastRead = timestamp;
    sensorData[index].isValid = true;
    sensorData[index].readingCount++;
    sensorData[index].signalStrength = WiFi.RSSI();
    sensorData[index].errorMessage = "";
    
    // Update min/max values
    if (calibratedValue < sensorData[index].minValue) {
        sensorData[index].minValue = calibratedValue;
    }
    if (calibratedValue > sensorData[index].maxValue) {
        sensorData[index].maxValue = calibratedValue;
    }
    
    // Calculate simple rolling average (last 10 readings)
    static float readings[3][10] = {0}; // 3 sensors, 10 readings each
    static int readingIndex[3] = {0};
    
    readings[index][readingIndex[index]] = calibratedValue;
    readingIndex[index] = (readingIndex[index] + 1) % 10;
    
    float sum = 0;
    for (int i = 0; i < 10; i++) {
        sum += readings[index][i];
    }
    sensorData[index].averageValue = sum / 10.0;
    
    // Check thresholds
    if (calibratedValue < sensors[index].minThreshold || calibratedValue > sensors[index].maxThreshold) {
        sensorData[index].status = "warning";
    } else {
        sensorData[index].status = "normal";
    }
}


void setupWebServer() {
    // Main routes
    server.on("/", HTTP_GET, handleRoot);
    server.on("/api/info", HTTP_GET, handleAPIInfo);  // Add this line
    server.on("/sensors", HTTP_GET, handleAllSensors);
    server.on("/sensors/registry", HTTP_GET, handleSensorRegistry);
    
    // Individual sensor routes
    server.on("/sensors/temp_01", HTTP_GET, []() { handleIndividualSensor("temp_01"); });
    server.on("/sensors/hum_01", HTTP_GET, []() { handleIndividualSensor("hum_01"); });
    server.on("/sensors/light_01", HTTP_GET, []() { handleIndividualSensor("light_01"); });
    
    // Alternative routes by type
    server.on("/sensors/temperature", HTTP_GET, []() { handleSensorsByType("temperature"); });
    server.on("/sensors/humidity", HTTP_GET, []() { handleSensorsByType("humidity"); });
    server.on("/sensors/light", HTTP_GET, []() { handleSensorsByType("light"); });
    
    // System status
    server.on("/status", HTTP_GET, handleSystemStatus);
    
    // Handle 404
    server.onNotFound(handleNotFound);
}

void handleAPIInfo() {
    addCORSHeaders();
    
    DynamicJsonDocument doc(3072);
    
    // Detailed API documentation
    doc["api_name"] = "ESP32 Sensor Registry API";
    doc["version"] = "1.0.0";
    doc["description"] = "Complete IoT sensor monitoring and data collection API";
    doc["base_url"] = "http://" + WiFi.localIP().toString();
    doc["base_url_localhost"] = "http://localhost:8180/" ;
    doc["documentation_generated"] = millis();
    
    // Detailed endpoint documentation
    JsonArray routes = doc.createNestedArray("routes");
    
    // Root endpoint
    JsonObject rootRoute = routes.createNestedObject();
    rootRoute["path"] = "/";
    rootRoute["method"] = "GET";
    rootRoute["description"] = "API overview and quick route reference";
    rootRoute["response"] = "Basic API information";

    rootRoute["path"] = "/api/info";
    rootRoute["method"] = "GET";
    rootRoute["description"] = "API detailed documentation with all routes";
    rootRoute["response"] = "Detailed API information";
    
    // API Info endpoint
    JsonObject apiInfoRoute = routes.createNestedObject();
    apiInfoRoute["path"] = "/api/info";
    apiInfoRoute["method"] = "GET";
    apiInfoRoute["description"] = "Detailed API documentation";
    apiInfoRoute["response"] = "Complete route documentation";
    
    // All sensors endpoint
    JsonObject allSensorsRoute = routes.createNestedObject();
    allSensorsRoute["path"] = "/sensors";
    allSensorsRoute["method"] = "GET";
    allSensorsRoute["description"] = "Get all sensors with current readings and metadata";
    allSensorsRoute["response"] = "Array of all sensors with data";
    
    // Registry endpoint
    JsonObject registryRoute = routes.createNestedObject();
    registryRoute["path"] = "/sensors/registry";
    registryRoute["method"] = "GET";
    registryRoute["description"] = "Get sensor configurations without current data";
    registryRoute["response"] = "Array of sensor configurations";
    
    // Individual sensor endpoints
    for (int i = 0; i < NUM_SENSORS; i++) {
        JsonObject sensorRoute = routes.createNestedObject();
        sensorRoute["path"] = "/sensors/" + sensors[i].id;
        sensorRoute["method"] = "GET";
        sensorRoute["description"] = "Get detailed data for " + sensors[i].name;
        sensorRoute["sensor_type"] = sensors[i].type;
        sensorRoute["sensor_model"] = sensors[i].model;
        sensorRoute["response"] = "Complete sensor data and metadata";
    }
    
    // Type-based endpoints
    JsonArray types = doc.createNestedArray("sensor_types");
    types.add("temperature");
    types.add("humidity");
    types.add("light");
    
    for (JsonVariant type : types) {
        JsonObject typeRoute = routes.createNestedObject();
        typeRoute["path"] = "/sensors/" + type.as<String>();
        typeRoute["method"] = "GET";
        typeRoute["description"] = "Get all " + type.as<String>() + " sensors";
        typeRoute["response"] = "Array of " + type.as<String>() + " sensors";
    }
    
    // Status endpoint
    JsonObject statusRoute = routes.createNestedObject();
    statusRoute["path"] = "/status";
    statusRoute["method"] = "GET";
    statusRoute["description"] = "System health and status information";
    statusRoute["response"] = "System metrics and connectivity status";
    
    // Response format information
    JsonObject responseInfo = doc.createNestedObject("response_format");
    responseInfo["content_type"] = "application/json";
    responseInfo["encoding"] = "UTF-8";
    responseInfo["cors_enabled"] = true;
    
    // Error handling
    JsonObject errorInfo = doc.createNestedObject("error_handling");
    errorInfo["404"] = "Route not found - returns available endpoints";
    errorInfo["500"] = "Internal server error";
    errorInfo["format"] = "JSON with error message and details";
    
    // System information
    JsonObject systemInfo = doc.createNestedObject("system_info");
    systemInfo["total_sensors"] = NUM_SENSORS;
    systemInfo["wifi_connected"] = WiFi.status() == WL_CONNECTED;
    systemInfo["ip_address"] = WiFi.localIP().toString();
    systemInfo["uptime_seconds"] = millis() / 1000;
    systemInfo["free_heap"] = ESP.getFreeHeap();
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void addCORSHeaders() {
    server.sendHeader("Access-Control-Allow-Origin", "*");
    server.sendHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
    server.sendHeader("Access-Control-Allow-Headers", "Content-Type");
}

void handleRoot() {
    addCORSHeaders();
    
    DynamicJsonDocument doc(2048);
    
    // API Information
    doc["message"] = "ESP32 Sensor Registry API";
    doc["version"] = "1.0";
    doc["description"] = "IoT sensor monitoring and data collection API";
    doc["total_sensors"] = NUM_SENSORS;
    doc["uptime"] = millis() / 1000;
    doc["timestamp"] = millis();
    
    // Available endpoints with descriptions
    JsonObject endpoints = doc.createNestedObject("endpoints");
    
    // Main routes
    JsonObject mainRoutes = endpoints.createNestedObject("main");
    mainRoutes["/"] = "API information and available routes";
    mainRoutes["/api/info1"] = "API more detailed information and api endpoints";
    mainRoutes["/sensors"] = "Get all sensors with current data";
    mainRoutes["/sensors/registry"] = "Get sensor configurations only";
    mainRoutes["/status"] = "System status and health information";
    
    // Individual sensor routes
    JsonObject sensorRoutes = endpoints.createNestedObject("individual_sensors");
    for (int i = 0; i < NUM_SENSORS; i++) {
        String route = "/sensors/" + sensors[i].id;
        String description = sensors[i].name + " (" + sensors[i].model + ") data";
        sensorRoutes[route] = description;
    }
    
    // Type-based routes
    JsonObject typeRoutes = endpoints.createNestedObject("by_type");
    typeRoutes["/sensors/temperature"] = "All temperature sensors";
    typeRoutes["/sensors/humidity"] = "All humidity sensors";
    typeRoutes["/sensors/light"] = "All light sensors";
    
    // HTTP methods supported
    JsonArray methods = doc.createNestedArray("supported_methods");
    methods.add("GET");
    
    // Response formats
    doc["response_format"] = "application/json";
    
    // CORS information
    JsonObject cors = doc.createNestedObject("cors");
    cors["enabled"] = true;
    cors["origin"] = "*";
    cors["methods"] = "GET, POST, OPTIONS";
    
    // Example usage
    JsonObject examples = doc.createNestedObject("example_usage");
    examples["get_all_sensors"] = "GET /sensors";
    examples["get_temperature"] = "GET /sensors/temp_01";
    examples["get_system_status"] = "GET /status";
    
    // Sensor categories available
    JsonArray categories = doc.createNestedArray("sensor_categories");
    categories.add("environmental");
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleAllSensors() {
    addCORSHeaders();
    
    DynamicJsonDocument doc(2048);
    doc["timestamp"] = millis();
    doc["total_sensors"] = NUM_SENSORS;
    
    JsonArray sensorsArray = doc.createNestedArray("sensors");
    
    for (int i = 0; i < NUM_SENSORS; i++) {
        JsonObject sensor = sensorsArray.createNestedObject();
        
        // Sensor config
        sensor["id"] = sensors[i].id;
        sensor["name"] = sensors[i].name;
        sensor["model"] = sensors[i].model;
        sensor["type"] = sensors[i].type;
        sensor["unit"] = sensors[i].unit;
        sensor["location"] = sensors[i].location;
        sensor["is_active"] = sensors[i].isActive;
        
        // Sensor data
        sensor["current_value"] = round(sensorData[i].value * 100.0) / 100.0;
        sensor["status"] = sensorData[i].status;
        sensor["last_read"] = sensorData[i].lastRead;
        sensor["is_valid"] = sensorData[i].isValid;
        
        // Thresholds
        JsonObject thresholds = sensor.createNestedObject("thresholds");
        thresholds["min"] = sensors[i].minThreshold;
        thresholds["max"] = sensors[i].maxThreshold;
    }
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleSensorRegistry() {
    addCORSHeaders();
    
    DynamicJsonDocument doc(1024);
    doc["total_sensors"] = NUM_SENSORS;
    
    JsonArray registry = doc.createNestedArray("sensor_registry");
    
    for (int i = 0; i < NUM_SENSORS; i++) {
        JsonObject sensor = registry.createNestedObject();
        sensor["id"] = sensors[i].id;
        sensor["name"] = sensors[i].name;
        sensor["model"] = sensors[i].model;
        sensor["type"] = sensors[i].type;
        sensor["unit"] = sensors[i].unit;
        sensor["location"] = sensors[i].location;
        sensor["pin"] = sensors[i].pin;
        sensor["is_active"] = sensors[i].isActive;
        sensor["endpoint"] = "/sensors/" + sensors[i].id;
        
        JsonObject thresholds = sensor.createNestedObject("thresholds");
        thresholds["min"] = sensors[i].minThreshold;
        thresholds["max"] = sensors[i].maxThreshold;
    }
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleIndividualSensor(String sensorId) {
    addCORSHeaders();
    
    int sensorIndex = findSensorIndex(sensorId);
    
    if (sensorIndex == -1) {
        StaticJsonDocument<150> errorDoc;
        errorDoc["error"] = "Sensor not found";
        errorDoc["sensor_id"] = sensorId;
        
        String response;
        serializeJson(errorDoc, response);
        server.send(404, "application/json", response);
        return;
    }
    
    StaticJsonDocument<500> doc;
    
    // Sensor configuration
    doc["id"] = sensors[sensorIndex].id;
    doc["name"] = sensors[sensorIndex].name;
    doc["model"] = sensors[sensorIndex].model;
    doc["type"] = sensors[sensorIndex].type;
    doc["unit"] = sensors[sensorIndex].unit;
    doc["location"] = sensors[sensorIndex].location;
    doc["pin"] = sensors[sensorIndex].pin;
    doc["is_active"] = sensors[sensorIndex].isActive;
    
    // Current data
    doc["current_value"] = round(sensorData[sensorIndex].value * 100.0) / 100.0;
    doc["status"] = sensorData[sensorIndex].status;
    doc["last_read"] = sensorData[sensorIndex].lastRead;
    doc["is_valid"] = sensorData[sensorIndex].isValid;
    doc["timestamp"] = millis();
    
    // Thresholds
    JsonObject thresholds = doc.createNestedObject("thresholds");
    thresholds["min"] = sensors[sensorIndex].minThreshold;
    thresholds["max"] = sensors[sensorIndex].maxThreshold;
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleSensorsByType(String sensorType) {
    addCORSHeaders();
    
    DynamicJsonDocument doc(1024);
    doc["type"] = sensorType;
    doc["timestamp"] = millis();
    
    JsonArray sensorsArray = doc.createNestedArray("sensors");
    
    for (int i = 0; i < NUM_SENSORS; i++) {
        if (sensors[i].type == sensorType) {
            JsonObject sensor = sensorsArray.createNestedObject();
            sensor["id"] = sensors[i].id;
            sensor["name"] = sensors[i].name;
            sensor["model"] = sensors[i].model;
            sensor["current_value"] = round(sensorData[i].value * 100.0) / 100.0;
            sensor["unit"] = sensors[i].unit;
            sensor["status"] = sensorData[i].status;
            sensor["location"] = sensors[i].location;
        }
    }
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleSystemStatus() {
    addCORSHeaders();
    
    StaticJsonDocument<300> doc;
    doc["wifi_connected"] = WiFi.status() == WL_CONNECTED;
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["ip_address"] = WiFi.localIP().toString();
    doc["uptime"] = millis() / 1000;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["total_sensors"] = NUM_SENSORS;
    doc["last_sensor_read"] = lastSensorRead;
    doc["status"] = "ok";
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleNotFound() {
    addCORSHeaders();
    
    StaticJsonDocument<200> doc;
    doc["error"] = "Not Found";
    doc["uri"] = server.uri();
    doc["method"] = (server.method() == HTTP_GET) ? "GET" : "POST";
    doc["available_endpoints"] = "/sensors, /sensors/registry, /sensors/{id}, /status";
    
    String response;
    serializeJson(doc, response);
    server.send(404, "application/json", response);
}

int findSensorIndex(String sensorId) {
    for (int i = 0; i < NUM_SENSORS; i++) {
        if (sensors[i].id == sensorId) {
            return i;
        }
    }
    return -1;
}

void printAvailableRoutes() {
    Serial.println("/ - API info");
    Serial.println("/sensors - All sensors with data");
    Serial.println("/sensors/registry - Sensor configurations");
    Serial.println("/status - System status");
    Serial.println("");
    Serial.println("Individual sensor routes:");
    for (int i = 0; i < NUM_SENSORS; i++) {
        Serial.printf("/sensors/%s - %s (%s)\n", 
                     sensors[i].id.c_str(), 
                     sensors[i].name.c_str(), 
                     sensors[i].model.c_str());
    }
    Serial.println("");
    Serial.println("By type routes:");
    Serial.println("/sensors/temperature");
    Serial.println("/sensors/humidity");
    Serial.println("/sensors/light");
}

