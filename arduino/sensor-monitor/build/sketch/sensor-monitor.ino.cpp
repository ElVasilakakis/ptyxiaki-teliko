#include <Arduino.h>
#line 1 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
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
float batteryLevel = 100.0; // Simulated battery

// GPS variables
double latitude = 0.0;
double longitude = 0.0;
double altitude = 0.0;
double speed_kmh = 0.0;
int satellites = 0;
bool gpsValid = false;
String gpsTimestamp = "";

// Random GPS simulation variables
const double BASE_LATITUDE = 37.7749;   // San Francisco base coordinates
const double BASE_LONGITUDE = -122.4194;
const double RADIUS_METERS = 1000.0;    // 1000 meter radius
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
void generateRandomGPSLocation();
void publishSensorData();
void publishGPSData();
void publishDeviceStatus(String status = "online");
void publishControlResponse(String control, String value);
void handleCalibrationUpdate(String payload);
void handleSensorConfig(String payload);
void updateLCD();

#line 92 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void setup();
#line 145 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void loop();
#line 558 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void publishDeviceStatus(String status);
#line 92 "C:\\Users\\tsigk\\Desktop\\Ptyxiaki\\ptyxiaki-final\\ptyxiaki-final\\arduino\\sensor-monitor\\sensor-monitor.ino"
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("=== ESP32 MQTT Environmental Monitor with GPS ===");
    Serial.println("Device ID: " + device_id);
    Serial.println("Firmware: " + firmware_version);
    Serial.println("==============================================");
    
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
    
    Serial.println("=== Setup Complete ===");
    digitalWrite(GREEN_LED_PIN, HIGH);
    
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("System Ready");
    delay(2000);
    
    // Enable simulated GPS after 30 seconds
    Serial.println("GPS simulation will start in 30 seconds...");
}

void loop() {
    client.loop();
    delay(10);
    
    if (!client.connected()) {
        Serial.println("MQTT disconnected, reconnecting...");
        connect();
    }
    
    // Read GPS data continuously
    readGPSData();
    
    // Publish sensor data every 10 seconds
    if (millis() - lastMillis > 10000) {
        lastMillis = millis();
        readSensors();
        publishSensorData();
        publishDeviceStatus(); 
    }
    
    // Publish GPS data every 15 seconds (if valid)
    if (millis() - lastGpsUpdate > 15000 && gpsValid) {
        lastGpsUpdate = millis();
        publishGPSData();
    }
    
    // Update LCD every 2 seconds
    if (millis() - lastLcdUpdate > 2000) {
        lastLcdUpdate = millis();
        updateLCD();
    }
    
    // Auto-republish discovery every 5 minutes
    if (millis() - lastDiscovery > 300000) {
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

void generateRandomGPSLocation() {
    // Generate random point within 1000m radius circle
    // Using uniform distribution within circle
    
    double u = random(0, 1000001) / 1000000.0; // Random between 0 and 1
    double v = random(0, 1000001) / 1000000.0; // Random between 0 and 1
    
    // Convert radius to degrees (approximately 111,300 meters per degree)
    double radiusInDegrees = RADIUS_METERS / 111300.0;
    
    // Generate uniform distribution within circle
    double w = radiusInDegrees * sqrt(u);
    double t = 2 * PI * v;
    
    // Calculate offset coordinates
    double x = w * cos(t);
    double y = w * sin(t);
    
    // Adjust for longitude compression at latitude
    double adjustedX = x / cos(BASE_LATITUDE * PI / 180.0);
    
    // Set new coordinates
    latitude = BASE_LATITUDE + y;
    longitude = BASE_LONGITUDE + adjustedX;
    
    // Simulate other GPS data
    altitude = 50.0 + random(-20, 21); // 30-70 meters
    speed_kmh = random(0, 51) / 10.0;   // 0-5 km/h
    satellites = random(6, 13);         // 6-12 satellites
    gpsValid = true;
    
    // Generate timestamp
    unsigned long currentTime = millis() / 1000;
    char timeBuffer[32];
    sprintf(timeBuffer, "2025-06-21 %02d:%02d:%02d", 
            (int)((currentTime / 3600) % 24), 
            (int)((currentTime / 60) % 60), 
            (int)(currentTime % 60));
    gpsTimestamp = String(timeBuffer);
}

void readGPSData() {
    // Try to read real GPS data first
    bool realGpsData = false;
    while (gpsSerial.available() > 0) {
        if (gps.encode(gpsSerial.read())) {
            if (gps.location.isValid()) {
                latitude = gps.location.lat();
                longitude = gps.location.lng();
                gpsValid = true;
                realGpsData = true;
                useSimulatedGPS = false;
                
                // Get additional GPS data
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
                Serial.println("Altitude: " + String(altitude, 2) + " m");
                Serial.println("Speed: " + String(speed_kmh, 2) + " km/h");
                Serial.println("Satellites: " + String(satellites));
                Serial.println("Timestamp: " + gpsTimestamp);
                Serial.println("====================");
                return;
            }
        }
    }
    
    // If no real GPS data and enough time has passed, use simulated GPS
    if (!realGpsData && millis() > 30000) { // Start simulation after 30 seconds
        if (!useSimulatedGPS) {
            Serial.println("=== Starting GPS Simulation ===");
            Serial.println("Generating random locations within 1000m radius");
            Serial.println("Base location: San Francisco (" + String(BASE_LATITUDE, 6) + ", " + String(BASE_LONGITUDE, 6) + ")");
            Serial.println("===============================");
            useSimulatedGPS = true;
            generateRandomGPSLocation();
            lastLocationChange = millis();
        } else {
            // Change location every 60 seconds
            if (millis() - lastLocationChange > 60000) {
                generateRandomGPSLocation();
                lastLocationChange = millis();
                
                Serial.println("=== SIMULATED GPS Data ===");
                Serial.println("Latitude: " + String(latitude, 6));
                Serial.println("Longitude: " + String(longitude, 6));
                Serial.println("Altitude: " + String(altitude, 2) + " m");
                Serial.println("Speed: " + String(speed_kmh, 2) + " km/h");
                Serial.println("Satellites: " + String(satellites));
                Serial.println("Timestamp: " + gpsTimestamp);
                Serial.println("Distance from base: ~" + String(random(100, 1001)) + "m");
                Serial.println("===========================");
            }
        }
    } else if (!realGpsData) {
        // Still waiting for GPS or simulation to start
        static unsigned long lastGpsWarning = 0;
        if (millis() - lastGpsWarning > 10000) { // Show warning every 10 seconds
            Serial.println("GPS: Waiting for satellite fix or starting simulation...");
            lastGpsWarning = millis();
        }
        gpsValid = false;
    }
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
        // Show GPS coordinates on LCD when available
        lcd.setCursor(0, 0);
        lcd.print("GPS: " + String(latitude, 4));
        lcd.setCursor(0, 1);
        if (useSimulatedGPS) {
            lcd.print("SIM: " + String(longitude, 4));
        } else {
            lcd.print("     " + String(longitude, 4));
        }
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

void publishDeviceDiscovery() {
    DynamicJsonDocument doc(4096);
    
    // Device Information
    doc["device_id"] = device_id;
    doc["device_name"] = device_name;
    doc["device_type"] = device_type;
    doc["firmware_version"] = firmware_version;
    doc["mac_address"] = WiFi.macAddress();
    doc["ip_address"] = WiFi.localIP().toString();
    
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

    // Light Sensor
    JsonObject lightSensor = sensors.createNestedObject();
    lightSensor["sensor_type"] = "light";
    lightSensor["sensor_name"] = "Photoresistor Light Level";
    lightSensor["unit"] = "percent";
    lightSensor["value"] = lightLevel;
    
    // Potentiometer
    JsonObject potSensor = sensors.createNestedObject();
    potSensor["sensor_type"] = "potentiometer";
    potSensor["sensor_name"] = "Potentiometer Value";
    potSensor["unit"] = "percent";
    potSensor["value"] = potValue;
    
    // WiFi Signal Sensor
    JsonObject wifiSensor = sensors.createNestedObject();
    wifiSensor["sensor_type"] = "wifi_signal";
    wifiSensor["sensor_name"] = "WiFi Signal Strength";
    wifiSensor["unit"] = "dBm";
    wifiSensor["value"] = wifiSignal;
    
    // Battery Level Sensor
    JsonObject batterySensor = sensors.createNestedObject();
    batterySensor["sensor_type"] = "battery";
    batterySensor["sensor_name"] = "Battery Level";
    batterySensor["unit"] = "percent";
    batterySensor["value"] = round(batteryLevel * 10) / 10.0;

    // GPS Sensor
    JsonObject gpsSensor = sensors.createNestedObject();
    gpsSensor["sensor_type"] = "gps";
    gpsSensor["sensor_name"] = useSimulatedGPS ? "Simulated GPS Module" : "NEO-6M GPS Module";
    gpsSensor["unit"] = "coordinates";
    gpsSensor["latitude"] = latitude;
    gpsSensor["longitude"] = longitude;
    gpsSensor["valid"] = gpsValid;
    gpsSensor["simulated"] = useSimulatedGPS;

    String jsonString;
    serializeJsonPretty(doc, jsonString);
    
    String discoveryTopic = "devices/" + device_id + "/discovery/response";
    client.publish(discoveryTopic, jsonString, false, 1);
    
    Serial.println("=== DEVICE DISCOVERY PUBLISHED ===");
    Serial.println(jsonString);
}

void publishSensorData() {
    DynamicJsonDocument doc(4096);
    
    doc["device_id"] = device_id;
    
    JsonObject sensors = doc.createNestedObject("sensors");
    sensors["temperature"] = round(temperature * 10) / 10.0;
    sensors["humidity"] = round(humidity * 10) / 10.0;
    sensors["light"] = lightLevel;
    sensors["potentiometer"] = potValue;
    sensors["wifi_signal"] = wifiSignal;
    sensors["battery"] = round(batteryLevel * 10) / 10.0;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String dataTopic = "devices/" + device_id + "/data";
    client.publish(dataTopic, jsonString, false, 1);
    
    Serial.println("Sensor data published - T:" + String(temperature) + "°C H:" + String(humidity) + "% L:" + String(lightLevel) + "% P:" + String(potValue) + "%");
}

void publishGPSData() {
    if (!gpsValid) return;
    
    DynamicJsonDocument doc(2048);
    
    doc["device_id"] = device_id;
    doc["timestamp"] = gpsTimestamp;
    doc["simulated"] = useSimulatedGPS;
    
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
    client.publish(gpsTopic, jsonString, false, 1);
    
    String gpsType = useSimulatedGPS ? "SIMULATED" : "REAL";
    Serial.println(gpsType + " GPS data published - Lat:" + String(latitude, 6) + " Lng:" + String(longitude, 6) + " Alt:" + String(altitude, 2) + "m");
}

void publishDeviceStatus(String status) {
    DynamicJsonDocument doc(512);
    
    doc["device_id"] = device_id;
    doc["status"] = status;
    doc["timestamp"] = millis() / 1000;
    doc["gps_status"] = gpsValid ? "active" : "searching";
    doc["gps_simulated"] = useSimulatedGPS;
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    String statusTopic = "devices/" + device_id + "/status";
    client.publish(statusTopic, jsonString, true, 1);
    
    Serial.println("Status published: " + status);
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

