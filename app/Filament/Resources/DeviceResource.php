<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use App\Models\Land;
use App\Models\Sensor;
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
                    ->visibleOn('edit'),
                    
                Forms\Components\Section::make('Device Data')
                    ->schema([
                        Forms\Components\KeyValue::make('application_data')
                            ->label('Device Information')
                            ->disabled(),
                        Forms\Components\KeyValue::make('status_details')
                            ->label('Status Details')
                            ->disabled(),
                    ])->columns(1)
                    ->visibleOn('edit'),
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
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->actions([
                Tables\Actions\Action::make('discover')
                    ->label('Discover Device')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function (Device $record) {
                        try {
                            // Store current user in session/cache for MQTT service
                            cache()->put("mqtt_user_context", auth()->id(), now()->addMinutes(5));
                            
                            // Log the discovery attempt
                            \Illuminate\Support\Facades\Log::channel('mqtt')->info("Manual device discovery initiated", [
                                'device_id' => $record->device_unique_id,
                                'user_id' => auth()->id(),
                                'from_filament' => true
                            ]);
                            
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            $mqtt->publish("devices/{$record->device_unique_id}/discover", 'discover');
                            
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
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('value')
                            ->label('Command Value')
                            ->placeholder('1 for LED on, 0 for LED off'),
                    ])
                    ->action(function (Device $record, array $data) {
                        try {
                            $service = new MqttDeviceService();
                            $service->publishDeviceCommand(
                                $record->device_unique_id,
                                $data['command'],
                                ['value' => $data['value'] ?? '']
                            );
                            
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
                            // Store current user context
                            cache()->put("mqtt_user_context", auth()->id(), now()->addMinutes(5));
                            
                            \Illuminate\Support\Facades\Log::channel('mqtt')->info("Global device discovery initiated", [
                                'user_id' => auth()->id(),
                                'from_filament' => true
                            ]);
                            
                            $service = new MqttDeviceService();
                            $service->discoverAllDevices();
                            
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
                            
                            Notification::make()
                                ->title('MQTT Connection Active')
                                ->body('Successfully connected to MQTT broker')
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
                            $service = new MqttDeviceService();
                            $mqtt = \PhpMqtt\Client\Facades\MQTT::connection();
                            
                            foreach ($records as $record) {
                                $mqtt->publish("devices/{$record->device_unique_id}/discover", 'discover');
                            }
                            
                            Notification::make()
                                ->title('Discovery requests sent')
                                ->body('Sent discovery requests to ' . count($records) . ' devices')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Add sensor relation resource here if needed
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
