<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // Device Basic Information
                Infolists\Components\Section::make('Device Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Device Name')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight('bold'),
                        Infolists\Components\TextEntry::make('device_unique_id')
                            ->label('Device ID')
                            ->copyable()
                            ->badge()
                            ->color('primary'),
                        Infolists\Components\TextEntry::make('device_type')
                            ->label('Device Type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'SENSOR_MONITOR' => 'success',
                                'WEATHER_STATION' => 'info',
                                'IRRIGATION_CONTROLLER' => 'warning',
                                'ESP32_DEVICE' => 'primary',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('land.land_name')
                            ->label('Land Location')
                            ->badge()
                            ->color('success'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'online' => 'success',
                                'offline' => 'danger',
                                'error' => 'warning',
                                'maintenance' => 'gray',
                                default => 'gray',
                            }),
                        Infolists\Components\IconEntry::make('enabled')
                            ->label('Enabled')
                            ->boolean(),
                    ])->columns(3),

                // Location Information
                Infolists\Components\Section::make('Location Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('latitude')
                            ->label('Device Latitude')
                            ->placeholder('Not set')
                            ->formatStateUsing(fn (?string $state): string => 
                                $state ? number_format((float)$state, 6) : 'Not available'
                            )
                            ->copyable(),
                        Infolists\Components\TextEntry::make('longitude')
                            ->label('Device Longitude')
                            ->placeholder('Not set')
                            ->formatStateUsing(fn (?string $state): string => 
                                $state ? number_format((float)$state, 6) : 'Not available'
                            )
                            ->copyable(),
                        Infolists\Components\TextEntry::make('altitude')
                            ->label('Altitude (m)')
                            ->placeholder('Not set')
                            ->suffix(' m'),
                        Infolists\Components\TextEntry::make('land.latitude')
                            ->label('Land Latitude')
                            ->placeholder('Not set')
                            ->formatStateUsing(fn (?string $state): string => 
                                $state ? number_format((float)$state, 6) : 'Not available'
                            )
                            ->copyable(),
                        Infolists\Components\TextEntry::make('land.longitude')
                            ->label('Land Longitude')
                            ->placeholder('Not set')
                            ->formatStateUsing(fn (?string $state): string => 
                                $state ? number_format((float)$state, 6) : 'Not available'
                            )
                            ->copyable(),
                        Infolists\Components\IconEntry::make('location_tracking_enabled')
                            ->label('GPS Tracking Enabled')
                            ->boolean(),
                    ])->columns(3),

                // Interactive Map
// Interactive Map
Infolists\Components\ViewEntry::make('location_map')
    ->view('filament.infolists.device-location-map')
    ->state(function (): array {
        $device = $this->record;
        $devices = collect();
        $lands = collect();
        
        // Check multiple possible sources for device location
        $deviceLat = null;
        $deviceLng = null;
        
        // Try direct model attributes first
        if ($device->latitude && $device->longitude) {
            $deviceLat = (float)$device->latitude;
            $deviceLng = (float)$device->longitude;
        }
        // Try sensor data if direct attributes don't exist
        elseif ($device->sensors && $device->sensors->isNotEmpty()) {
            foreach ($device->sensors as $sensor) {
                if ($sensor->sensor_type === 'GPS' || $sensor->sensor_type === 'LOCATION') {
                    $latestReading = $sensor->sensorReadings()
                        ->latest()
                        ->first();
                    
                    if ($latestReading && $latestReading->sensor_data) {
                        $sensorData = is_string($latestReading->sensor_data) 
                            ? json_decode($latestReading->sensor_data, true) 
                            : $latestReading->sensor_data;
                        
                        if (isset($sensorData['latitude']) && isset($sensorData['longitude'])) {
                            $deviceLat = (float)$sensorData['latitude'];
                            $deviceLng = (float)$sensorData['longitude'];
                            break;
                        }
                        // Also check for 'lat' and 'lng' keys
                        elseif (isset($sensorData['lat']) && isset($sensorData['lng'])) {
                            $deviceLat = (float)$sensorData['lat'];
                            $deviceLng = (float)$sensorData['lng'];
                            break;
                        }
                    }
                }
            }
        }
        // Try the latest sensor reading with GPS data
        elseif ($device->sensorReadings && $device->sensorReadings->isNotEmpty()) {
            $latestReading = $device->sensorReadings()
                ->whereHas('sensor', function($query) {
                    $query->where('sensor_type', 'GPS')
                          ->orWhere('sensor_type', 'LOCATION');
                })
                ->latest()
                ->first();
            
            if ($latestReading && $latestReading->sensor_data) {
                $sensorData = is_string($latestReading->sensor_data) 
                    ? json_decode($latestReading->sensor_data, true) 
                    : $latestReading->sensor_data;
                
                if (isset($sensorData['latitude']) && isset($sensorData['longitude'])) {
                    $deviceLat = (float)$sensorData['latitude'];
                    $deviceLng = (float)$sensorData['longitude'];
                }
            }
        }
        
        // Add device to collection if we found coordinates
        if ($deviceLat && $deviceLng) {
            $devices->push([
                'id' => $device->id,
                'name' => $device->name,
                'lat' => $deviceLat,
                'lng' => $deviceLng,
                'type' => $device->device_type,
                'status' => $device->status,
                'altitude' => $device->altitude ?? null,
            ]);
        }
        
        // Associated land location (unchanged)
        if ($device->land && $device->land->latitude && $device->land->longitude) {
            $lands->push([
                'id' => $device->land->id,
                'name' => $device->land->land_name,
                'lat' => (float)$device->land->latitude,
                'lng' => (float)$device->land->longitude,
                'boundary' => $device->land->boundary_coordinates ?? null,
                'area' => $device->land->area ?? null,
            ]);
        }
        
        // Determine center coordinates
        $centerLat = $deviceLat ?? $device->land?->latitude ?? 0;
        $centerLng = $deviceLng ?? $device->land?->longitude ?? 0;
        
        return [
            'devices' => $devices,
            'lands' => $lands,
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
            'has_location' => ($deviceLat && $deviceLng) || 
                            ($device->land && $device->land->latitude && $device->land->longitude),
            'mapId' => 'device-map-' . $device->id,
        ];
    })
    ->columnSpanFull(),


                // MQTT Topics
                Infolists\Components\Section::make('MQTT Topics')
                    ->schema([
                        Infolists\Components\TextEntry::make('mqtt_topics_config.discovery_request')
                            ->label('Discovery Request Topic')
                            ->placeholder('devices/{device_id}/discover')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.discovery_response')
                            ->label('Discovery Response Topic')
                            ->placeholder('devices/{device_id}/discovery/response')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.data')
                            ->label('Data Topic')
                            ->placeholder('devices/{device_id}/data')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.status')
                            ->label('Status Topic')
                            ->placeholder('devices/{device_id}/status')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.gps')
                            ->label('GPS Topic')
                            ->placeholder('devices/{device_id}/gps')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.commands')
                            ->label('Commands Topic')
                            ->placeholder('devices/{device_id}/commands')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.control_response')
                            ->label('Control Response Topic')
                            ->placeholder('devices/{device_id}/control/response')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('mqtt_topics_config.global_discovery')
                            ->label('Global Discovery Topic')
                            ->placeholder('devices/discover/all')
                            ->copyable(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                // Connected Sensors Information
                Infolists\Components\Section::make('Connected Sensors')
                    ->extraAttributes(['wire:poll.5s' => ''])
                    ->schema([
                        Infolists\Components\ViewEntry::make('sensors')
                            ->view('filament.infolists.sensors-table')
                            ->columnSpanFull(),
                    ]),

                // Device Notes
                Infolists\Components\Section::make('Additional Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('No notes available')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('request_gps_location')
                ->label('Request GPS Location')
                ->icon('heroicon-o-map-pin')
                ->color('info')
                ->action(function () {
                    try {
                        $deviceId = $this->record->device_unique_id;
                        $topics = $this->record->mqtt_topics_config ?? [];
                        $locationTopic = $topics['gps'] ?? "devices/{$deviceId}/gps";
                        
                        $payload = [
                            'command' => 'get_location',
                            'timestamp' => now()->toISOString(),
                            'device_id' => $deviceId,
                            'request_id' => uniqid('gps_', true)
                        ];
                        
                        $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                        $mqtt->publish($locationTopic, json_encode($payload));
                        
                        \Filament\Notifications\Notification::make()
                            ->title('GPS location requested')
                            ->body("Requested GPS location from {$this->record->name}")
                            ->success()
                            ->send();
                            
                        \Illuminate\Support\Facades\Log::channel('mqtt')->info("GPS location requested from admin panel", [
                            'device_id' => $deviceId,
                            'device_name' => $this->record->name,
                            'topic' => $locationTopic,
                            'payload' => $payload
                        ]);
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('GPS request failed')
                            ->body('Error: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('discover_device')
                ->label('Discover Device')
                ->icon('heroicon-o-magnifying-glass')
                ->color('success')
                ->action(function () {
                    try {
                        $deviceId = $this->record->device_unique_id;
                        $service = new \App\Services\MqttDeviceService();
                        
                        // Create a more detailed discovery payload
                        $payload = [
                            'action' => 'discover',
                            'timestamp' => time(),
                            'initiated_by' => auth()->user()?->email ?? 'admin_panel',
                            'request_id' => uniqid('disc_', true)
                        ];
                        
                        // Store current user context for discovery
                        \Illuminate\Support\Facades\Cache::put(
                            "mqtt_user_context", 
                            auth()->id() ?? 1, 
                            now()->addMinutes(5)
                        );
                        
                        // Log the discovery request
                        \Illuminate\Support\Facades\Log::channel('mqtt')->info("Device discovery requested from admin panel", [
                            'device_id' => $deviceId,
                            'device_name' => $this->record->name,
                            'user_id' => auth()->id(),
                            'user_email' => auth()->user()?->email,
                            'payload' => $payload
                        ]);
                        
                        // Send the discovery request
                        $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                        $mqtt->publish(
                            "devices/{$deviceId}/discover", 
                            json_encode($payload)
                        );
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Discovery request sent')
                            ->body("Device discovery request sent to {$this->record->name}")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Discovery failed')
                            ->body('Error: ' . $e->getMessage())
                            ->danger()
                            ->send();
                            
                        \Illuminate\Support\Facades\Log::channel('mqtt')->error("Device discovery failed from admin panel", [
                            'device_id' => $this->record->device_unique_id,
                            'device_name' => $this->record->name,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }),
            Actions\Action::make('send_command')
                ->label('Send Command')
                ->icon('heroicon-o-command-line')
                ->form([
                    \Filament\Forms\Components\Select::make('command')
                        ->options([
                            'led' => 'Toggle LED',
                            'reset' => 'Reset Device',
                            'config' => 'Update Config',
                            'calibrate' => 'Calibrate Sensors',
                        ])
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('value')
                        ->label('Command Value')
                        ->placeholder('Command parameter'),
                ])
                ->action(function (array $data) {
                    try {
                        $service = new \App\Services\MqttDeviceService();
                        $service->publishDeviceCommand(
                            $this->record->device_unique_id,
                            $data['command'],
                            ['value' => $data['value'] ?? '']
                        );
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Command sent')
                            ->body("Sent {$data['command']} command to {$this->record->name}")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Command failed')
                            ->body('Error: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
