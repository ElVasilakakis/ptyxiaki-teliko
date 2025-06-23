<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use App\Models\Land;
use App\Models\Sensor;
use App\Models\MqttBroker;
use App\Services\MqttDeviceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;
    protected static ?string $navigationIcon = 'heroicon-o-device-tablet';
    protected static ?string $navigationGroup = 'Farm Management';
    protected static ?int $navigationSort = 2;

    protected function getPollingInterval(): ?string
    {
        return '10s';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Device Configuration')
                    ->schema([
                        Forms\Components\Hidden::make('user_id')
                            ->default(fn () => auth()->id())    
                            ->required(),
                    
                        Forms\Components\Select::make('land_id')
                            ->options(Land::all()->pluck('land_name', 'id'))
                            ->required()
                            ->label('Land')
                            ->searchable(),
                        Forms\Components\TextInput::make('device_unique_id')
                            ->label('Device ID')
                            ->required()
                            ->maxLength(255)
                            ->unique(Device::class, 'device_unique_id', ignoreRecord: true),
                        Forms\Components\TextInput::make('name')
                            ->label('Device Name')
                            ->maxLength(255)
                            ->required(),
                        Forms\Components\Select::make('device_type')
                            ->options([
                                'SENSOR_MONITOR' => 'Sensor Monitor',
                                'WEATHER_STATION' => 'Weather Station',
                                'IRRIGATION_CONTROLLER' => 'Irrigation Controller',
                                'ESP32_DEVICE' => 'ESP32 Device',
                            ])
                            ->label('Device Type')
                            ->required(),
                        Forms\Components\TextInput::make('api_url')
                            ->label('MQTT Topic Prefix')
                            ->maxLength(255)
                            ->nullable()
                            ->placeholder('devices/{device_id}'),
                        Forms\Components\Toggle::make('enabled')
                            ->default(true),
                        Forms\Components\Textarea::make('notes')
                            ->maxLength(1000)
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('MQTT Configuration')
                    ->schema([
                        Forms\Components\Select::make('mqtt_broker_id')
                            ->label('MQTT Broker')
                            ->options(MqttBroker::active()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->placeholder('Use default broker')
                            ->helperText('Leave empty to use the default MQTT broker'),
                        
                        Forms\Components\KeyValue::make('mqtt_topics_config')
                            ->label('Custom MQTT Topics')
                            ->keyLabel('Topic Name')
                            ->valueLabel('Topic Path')
                            ->nullable()
                            ->helperText('Override default topics or add custom ones')
                            ->columnSpanFull(),
                    ])->columns(2)
                    ->collapsible(),
                    
                Forms\Components\Section::make('Device Status')
                    ->schema([
                        Forms\Components\TextInput::make('status')
                            ->disabled(),
                        Forms\Components\TextInput::make('health_percentage')
                            ->disabled()
                            ->suffix('%'),
                        Forms\Components\TextInput::make('last_seen_at')
                            ->disabled(),
                        Forms\Components\TextInput::make('wifi_rssi')
                            ->disabled()
                            ->suffix(' dBm'),
                        Forms\Components\TextInput::make('firmware_version')
                            ->disabled(),
                        Forms\Components\TextInput::make('mac_address')
                            ->disabled(),
                    ])->columns(3)
                    ->hiddenOn('create'),

                Forms\Components\Section::make('MQTT Information')
                    ->schema([
                        Forms\Components\Placeholder::make('mqtt_broker_info')
                            ->label('MQTT Broker')
                            ->content(function (?Device $record): string {
                                if (!$record) {
                                    return 'Device not loaded';
                                }
                                
                                return $record->effective_mqtt_broker 
                                    ? $record->effective_mqtt_broker->name . ' (' . $record->effective_mqtt_broker->connection_string . ')'
                                    : 'No broker assigned';
                            }),
                        Forms\Components\Placeholder::make('mqtt_client_id')
                            ->label('MQTT Client ID')
                            ->content(function (?Device $record): string {
                                if (!$record) {
                                    return 'Not available';
                                }
                                
                                return $record->mqtt_client_id ?? 'Not generated';
                            }),
                        Forms\Components\KeyValue::make('mqtt_topics_display')
                            ->label('MQTT Topics')
                            ->disabled()
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false)
                            ->editableValues(false)
                            ->default(function (?Device $record): array {
                                if (!$record) {
                                    return [];
                                }
                                
                                return $record->mqtt_topics ?? [];
                            })
                            ->columnSpanFull(),
                    ])->columns(2)
                    ->hiddenOn('create'),
                    
                Forms\Components\Section::make('Device Data')
                    ->schema([
                        Forms\Components\KeyValue::make('application_data')
                            ->label('Device Information')
                            ->disabled()
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false)
                            ->editableValues(false),
                    ])->columns(1)
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('device_unique_id')
                    ->label('Device ID')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('device_type')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'SENSOR_MONITOR' => 'success',
                        'WEATHER_STATION' => 'info',
                        'IRRIGATION_CONTROLLER' => 'warning',
                        'ESP32_DEVICE' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('land.land_name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('mqttBroker.name')
                    ->label('MQTT Broker')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('Default'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'online',
                        'danger' => 'offline',
                        'warning' => 'error',
                        'gray' => 'maintenance',
                    ]),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('health_percentage')
                    ->numeric()
                    ->sortable()
                    ->suffix('%')
                    ->color(fn ($state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 60 => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('sensors_count')
                    ->counts('sensors')
                    ->label('Sensors')
                    ->badge(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($record) => $record->last_seen_at?->format('Y-m-d H:i:s')),
                Tables\Columns\TextColumn::make('firmware_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('mac_address')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                        'error' => 'Error',
                        'maintenance' => 'Maintenance',
                    ]),
                Tables\Filters\SelectFilter::make('device_type')
                    ->options([
                        'SENSOR_MONITOR' => 'Sensor Monitor',
                        'WEATHER_STATION' => 'Weather Station',
                        'IRRIGATION_CONTROLLER' => 'Irrigation Controller',
                        'ESP32_DEVICE' => 'ESP32 Device',
                    ]),
                Tables\Filters\SelectFilter::make('land_id')
                    ->relationship('land', 'land_name')
                    ->label('Land'),
                Tables\Filters\SelectFilter::make('mqtt_broker_id')
                    ->relationship('mqttBroker', 'name')
                    ->label('MQTT Broker'),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->actions([
                Tables\Actions\Action::make('discover')
                    ->label('Discover Device')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function (Device $record) {
                        try {
                            cache()->put("mqtt_user_context", auth()->id(), now()->addMinutes(5));
                            
                            \Illuminate\Support\Facades\Log::channel('mqtt')->info("Manual device discovery initiated", [
                                'device_id' => $record->device_unique_id,
                                'user_id' => auth()->id(),
                                'from_filament' => true,
                                'mqtt_broker' => $record->effective_mqtt_broker?->name ?? 'default'
                            ]);
                            
                            $topics = $record->mqtt_topics;
                            $discoveryTopic = $topics['discovery_request'] ?? "devices/{$record->device_unique_id}/discover";
                            
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            $mqtt->publish($discoveryTopic, 'discover');
                            
                            Notification::make()
                                ->title('Discovery request sent')
                                ->body("Sent discovery request to device: {$record->device_unique_id}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::channel('mqtt')->error("Manual device discovery failed", [
                                'device_id' => $record->device_unique_id,
                                'error' => $e->getMessage()
                            ]);
                            
                            Notification::make()
                                ->title('Discovery failed')
                                ->body('Error: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('send_command')
                    ->label('Send Command')
                    ->icon('heroicon-o-command-line')
                    ->form([
                        Forms\Components\Select::make('command')
                            ->options([
                                'led' => 'Toggle LED',
                                'reset' => 'Reset Device',
                                'config' => 'Update Config',
                                'status' => 'Request Status',
                                'gps' => 'Request GPS Data',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('value')
                            ->label('Command Value')
                            ->placeholder('1 for LED on, 0 for LED off'),
                    ])
                    ->action(function (Device $record, array $data) {
                        try {
                            $topics = $record->mqtt_topics;
                            $commandTopic = $topics['commands'] ?? "devices/{$record->device_unique_id}/commands";
                            
                            $message = [
                                'command' => $data['command'],
                                'payload' => ['value' => $data['value'] ?? ''],
                                'timestamp' => now()->toISOString(),
                                'device_id' => $record->device_unique_id,
                            ];
                            
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            $mqtt->publish($commandTopic, json_encode($message));
                            
                            Notification::make()
                                ->title('Command sent')
                                ->body("Sent {$data['command']} command to {$record->name}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Command failed')
                                ->body('Error: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('assign_broker')
                    ->label('Assign MQTT Broker')
                    ->icon('heroicon-o-wifi')
                    ->form([
                        Forms\Components\Select::make('mqtt_broker_id')
                            ->label('MQTT Broker')
                            ->options(MqttBroker::active()->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (Device $record, array $data) {
                        try {
                            $broker = MqttBroker::find($data['mqtt_broker_id']);
                            $record->assignToMqttBroker($broker, $record->device_unique_id);
                            
                            Notification::make()
                                ->title('MQTT Broker assigned')
                                ->body("Device {$record->name} assigned to broker: {$broker->name}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Assignment failed')
                                ->body('Error: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('discover_all')
                    ->label('Discover All Devices')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function () {
                        try {
                            cache()->put("mqtt_user_context", auth()->id(), now()->addMinutes(5));
                            
                            \Illuminate\Support\Facades\Log::channel('mqtt')->info("Global device discovery initiated", [
                                'user_id' => auth()->id(),
                                'from_filament' => true
                            ]);
                            
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            $mqtt->publish('devices/discover/all', 'discover');
                            
                            Notification::make()
                                ->title('Global discovery request sent')
                                ->body('All connected devices will respond with their information')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::channel('mqtt')->error("Global device discovery failed", [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            
                            Notification::make()
                                ->title('Discovery failed')
                                ->body('Error: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('mqtt_status')
                    ->label('MQTT Status')
                    ->icon('heroicon-o-signal')
                    ->action(function () {
                        try {
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            
                            $activeBrokers = MqttBroker::active()->count();
                            $devicesWithBrokers = Device::whereNotNull('mqtt_broker_id')->count();
                            $totalDevices = Device::count();
                            
                            Notification::make()
                                ->title('MQTT Connection Active')
                                ->body("Active brokers: {$activeBrokers} | Devices with brokers: {$devicesWithBrokers}/{$totalDevices}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('MQTT Connection Failed')
                                ->body('Error: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\BulkAction::make('bulk_discover')
                        ->label('Discover Selected')
                        ->icon('heroicon-o-magnifying-glass')
                        ->action(function ($records) {
                            try {
                                $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                                
                                foreach ($records as $record) {
                                    $topics = $record->mqtt_topics;
                                    $discoveryTopic = $topics['discovery_request'] ?? "devices/{$record->device_unique_id}/discover";
                                    $mqtt->publish($discoveryTopic, 'discover');
                                }
                                
                                Notification::make()
                                    ->title('Discovery requests sent')
                                    ->body('Sent discovery requests to ' . count($records) . ' devices')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Bulk discovery failed')
                                    ->body('Error: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\BulkAction::make('bulk_assign_broker')
                        ->label('Assign MQTT Broker')
                        ->icon('heroicon-o-wifi')
                        ->form([
                            Forms\Components\Select::make('mqtt_broker_id')
                                ->label('MQTT Broker')
                                ->options(MqttBroker::active()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function ($records, array $data) {
                            try {
                                $broker = MqttBroker::find($data['mqtt_broker_id']);
                                
                                foreach ($records as $record) {
                                    $record->assignToMqttBroker($broker, $record->device_unique_id);
                                }
                                
                                Notification::make()
                                    ->title('MQTT Broker assigned')
                                    ->body('Assigned ' . count($records) . " devices to broker: {$broker->name}")
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Bulk assignment failed')
                                    ->body('Error: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
            'view' => Pages\ViewDevice::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
