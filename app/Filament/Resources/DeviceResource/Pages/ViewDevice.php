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

                // Sensors Information
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
