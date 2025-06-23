#include <WiFi.h>
#include <MQTT.h>
#include <ArduinoJson.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include <TinyGPS++.h>

// DHT22 Configuration
#define DHTPIN 15
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

// LCD Configuration
LiquidCrystal_I2C lcd(0x27, 16, 2);

// GPS Configuration - Using Hardware Serial
HardwareSerial gpsSerial(2);
static const uint32_t GPSBaud = 9600;
TinyGPSPlus gps;

// Pin Definitions
#define PHOTORESISTOR_PIN 32
#define POTENTIOMETER_PIN 34
#define GREEN_LED_PIN 2
#define BLUE_LED_PIN 4

const char ssid[] = "Wokwi-GUEST";
const char pass[] = "";

// MQTT Configuration
const char* mqtt_broker = "broker.emqx.io";
const int mqtt_port = 1883;
const char* mqtt_username = "mqttuser";
const char* mqtt_password = "12345678";

WiFiClient net;
MQTTClient client(4096); 
unsigned long lastMillis = 0;
unsigned long lastDiscovery = 0;
unsigned long lastLcdUpdate = 0;
unsigned long lastGpsUpdate = 0;

// Device Configuration
const String device_id = "ESP32-DEV-001";
const String device_name = "Environmental Sensor Monitor with GPS";
const String firmware_version = "1.3.0";
const String device_type = "ESP32_ENVIRONMENTAL_GPS";

// Sensor variables
float temperature = 0.0;
float humidity = 0.0;
int lightLevel = 0;
int potValue = 0;
int wifiSignal = 0;
float batteryLevel = 100.0;

// GPS variables
double latitude = 0.0;
double longitude = 0.0;
double altitude = 0.0;
double speed_kmh = 0.0;
int satellites = 0;
bool gpsValid = false;
String gpsTimestamp = "";

// Geofence testing variables
bool testGeofencing = true;
bool generateInsideGeofence = true;  // Start with inside
unsigned long lastGeofenceToggle = 0;
const unsigned long geofenceToggleInterval = 120000;  // 2 minutes

// Xorafi 1 polygon coordinates (Colorado)
const double XORAFI_COORDS[][2] = {
    {-107.744122, 39.495387},
    {-107.744122, 39.529577},
    {-107.653999, 39.529577},
    {-107.653999, 39.495387},
    {-107.744122, 39.495387}  // Close the polygon
};
const int XORAFI_COORD_COUNT = 5;

// Xorafi 1 bounding box for inside generation
const double XORAFI_MIN_LAT = 39.495387;
const double XORAFI_MAX_LAT = 39.529577;
const double XORAFI_MIN_LNG = -107.744122;
const double XORAFI_MAX_LNG = -107.653999;

// San Francisco base coordinates (for outside testing)
const double SF_BASE_LAT = 37.7749;
const double SF_BASE_LNG = -122.4194;
const double RADIUS_METERS = 1000.0;

bool useSimulatedGPS = false;
unsigned long lastLocationChange = 0;

// Calibration offsets
float tempOffset = 0.0;
float humOffset = 0.0;

// Function Declarations
void connect();
void messageReceived(String &topic, String &payload);
void publishDeviceDiscovery();
void readSensors();
void readGPSData();
void generateGPSData();
void generateInsideXorafi();
void generateOutsideXorafi();
void generateSanFranciscoRandom();
bool isPointInPolygon(double lat, double lng);
void generateGPSTimestamp();
void publishSensorData();
void publishGPSData();
void publishDeviceStatus(String status = "online");
void publishControlResponse(String control, String value);
void handleCalibrationUpdate(String payload);
void handleSensorConfig(String payload);
void updateLCD();

void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("=== ESP32 GEOFENCE TESTING DEVICE ===");
    Serial.println("Device ID: " + device_id);
    Serial.println("Firmware: " + firmware_version);
    Serial.println("=====================================");
    
    // Initialize random seed
    randomSeed(analogRead(0));
    
    // Initialize pins
    pinMode(GREEN_LED_PIN, OUTPUT);
    pinMode(BLUE_LED_PIN, OUTPUT);
    digitalWrite(GREEN_LED_PIN, LOW);
    digitalWrite(BLUE_LED_PIN, LOW);
    
    // Initialize DHT22 sensor
    dht.begin();
    
    // Initialize GPS with Hardware Serial
    gpsSerial.begin(GPSBaud, SERIAL_8N1, 16, 17); // RX=16, TX=17
    Serial.println("GPS module initialized with Hardware Serial");
    
    // Initialize LCD
    lcd.init();
    lcd.backlight();
    lcd.setCursor(0, 0);
    lcd.print("Initializing...");
    
    // Configure ADC
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);
    
    WiFi.begin(ssid, pass);
    
    client.begin(mqtt_broker, mqtt_port, net);
    client.onMessage(messageReceived);
    client.setKeepAlive(60);
    client.setCleanSession(true);
    
    connect();
    
    Serial.println("=== GEOFENCE TESTING MODE ACTIVE ===");
    Serial.println("Will alternate between INSIDE and OUTSIDE Xorafi 1 every 2 minutes");
    Serial.println("Current mode: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE"));
    Serial.println("=====================================");
    
    digitalWrite(GREEN_LED_PIN, HIGH);
    
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Geofence Test");
    lcd.setCursor(0, 1);
    lcd.print("Mode: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE"));
    delay(3000);
    
    // Start GPS simulation immediately for testing
    Serial.println("Starting GPS simulation for geofence testing...");
    useSimulatedGPS = true;
    generateGPSData();
}

void loop() {
    client.loop();
    delay(10);
    
    if (!client.connected()) {
        Serial.println("MQTT disconnected, reconnecting...");
        connect();
    }
    
    unsigned long currentTime = millis();
    
    // Toggle geofence mode every 2 minutes
    if (currentTime - lastGeofenceToggle > geofenceToggleInterval) {
        generateInsideGeofence = !generateInsideGeofence;
        lastGeofenceToggle = currentTime;
        
        Serial.println("\n=== GEOFENCE TEST MODE SWITCHED ===");
        Serial.println("Now generating: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE") + " Xorafi 1");
        Serial.println("===================================\n");
        
        // Update LCD to show new mode
        lcd.clear();
        lcd.setCursor(0, 1);
        lcd.print("Mode: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE"));
        
        // Generate new GPS data immediately after mode switch
        generateGPSData();
    }
    
    // Read GPS data (simulated for testing)
    readGPSData();
    
    // Publish sensor data every 10 seconds
    if (currentTime - lastMillis > 10000) {
        lastMillis = currentTime;
        readSensors();
        publishSensorData();
        publishDeviceStatus(); 
    }
    
    // Publish GPS data every 15 seconds (if valid)
    if (currentTime - lastGpsUpdate > 15000 && gpsValid) {
        lastGpsUpdate = currentTime;
        publishGPSData();
    }
    
    // Update LCD every 3 seconds
    if (currentTime - lastLcdUpdate > 3000) {
        lastLcdUpdate = currentTime;
        updateLCD();
    }
    
    // Auto-republish discovery every 5 minutes
    if (currentTime - lastDiscovery > 300000) {
        lastDiscovery = millis();
        publishDeviceDiscovery();
    }
}

void connect() {
    Serial.print("Checking WiFi...");
    while (WiFi.status() != WL_CONNECTED) {
        Serial.print(".");
        delay(1000);
    }

    Serial.print("\nConnecting to MQTT...");
    while (!client.connect(device_id.c_str(), mqtt_username, mqtt_password)) {
        Serial.print(".");
        delay(1000);
    }

    Serial.println("\nMQTT Connected!");

    // Subscribe to topics
    client.subscribe("devices/" + device_id + "/control/#");
    client.subscribe("devices/" + device_id + "/config/#");
    client.subscribe("devices/" + device_id + "/discover");
    client.subscribe("devices/discover/all");
    
    publishDeviceStatus("online");
    
    readSensors();
    delay(1000);
    publishDeviceDiscovery();
}

void messageReceived(String &topic, String &payload) {
    Serial.println("Received: " + topic + " - " + payload);
    
    if(topic == "devices/" + device_id + "/control/green_led") {
        digitalWrite(GREEN_LED_PIN, payload.toInt());
        publishControlResponse("green_led", payload.toInt() ? "on" : "off");
    }
    
    if(topic == "devices/" + device_id + "/control/blue_led") {
        digitalWrite(BLUE_LED_PIN, payload.toInt());
        publishControlResponse("blue_led", payload.toInt() ? "on" : "off");
    }
    
    if(topic == "devices/" + device_id + "/control/toggle_geofence") {
        generateInsideGeofence = !generateInsideGeofence;
        lastGeofenceToggle = millis(); // Reset timer
        Serial.println("Manually toggled to: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE"));
        generateGPSData(); // Generate new coordinates immediately
        publishControlResponse("toggle_geofence", generateInsideGeofence ? "inside" : "outside");
    }
    
    if(topic == "devices/" + device_id + "/config/calibration") {
        handleCalibrationUpdate(payload);
    }
    
    if(topic == "devices/" + device_id + "/discover" || topic == "devices/discover/all") {
        Serial.println("Discovery request received");
        readSensors();
        publishDeviceDiscovery();
    }
    
    if(topic == "devices/" + device_id + "/config/sensors") {
        handleSensorConfig(payload);
    }
}

void readGPSData() {
    // For testing, we always use simulated GPS with geofence testing
    if (useSimulatedGPS) {
        // Change location every 30 seconds for more frequent updates
        if (millis() - lastLocationChange > 30000) {
            generateGPSData();
            lastLocationChange = millis();
        }
    } else {
        // Try to read real GPS data (keeping original functionality)
        bool realGpsData = false;
        while (gpsSerial.available() > 0) {
            if (gps.encode(gpsSerial.read())) {
                if (gps.location.isValid()) {
                    latitude = gps.location.lat();
                    longitude = gps.location.lng();
                    gpsValid = true;
                    realGpsData = true;
                    useSimulatedGPS = false;
                    
                    if (gps.altitude.isValid()) {
                        altitude = gps.altitude.meters();
                    }
                    
                    if (gps.speed.isValid()) {
                        speed_kmh = gps.speed.kmph();
                    }
                    
                    if (gps.satellites.isValid()) {
                        satellites = gps.satellites.value();
                    }
                    
                    if (gps.time.isValid() && gps.date.isValid()) {
                        char timeBuffer[32];
                        sprintf(timeBuffer, "%04d-%02d-%02d %02d:%02d:%02d", 
                                gps.date.year(), gps.date.month(), gps.date.day(),
                                gps.time.hour(), gps.time.minute(), gps.time.second());
                        gpsTimestamp = String(timeBuffer);
                    }
                    
                    Serial.println("=== REAL GPS Data ===");
                    Serial.println("Latitude: " + String(latitude, 6));
                    Serial.println("Longitude: " + String(longitude, 6));
                    return;
                }
            }
        }
        
        // If no real GPS, start simulation
        if (!realGpsData && !useSimulatedGPS) {
            Serial.println("No real GPS detected, starting simulation for testing...");
            useSimulatedGPS = true;
            generateGPSData();
            lastLocationChange = millis();
        }
    }
}

void generateGPSData() {
    if (generateInsideGeofence) {
        generateInsideXorafi();
    } else {
        generateOutsideXorafi();
    }
    
    generateGPSTimestamp();
}

void generateInsideXorafi() {
    // Updated coordinates for the new rectangular Xorafi polygon
    // Based on your new geojson: lat range 39.495387 to 39.529577, lng range -107.744122 to -107.653999
    
    // For a rectangle, we can generate points more efficiently
    // Generate random point within bounding box (all points will be inside for a rectangle)
    double testLat = XORAFI_MIN_LAT + (random(0, 1000001) / 1000000.0) * (XORAFI_MAX_LAT - XORAFI_MIN_LAT);
    double testLng = XORAFI_MIN_LNG + (random(0, 1000001) / 1000000.0) * (XORAFI_MAX_LNG - XORAFI_MIN_LNG);
    
    // Since your new polygon is a rectangle, any point within the bounding box is guaranteed to be inside
    latitude = testLat;
    longitude = testLng;
    
    // Set Colorado-appropriate values
    altitude = 2500.0 + random(-100, 101);  // 2400-2600m elevation (Colorado altitude)
    speed_kmh = random(0, 31) / 10.0;       // 0-3 km/h (stationary to walking speed)
    satellites = random(10, 15);            // Good satellite count for clear sky
    gpsValid = true;
    
    Serial.println("✓ INSIDE Xorafi rectangle generated");
    Serial.println("GPS: " + String(latitude, 6) + ", " + String(longitude, 6) + " (INSIDE)");
    Serial.println("Bounds: Lat[" + String(XORAFI_MIN_LAT, 6) + " to " + String(XORAFI_MAX_LAT, 6) + "] Lng[" + String(XORAFI_MIN_LNG, 6) + " to " + String(XORAFI_MAX_LNG, 6) + "]");
}


void generateOutsideXorafi() {
    int locationChoice = random(0, 4);
    
    switch (locationChoice) {
        case 0: // San Francisco
            latitude = SF_BASE_LAT + (random(-1000, 1001) / 100000.0);
            longitude = SF_BASE_LNG + (random(-1000, 1001) / 100000.0);
            altitude = 50.0 + random(-20, 21);
            Serial.println("✗ OUTSIDE Xorafi: San Francisco");
            break;
            
        case 1: // Denver, Colorado (close but outside)
            latitude = 39.7392 + (random(-500, 501) / 100000.0);
            longitude = -104.9903 + (random(-500, 501) / 100000.0);
            altitude = 1600.0 + random(-50, 51);
            Serial.println("✗ OUTSIDE Xorafi: Denver");
            break;
            
        case 2: // New York
            latitude = 40.7128 + (random(-300, 301) / 100000.0);
            longitude = -74.0060 + (random(-300, 301) / 100000.0);
            altitude = 10.0 + random(-5, 16);
            Serial.println("✗ OUTSIDE Xorafi: New York");
            break;
            
        case 3: // Los Angeles
            latitude = 34.0522 + (random(-300, 301) / 100000.0);
            longitude = -118.2437 + (random(-300, 301) / 100000.0);
            altitude = 100.0 + random(-30, 31);
            Serial.println("✗ OUTSIDE Xorafi: Los Angeles");
            break;
    }
    
    speed_kmh = random(0, 801) / 10.0;  // 0-80 km/h
    satellites = random(6, 13);
    gpsValid = true;
    
    Serial.println("GPS: " + String(latitude, 6) + ", " + String(longitude, 6) + " (OUTSIDE)");
}

// Ray casting algorithm to check if point is inside polygon
bool isPointInPolygon(double lat, double lng) {
    int intersections = 0;
    
    for (int i = 0; i < XORAFI_COORD_COUNT - 1; i++) {
        double x1 = XORAFI_COORDS[i][0];     // longitude
        double y1 = XORAFI_COORDS[i][1];     // latitude
        double x2 = XORAFI_COORDS[i + 1][0]; // longitude
        double y2 = XORAFI_COORDS[i + 1][1]; // latitude
        
        // Check if ray crosses this edge
        if (((y1 > lat) != (y2 > lat)) &&
            (lng < (x2 - x1) * (lat - y1) / (y2 - y1) + x1)) {
            intersections++;
        }
    }
    
    return (intersections % 2) == 1; // Odd number = inside
}

void generateGPSTimestamp() {
    unsigned long currentTime = millis() / 1000;
    char timeBuffer[32];
    sprintf(timeBuffer, "2025-06-22 %02d:%02d:%02d", 
            (int)((currentTime / 3600) % 24), 
            (int)((currentTime / 60) % 60), 
            (int)(currentTime % 60));
    gpsTimestamp = String(timeBuffer);
}

void readSensors() {
    // Read DHT22 sensor
    humidity = dht.readHumidity();
    temperature = dht.readTemperature();
    
    // Check if DHT22 readings are valid
    if (isnan(humidity) || isnan(temperature)) {
        Serial.println("Failed to read from DHT22 sensor!");
        if (isnan(humidity)) humidity = -1;
        if (isnan(temperature)) temperature = -999;
    }
    
    // Read photoresistor (light sensor)
    int rawLight = analogRead(PHOTORESISTOR_PIN);
    lightLevel = map(rawLight, 0, 4095, 0, 100);
    
    // Read potentiometer
    int rawPot = analogRead(POTENTIOMETER_PIN);
    potValue = map(rawPot, 0, 4095, 0, 100);
    
    // Read WiFi signal strength
    wifiSignal = WiFi.RSSI();
    
    // Simulate battery drain and recharge
    batteryLevel = max(10.0, batteryLevel - 0.01);
    if (batteryLevel <= 10.0) batteryLevel = 100.0;
    
    // Apply calibration offsets
    temperature += tempOffset;
    humidity += humOffset;
    
    // Print sensor readings to Serial Monitor
    Serial.println("=== Sensor Readings ===");
    Serial.println("Temperature: " + String(temperature) + "°C");
    Serial.println("Humidity: " + String(humidity) + "%");
    Serial.println("Light Level: " + String(lightLevel) + "%");
    Serial.println("Potentiometer: " + String(potValue) + "%");
    Serial.println("WiFi Signal: " + String(wifiSignal) + " dBm");
    Serial.println("========================");
}

void updateLCD() {
    lcd.clear();
    
    if (gpsValid) {
        // Show GPS coordinates and geofence status on LCD
        lcd.setCursor(0, 0);
        lcd.print("GPS: " + String(latitude, 4));
        lcd.setCursor(0, 1);
        String mode = generateInsideGeofence ? "IN" : "OUT";
        lcd.print(mode + ": " + String(longitude, 4));
    } else {
        // Show sensor data when GPS not available
        lcd.setCursor(0, 0);
        if (temperature != -999 && humidity != -1) {
            lcd.print("T:" + String(temperature, 1) + "C H:" + String(humidity, 1) + "%");
        } else {
            lcd.print("DHT22 Error!");
        }
        
        lcd.setCursor(0, 1);
        lcd.print("L:" + String(lightLevel) + "% P:" + String(potValue) + "%");
    }
}

void publishSensorData() {
    DynamicJsonDocument doc(4096);
    
    // Device info
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["timestamp"] = gpsTimestamp;
    
    // Create sensors array
    JsonArray sensors = doc.createNestedArray("sensors");
    
    // GPS Latitude
    JsonObject gpsLat = sensors.createNestedObject();
    gpsLat["sensor_name"] = "GPS Latitude";
    gpsLat["sensor_type"] = "gps_latitude";
    gpsLat["value"] = latitude;
    gpsLat["unit"] = "degrees";
    gpsLat["accuracy"] = 95;
    gpsLat["location"] = "Device";
    gpsLat["enabled"] = true;
    gpsLat["reading_timestamp"] = gpsTimestamp;
    
    // GPS Longitude
    JsonObject gpsLng = sensors.createNestedObject();
    gpsLng["sensor_name"] = "GPS Longitude";
    gpsLng["sensor_type"] = "gps_longitude";
    gpsLng["value"] = longitude;
    gpsLng["unit"] = "degrees";
    gpsLng["accuracy"] = 95;
    gpsLng["location"] = "Device";
    gpsLng["enabled"] = true;
    gpsLng["reading_timestamp"] = gpsTimestamp;
    
    // GPS Altitude
    JsonObject gpsAlt = sensors.createNestedObject();
    gpsAlt["sensor_name"] = "GPS Altitude";
    gpsAlt["sensor_type"] = "gps_altitude";
    gpsAlt["value"] = altitude;
    gpsAlt["unit"] = "meters";
    gpsAlt["accuracy"] = 90;
    gpsAlt["location"] = "Device";
    gpsAlt["enabled"] = true;
    gpsAlt["reading_timestamp"] = gpsTimestamp;
    
    // Temperature
    JsonObject temp = sensors.createNestedObject();
    temp["sensor_name"] = "Temperature";
    temp["sensor_type"] = "temperature";
    temp["value"] = temperature;
    temp["unit"] = "°C";
    temp["accuracy"] = 98;
    temp["location"] = "Device";
    temp["enabled"] = true;
    temp["reading_timestamp"] = gpsTimestamp;
    
    // Humidity
    JsonObject hum = sensors.createNestedObject();
    hum["sensor_name"] = "Humidity";
    hum["sensor_type"] = "humidity";
    hum["value"] = humidity;
    hum["unit"] = "%";
    hum["accuracy"] = 95;
    hum["location"] = "Device";
    hum["enabled"] = true;
    hum["reading_timestamp"] = gpsTimestamp;
    
    // Serialize and send
    String jsonString;
    serializeJson(doc, jsonString);
    
    String dataTopic = "devices/" + device_id + "/data";
    if (client.publish(dataTopic, jsonString, false, 1)) {
        Serial.println("✓ Sensor data sent successfully");
        Serial.println("Mode: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE") + " Xorafi 1");
    } else {
        Serial.println("✗ Failed to send sensor data");
    }
}

void publishGPSData() {
    if (!gpsValid) return;
    
    DynamicJsonDocument doc(2048);
    
    doc["device_id"] = device_id;
    doc["timestamp"] = gpsTimestamp;
    doc["simulated"] = useSimulatedGPS;
    doc["geofence_mode"] = generateInsideGeofence ? "inside" : "outside";
    
    JsonObject location = doc.createNestedObject("location");
    location["latitude"] = latitude;
    location["longitude"] = longitude;
    location["altitude"] = altitude;
    location["speed_kmh"] = speed_kmh;
    location["satellites"] = satellites;
    location["valid"] = gpsValid;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String gpsTopic = "devices/" + device_id + "/gps";
    if (client.publish(gpsTopic, jsonString, false, 1)) {
        String gpsType = useSimulatedGPS ? "SIMULATED" : "REAL";
        String mode = generateInsideGeofence ? "INSIDE" : "OUTSIDE";
        Serial.println("✓ " + gpsType + " GPS data published (" + mode + ") - Lat:" + String(latitude, 6) + " Lng:" + String(longitude, 6));
    } else {
        Serial.println("✗ Failed to send GPS data");
    }
}

void publishDeviceStatus(String status) {
    DynamicJsonDocument doc(512);
    
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["device_type"] = device_type;
    doc["status"] = status;
    doc["enabled"] = true;
    doc["last_seen"] = gpsTimestamp;
    doc["wifi_signal"] = WiFi.RSSI();
    doc["free_memory"] = ESP.getFreeHeap();
    doc["uptime"] = millis() / 1000;
    
    // Add geofence testing info
    doc["geofence_test_mode"] = testGeofencing;
    doc["current_mode"] = generateInsideGeofence ? "inside" : "outside";
    doc["gps_simulated"] = useSimulatedGPS;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String statusTopic = "devices/" + device_id + "/status";
    if (client.publish(statusTopic, jsonString, true, 1)) {
        Serial.println("✓ Status update sent (" + status + ")");
    } else {
        Serial.println("✗ Failed to send status update");
    }
}

void publishDeviceDiscovery() {
    DynamicJsonDocument doc(4096);
    
    // Device Information
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["device_type"] = device_type;
    doc["firmware_version"] = firmware_version;
    doc["mac_address"] = WiFi.macAddress();
    doc["ip_address"] = WiFi.localIP().toString();
    doc["geofence_testing"] = testGeofencing;
    
    // Sensor Array
    JsonArray sensors = doc.createNestedArray("available_sensors");
    
    // Temperature Sensor
    JsonObject tempSensor = sensors.createNestedObject();
    tempSensor["sensor_type"] = "temperature";
    tempSensor["sensor_name"] = "DHT22 Temperature";
    tempSensor["unit"] = "celsius";
    tempSensor["value"] = round(temperature * 10) / 10.0;

    // Humidity Sensor
    JsonObject humSensor = sensors.createNestedObject();
    humSensor["sensor_type"] = "humidity";
    humSensor["sensor_name"] = "DHT22 Humidity";
    humSensor["unit"] = "percent";
    humSensor["value"] = round(humidity * 10) / 10.0;

    // GPS Sensor
    JsonObject gpsSensor = sensors.createNestedObject();
    gpsSensor["sensor_type"] = "gps";
    gpsSensor["sensor_name"] = "Geofence Testing GPS";
    gpsSensor["unit"] = "coordinates";
    gpsSensor["latitude"] = latitude;
    gpsSensor["longitude"] = longitude;
    gpsSensor["valid"] = gpsValid;
    gpsSensor["simulated"] = useSimulatedGPS;
    gpsSensor["geofence_mode"] = generateInsideGeofence ? "inside" : "outside";
    
    String jsonString;
    serializeJsonPretty(doc, jsonString);
    
    String discoveryTopic = "devices/" + device_id + "/discovery/response";
    client.publish(discoveryTopic, jsonString, false, 1);
    
    Serial.println("=== DEVICE DISCOVERY PUBLISHED ===");
    Serial.println("Geofence Mode: " + String(generateInsideGeofence ? "INSIDE" : "OUTSIDE"));
}

void publishControlResponse(String control, String value) {
    DynamicJsonDocument doc(256);
    
    doc["device_id"] = device_id;
    doc["control"] = control;
    doc["value"] = value;
    doc["timestamp"] = millis() / 1000;
    doc["status"] = "executed";
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String responseTopic = "devices/" + device_id + "/control/response";
    client.publish(responseTopic, jsonString);
    
    Serial.println("Control response: " + control + " = " + value);
}

void handleCalibrationUpdate(String payload) {
    DynamicJsonDocument doc(512);
    deserializeJson(doc, payload);
    
    if (doc.containsKey("temperature_offset")) {
        tempOffset = doc["temperature_offset"];
        Serial.println("Temperature offset updated: " + String(tempOffset));
    }
    
    if (doc.containsKey("humidity_offset")) {
        humOffset = doc["humidity_offset"];
        Serial.println("Humidity offset updated: " + String(humOffset));
    }
    
    publishControlResponse("calibration", "updated");
}

void handleSensorConfig(String payload) {
    Serial.println("Sensor configuration update: " + payload);
    publishControlResponse("sensor_config", "updated");
}
