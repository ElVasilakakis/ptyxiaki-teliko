<?php
// app/Filament/Pages/MqttDocumentation.php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Tabs;
use Filament\Support\Enums\FontWeight;

class MqttDocumentation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    protected static string $view = 'filament.pages.mqtt-documentation';
    
    protected static ?string $navigationLabel = 'MQTT Documentation';
    
    protected static ?string $title = 'ESP32 MQTT Documentation';
    
    protected static ?string $navigationGroup = 'ESP32 Device';
    
    protected static ?int $navigationSort = 1;

    public function getSubscriptionsInfolist(): Infolist
    {
        return Infolist::make()
            ->schema([
                Infolists\Components\Section::make('Control Subscriptions')
                    ->description('Topics the ESP32 device subscribes to for receiving commands')
                    ->schema([
                        Infolists\Components\Grid::make(1)
                            ->schema([
                                Infolists\Components\TextEntry::make('control_wildcard')
                                    ->label('Control Commands (Wildcard)')
                                    ->state('devices/{device_id}/control/#')
                                    ->badge()
                                    ->color('info')
                                    ->fontFamily('mono'),
                                    
                                Infolists\Components\TextEntry::make('control_description')
                                    ->label('Description')
                                    ->state('Wildcard subscription for all control commands including LED control and geofence testing')
                                    ->color('gray'),
                                    
                                Infolists\Components\KeyValueEntry::make('control_commands')
                                    ->label('Specific Commands')
                                    ->keyLabel('Topic')
                                    ->valueLabel('Function')
                                    ->state([
                                        'devices/{device_id}/control/green_led' => 'Controls green LED (0/1)',
                                        'devices/{device_id}/control/blue_led' => 'Controls blue LED (0/1)',
                                        'devices/{device_id}/control/toggle_geofence' => 'Toggles geofence testing mode'
                                    ]),
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('Configuration Subscriptions')
                    ->schema([
                        Infolists\Components\Grid::make(1)
                            ->schema([
                                Infolists\Components\TextEntry::make('config_wildcard')
                                    ->label('Configuration Commands (Wildcard)')
                                    ->state('devices/{device_id}/config/#')
                                    ->badge()
                                    ->color('info')
                                    ->fontFamily('mono'),
                                    
                                Infolists\Components\KeyValueEntry::make('config_commands')
                                    ->label('Configuration Topics')
                                    ->keyLabel('Topic')
                                    ->valueLabel('Function')
                                    ->state([
                                        'devices/{device_id}/config/calibration' => 'Sensor calibration updates',
                                        'devices/{device_id}/config/sensors' => 'Sensor configuration updates'
                                    ]),
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('Discovery Subscriptions')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('discovery_topics')
                            ->label('Discovery Topics')
                            ->keyLabel('Topic')
                            ->valueLabel('Function')
                            ->state([
                                'devices/{device_id}/discover' => 'Device-specific discovery requests',
                                'devices/discover/all' => 'Global discovery requests for all devices'
                            ]),
                    ]),
            ]);
    }

    public function getPublicationsInfolist(): Infolist
    {
        return Infolist::make()
            ->schema([
                Infolists\Components\Section::make('Sensor Data Publication')
                    ->description('Main sensor data stream published every 10 seconds')
                    ->schema([
                        Infolists\Components\TextEntry::make('data_topic')
                            ->label('Topic')
                            ->state('devices/{device_id}/data')
                            ->badge()
                            ->color('success')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('data_frequency')
                                    ->label('Frequency')
                                    ->state('Every 10 seconds')
                                    ->icon('heroicon-o-clock'),
                                    
                                Infolists\Components\TextEntry::make('data_qos')
                                    ->label('QoS Level')
                                    ->state('QoS 1')
                                    ->badge()
                                    ->color('warning'),
                                    
                                Infolists\Components\IconEntry::make('data_retained')
                                    ->label('Retained')
                                    ->state(false)
                                    ->boolean(),
                            ]),
                            
                        Infolists\Components\TextEntry::make('data_payload')
                            ->label('Payload Structure')
                            ->state('
{
  "device_id": "ESP32-DEV-001",
  "device_name": "Environmental Sensor Monitor with GPS",
  "timestamp": "2025-06-22 HH:MM:SS",
  "sensors": [
    {
      "sensor_name": "GPS Latitude",
      "sensor_type": "gps_latitude",
      "value": 39.512345,
      "unit": "degrees",
      "accuracy": 95,
      "location": "Device",
      "enabled": true,
      "reading_timestamp": "2025-06-22 HH:MM:SS"
    },
    {
      "sensor_name": "Temperature",
      "sensor_type": "temperature", 
      "value": 23.5,
      "unit": "°C",
      "accuracy": 98
    },
    {
      "sensor_name": "Humidity",
      "sensor_type": "humidity",
      "value": 65.2,
      "unit": "%",
      "accuracy": 95
    }
  ]
}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
                    
                Infolists\Components\Section::make('GPS Data Publication')
                    ->description('Dedicated GPS data stream with geofence testing')
                    ->schema([
                        Infolists\Components\TextEntry::make('gps_topic')
                            ->label('Topic')
                            ->state('devices/{device_id}/gps')
                            ->badge()
                            ->color('success')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('gps_frequency')
                                    ->label('Frequency')
                                    ->state('Every 15 seconds (when GPS valid)')
                                    ->icon('heroicon-o-clock'),
                                    
                                Infolists\Components\TextEntry::make('gps_special')
                                    ->label('Special Feature')
                                    ->state('Geofence Testing Mode')
                                    ->badge()
                                    ->color('purple'),
                            ]),
                            
                        Infolists\Components\TextEntry::make('gps_payload')
                            ->label('Payload Structure')
                            ->state('
{
  "device_id": "ESP32-DEV-001",
  "timestamp": "2025-06-22 HH:MM:SS",
  "simulated": true,
  "geofence_mode": "inside",
  "location": {
    "latitude": 39.512345,
    "longitude": -107.699060,
    "altitude": 2500.0,
    "speed_kmh": 1.5,
    "satellites": 12,
    "valid": true
  }
}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
                    
                Infolists\Components\Section::make('Device Status Publication')
                    ->description('Device health and status information')
                    ->schema([
                        Infolists\Components\TextEntry::make('status_topic')
                            ->label('Topic')
                            ->state('devices/{device_id}/status')
                            ->badge()
                            ->color('success')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('status_frequency')
                                    ->label('Frequency')
                                    ->state('Every 10 seconds + events')
                                    ->icon('heroicon-o-clock'),
                                    
                                Infolists\Components\IconEntry::make('status_retained')
                                    ->label('Retained')
                                    ->state(true)
                                    ->boolean(),
                                    
                                Infolists\Components\TextEntry::make('status_purpose')
                                    ->label('Purpose')
                                    ->state('Last-will functionality')
                                    ->badge()
                                    ->color('info'),
                            ]),
                            
                        Infolists\Components\TextEntry::make('status_payload')
                            ->label('Payload Structure')
                            ->state('
{
  "device_id": "ESP32-DEV-001",
  "device_name": "Environmental Sensor Monitor with GPS",
  "device_type": "ESP32_ENVIRONMENTAL_GPS",
  "status": "online",
  "enabled": true,
  "last_seen": "2025-06-22 HH:MM:SS",
  "wifi_signal": -45,
  "free_memory": 234567,
  "uptime": 12345,
  "geofence_test_mode": true,
  "current_mode": "inside",
  "gps_simulated": true
}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
                    
                Infolists\Components\Section::make('Discovery Response Publication')
                    ->description('Device capabilities and auto-discovery information')
                    ->schema([
                        Infolists\Components\TextEntry::make('discovery_topic')
                            ->label('Topic')
                            ->state('devices/{device_id}/discovery/response')
                            ->badge()
                            ->color('success')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\TextEntry::make('discovery_frequency')
                            ->label('Frequency')
                            ->state('Every 5 minutes + on discovery requests')
                            ->icon('heroicon-o-clock'),
                            
                        Infolists\Components\TextEntry::make('discovery_payload')
                            ->label('Payload Structure')
                            ->state('
{
  "device_id": "ESP32-DEV-001",
  "device_name": "Environmental Sensor Monitor with GPS",
  "device_type": "ESP32_ENVIRONMENTAL_GPS",
  "firmware_version": "1.3.0",
  "mac_address": "XX:XX:XX:XX:XX:XX",
  "ip_address": "192.168.1.100",
  "geofence_testing": true,
  "available_sensors": [
    {
      "sensor_type": "temperature",
      "sensor_name": "DHT22 Temperature",
      "unit": "celsius",
      "value": 23.5
    },
    {
      "sensor_type": "gps",
      "sensor_name": "Geofence Testing GPS",
      "unit": "coordinates",
      "simulated": true,
      "geofence_mode": "inside"
    }
  ]
}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
                    
                Infolists\Components\Section::make('Control Response Publication')
                    ->description('Confirmation of executed control commands')
                    ->schema([
                        Infolists\Components\TextEntry::make('control_response_topic')
                            ->label('Topic')
                            ->state('devices/{device_id}/control/response')
                            ->badge()
                            ->color('success')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\TextEntry::make('control_response_payload')
                            ->label('Payload Structure')
                            ->state('
{
  "device_id": "ESP32-DEV-001",
  "control": "green_led",
  "value": "on",
  "timestamp": 1234567890,
  "status": "executed"
}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
            ]);
    }

    public function getGeofenceInfolist(): Infolist
    {
        return Infolist::make()
            ->schema([
                Infolists\Components\Section::make('Geofence Testing Overview')
                    ->description('This ESP32 device includes special geofence testing capabilities')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('geofence_mode')
                                    ->label('Testing Mode')
                                    ->state('Automatic alternating between INSIDE and OUTSIDE')
                                    ->badge()
                                    ->color('purple'),
                                    
                                Infolists\Components\TextEntry::make('geofence_interval')
                                    ->label('Toggle Interval')
                                    ->state('Every 2 minutes')
                                    ->icon('heroicon-o-clock'),
                            ]),
                            
                        Infolists\Components\TextEntry::make('geofence_polygon')
                            ->label('Test Polygon: Xorafi 1 (Colorado)')
                            ->state('Rectangular geofence in Colorado with coordinates:
• Min Latitude: 39.495387
• Max Latitude: 39.529577  
• Min Longitude: -107.744122
• Max Longitude: -107.653999')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\KeyValueEntry::make('geofence_locations')
                            ->label('Outside Test Locations')
                            ->keyLabel('Location')
                            ->valueLabel('Purpose')
                            ->state([
                                'San Francisco, CA' => 'West coast testing',
                                'Denver, CO' => 'Close but outside geofence',
                                'New York, NY' => 'East coast testing',
                                'Los Angeles, CA' => 'Alternative west coast'
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('Manual Control')
                    ->schema([
                        Infolists\Components\TextEntry::make('manual_control')
                            ->label('Manual Toggle Command')
                            ->state('devices/{device_id}/control/toggle_geofence')
                            ->badge()
                            ->color('warning')
                            ->fontFamily('mono'),
                            
                        Infolists\Components\TextEntry::make('manual_description')
                            ->label('Description')
                            ->state('Send any payload to this topic to manually toggle between INSIDE and OUTSIDE modes. The device will respond with the new mode and generate new GPS coordinates immediately.'),
                    ]),
            ]);
    }

    public function getOverviewInfolist(): Infolist
    {
        return Infolist::make()
            ->schema([
                Infolists\Components\Section::make('Device Architecture')
                    ->description('ESP32 Environmental Sensor Monitor with GPS and Geofence Testing')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('device_type')
                                    ->label('Device Type')
                                    ->state('ESP32_ENVIRONMENTAL_GPS')
                                    ->badge()
                                    ->color('primary'),
                                    
                                Infolists\Components\TextEntry::make('firmware_version')
                                    ->label('Firmware Version')
                                    ->state('v1.3.0')
                                    ->badge()
                                    ->color('success'),
                            ]),
                            
                        Infolists\Components\KeyValueEntry::make('hardware_specs')
                            ->label('Hardware Components')
                            ->keyLabel('Component')
                            ->valueLabel('Model/Type')
                            ->state([
                                'Microcontroller' => 'ESP32 Development Board',
                                'Temperature/Humidity' => 'DHT22 Sensor',
                                'GPS Module' => 'Simulated (Geofence Testing)',
                                'LEDs' => 'Green & Blue Control LEDs',
                                'WiFi' => 'Built-in ESP32 WiFi'
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('MQTT Communication Summary')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_subscriptions')
                                    ->label('Subscriptions')
                                    ->state('4 Topics')
                                    ->badge()
                                    ->color('info'),
                                    
                                Infolists\Components\TextEntry::make('total_publications')
                                    ->label('Publications')
                                    ->state('5 Topics')
                                    ->badge()
                                    ->color('success'),
                                    
                                Infolists\Components\TextEntry::make('data_frequency')
                                    ->label('Main Data Rate')
                                    ->state('Every 10 seconds')
                                    ->badge()
                                    ->color('warning'),
                            ]),
                            
                        Infolists\Components\KeyValueEntry::make('mqtt_topics_summary')
                            ->label('Topic Categories')
                            ->keyLabel('Category')
                            ->valueLabel('Description')
                            ->state([
                                'Control Topics' => 'LED control and geofence toggle commands',
                                'Configuration Topics' => 'Sensor calibration and device settings',
                                'Data Topics' => 'Sensor readings and GPS coordinates',
                                'Status Topics' => 'Device health and connectivity status',
                                'Discovery Topics' => 'Auto-discovery and capability reporting'
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('Special Features')
                    ->schema([
                        Infolists\Components\Grid::make(1)
                            ->schema([
                                Infolists\Components\TextEntry::make('geofence_feature')
                                    ->label('Geofence Testing Mode')
                                    ->state('Automatically generates GPS coordinates that alternate between INSIDE and OUTSIDE the Xorafi 1 polygon in Colorado every 2 minutes. Perfect for testing geofencing applications without physical movement.')
                                    ->color('purple'),
                                    
                                Infolists\Components\TextEntry::make('simulation_feature')
                                    ->label('GPS Simulation')
                                    ->state('Uses predefined coordinate sets for consistent testing. Inside coordinates are within the Xorafi 1 boundary, while outside coordinates include major US cities for comprehensive testing scenarios.')
                                    ->color('info'),
                                    
                                Infolists\Components\TextEntry::make('remote_control')
                                    ->label('Remote Control Capabilities')
                                    ->state('Supports remote LED control and manual geofence mode toggling via MQTT commands. All control actions are confirmed with response messages for reliable operation.')
                                    ->color('success'),
                            ]),
                    ]),
                    
                Infolists\Components\Section::make('Data Flow Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('data_flow')
                            ->label('Complete Data Pipeline')
                            ->state('
1. Sensor Reading (Every 10s): DHT22 → Temperature/Humidity data
2. GPS Generation (Every 15s): Geofence algorithm → Simulated coordinates  
3. Status Monitoring (Every 10s): Device health → WiFi, memory, uptime
4. Discovery Broadcasting (Every 5min): Device capabilities → Auto-discovery
5. Control Processing (On-demand): MQTT commands → LED/geofence control
6. Response Confirmation (Immediate): Command execution → Status feedback')
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
