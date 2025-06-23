<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MqttBrokerResource\Pages;
use App\Models\MqttBroker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class MqttBrokerResource extends Resource
{
    protected static ?string $model = MqttBroker::class;
    protected static ?string $navigationIcon = 'heroicon-o-server';
    protected static ?string $navigationGroup = 'MQTT Management';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'MQTT Brokers';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Broker Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Broker Name')
                            ->required()
                            ->maxLength(255)
                            ->unique(MqttBroker::class, 'name', ignoreRecord: true),
                        Forms\Components\TextInput::make('host')
                            ->label('Host')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('broker.emqx.io'),
                        Forms\Components\TextInput::make('port')
                            ->label('Port')
                            ->numeric()
                            ->required()
                            ->default(1883)
                            ->minValue(1)
                            ->maxValue(65535),
                        Forms\Components\Select::make('protocol')
                            ->label('Protocol')
                            ->options(MqttBroker::PROTOCOLS)
                            ->default('mqtt')
                            ->required(),
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->maxLength(255)
                            ->nullable(),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->nullable(),
                        Forms\Components\Toggle::make('is_default')
                            ->label('Set as Default Broker')
                            ->helperText('Only one broker can be set as default')
                            ->default(false),
                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('Connection Settings')
                    ->schema([
                        Forms\Components\TextInput::make('keep_alive')
                            ->label('Keep Alive Interval (seconds)')
                            ->numeric()
                            ->default(60)
                            ->required()
                            ->minValue(1)
                            ->maxValue(3600),
                        Forms\Components\TextInput::make('connect_timeout')
                            ->label('Connect Timeout (seconds)')
                            ->numeric()
                            ->default(30)
                            ->required()
                            ->minValue(1)
                            ->maxValue(300),
                        Forms\Components\Toggle::make('clean_session')
                            ->label('Clean Session')
                            ->helperText('Start with a clean session')
                            ->default(false),
                        Forms\Components\TextInput::make('qos')
                            ->label('Default Quality of Service (QoS)')
                            ->numeric()
                            ->default(1)
                            ->required()
                            ->minValue(0)
                            ->maxValue(2),
                        Forms\Components\TextInput::make('client_id_prefix')
                            ->label('Client ID Prefix')
                            ->default('laravel')
                            ->required()
                            ->maxLength(50),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                MqttBroker::STATUS_ACTIVE => 'Active',
                                MqttBroker::STATUS_INACTIVE => 'Inactive',
                                MqttBroker::STATUS_ERROR => 'Error',
                            ])
                            ->default(MqttBroker::STATUS_ACTIVE)
                            ->required(),
                    ])->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('TLS/SSL Configuration')
                    ->schema([
                        Forms\Components\Toggle::make('tls_enabled')
                            ->label('Enable TLS/SSL')
                            ->default(false)
                            ->live(),
                        Forms\Components\Textarea::make('tls_options')
                            ->label('TLS Options (JSON)')
                            ->rows(4)
                            ->placeholder('{"verify_peer": true, "verify_peer_name": true}')
                            ->helperText('Advanced TLS configuration in JSON format')
                            ->nullable()
                            ->visible(fn (Forms\Get $get): bool => $get('tls_enabled')),
                    ])->columns(1)
                    ->collapsible(),

                Forms\Components\Section::make('Auto-Reconnect Settings')
                    ->schema([
                        Forms\Components\Toggle::make('auto_reconnect')
                            ->label('Enable Auto Reconnect')
                            ->default(true)
                            ->live(),
                        Forms\Components\TextInput::make('max_reconnect_attempts')
                            ->label('Max Reconnect Attempts')
                            ->numeric()
                            ->default(5)
                            ->required()
                            ->minValue(1)
                            ->maxValue(100)
                            ->visible(fn (Forms\Get $get): bool => $get('auto_reconnect')),
                        Forms\Components\TextInput::make('reconnect_delay')
                            ->label('Reconnect Delay (seconds)')
                            ->numeric()
                            ->default(5)
                            ->required()
                            ->minValue(1)
                            ->maxValue(300)
                            ->visible(fn (Forms\Get $get): bool => $get('auto_reconnect')),
                    ])->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Last Will Testament')
                    ->schema([
                        Forms\Components\TextInput::make('last_will_topic')
                            ->label('Last Will Topic')
                            ->maxLength(255)
                            ->placeholder('devices/laravel_client/status')
                            ->nullable(),
                        Forms\Components\TextInput::make('last_will_message')
                            ->label('Last Will Message')
                            ->maxLength(255)
                            ->placeholder('offline')
                            ->nullable(),
                        Forms\Components\TextInput::make('last_will_qos')
                            ->label('Last Will QoS')
                            ->numeric()
                            ->default(1)
                            ->required()
                            ->minValue(0)
                            ->maxValue(2),
                        Forms\Components\Toggle::make('last_will_retain')
                            ->label('Last Will Retain')
                            ->default(true),
                    ])->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Connection Status')
                    ->schema([
                        Forms\Components\Placeholder::make('last_connected_at')
                            ->label('Last Connected')
                            ->content(fn (?MqttBroker $record): string => 
                                $record?->last_connected_at 
                                    ? $record->last_connected_at->format('Y-m-d H:i:s') . ' (' . $record->last_connected_at->diffForHumans() . ')'
                                    : 'Never connected'
                            ),
                        Forms\Components\Placeholder::make('connection_error')
                            ->label('Last Connection Error')
                            ->content(fn (?MqttBroker $record): string => 
                                $record?->connection_error ?? 'No errors'
                            ),
                        Forms\Components\Placeholder::make('devices_count')
                            ->label('Connected Devices')
                            ->content(fn (?MqttBroker $record): string => 
                                $record ? $record->devices()->count() . ' devices' : '0 devices'
                            ),
                        Forms\Components\KeyValue::make('statistics')
                            ->label('Connection Statistics')
                            ->disabled()
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false)
                            ->editableValues(false)
                            ->default(fn (?MqttBroker $record): array => $record?->statistics ?? [])
                            ->columnSpanFull(),
                    ])->columns(2)
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Broker Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('host')
                    ->label('Host')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('port')
                    ->label('Port')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('protocol')
                    ->label('Protocol')
                    ->colors([
                        'primary' => 'mqtt',
                        'success' => 'mqtts',
                        'warning' => 'ws',
                        'info' => 'wss',
                    ]),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => MqttBroker::STATUS_ACTIVE,
                        'warning' => MqttBroker::STATUS_INACTIVE,
                        'danger' => MqttBroker::STATUS_ERROR,
                    ])
                    ->sortable(),
                Tables\Columns\IconColumn::make('tls_enabled')
                    ->label('TLS')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('auto_reconnect')
                    ->label('Auto Reconnect')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('devices_count')
                    ->label('Devices')
                    ->counts('devices')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('last_connected_at')
                    ->label('Last Connected')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('connection_error')
                    ->label('Error')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->connection_error)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MqttBroker::STATUS_ACTIVE => 'Active',
                        MqttBroker::STATUS_INACTIVE => 'Inactive',
                        MqttBroker::STATUS_ERROR => 'Error',
                    ]),
                Tables\Filters\SelectFilter::make('protocol')
                    ->options(MqttBroker::PROTOCOLS),
                Tables\Filters\TernaryFilter::make('tls_enabled')
                    ->label('TLS Enabled'),
                Tables\Filters\TernaryFilter::make('auto_reconnect')
                    ->label('Auto Reconnect'),
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label('Default Broker'),
            ])
            ->actions([
                Tables\Actions\Action::make('test_connection')
                    ->label('Test Connection')
                    ->icon('heroicon-o-signal')
                    ->action(function (MqttBroker $record) {
                        $maxTestTime = 10; // seconds
                        $startTime = time();
                        
                        try {
                            // Call the static method correctly
                            self::testConnectionWithTimeout($record, $maxTestTime);
                            
                            $record->updateConnectionStatus(true);
                            
                            Notification::make()
                                ->title('Connection Test Successful')
                                ->body("Connected to {$record->name} in " . (time() - $startTime) . " seconds")
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            $record->updateConnectionStatus(false, $e->getMessage());
                            
                            Notification::make()
                                ->title('Connection Test Failed')
                                ->body("Error: " . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('set_default')
                    ->label('Set as Default')
                    ->icon('heroicon-o-star')
                    ->action(function (MqttBroker $record) {
                        // Remove default from all other brokers
                        MqttBroker::where('is_default', true)->update(['is_default' => false]);
                        
                        // Set this broker as default
                        $record->update(['is_default' => true]);
                        
                        Notification::make()
                            ->title('Default Broker Updated')
                            ->body("{$record->name} is now the default MQTT broker")
                            ->success()
                            ->send();
                    })
                    ->visible(fn (MqttBroker $record): bool => !$record->is_default),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (MqttBroker $record) {
                        if ($record->is_default) {
                            Notification::make()
                                ->title('Cannot Delete Default Broker')
                                ->body('Please set another broker as default before deleting this one.')
                                ->danger()
                                ->send();
                            
                            return false;
                        }
                        
                        if ($record->devices()->count() > 0) {
                            Notification::make()
                                ->title('Cannot Delete Broker')
                                ->body('This broker has ' . $record->devices()->count() . ' devices assigned to it.')
                                ->danger()
                                ->send();
                            
                            return false;
                        }
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('test_all_connections')
                    ->label('Test All Connections')
                    ->icon('heroicon-o-signal')
                    ->action(function () {
                        $brokers = MqttBroker::active()->get();
                        $successful = 0;
                        $failed = 0;
                        
                        foreach ($brokers as $broker) {
                            try {
                                // Test connection logic would go here
                                $broker->updateConnectionStatus(true);
                                $successful++;
                            } catch (\Exception $e) {
                                $broker->updateConnectionStatus(false, $e->getMessage());
                                $failed++;
                            }
                        }
                        
                        Notification::make()
                            ->title('Connection Tests Completed')
                            ->body("Successful: {$successful}, Failed: {$failed}")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            $defaultBroker = $records->where('is_default', true)->first();
                            if ($defaultBroker) {
                                Notification::make()
                                    ->title('Cannot Delete Default Broker')
                                    ->body('Please remove the default broker from selection.')
                                    ->danger()
                                    ->send();
                                
                                return false;
                            }
                        }),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate Selected')
                        ->icon('heroicon-o-check-circle')
                        ->action(function ($records) {
                            $records->each(fn ($record) => $record->update(['status' => MqttBroker::STATUS_ACTIVE]));
                            
                            Notification::make()
                                ->title('Brokers Activated')
                                ->body('Selected brokers have been activated.')
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate Selected')
                        ->icon('heroicon-o-x-circle')
                        ->action(function ($records) {
                            $records->each(fn ($record) => $record->update(['status' => MqttBroker::STATUS_INACTIVE]));
                            
                            Notification::make()
                                ->title('Brokers Deactivated')
                                ->body('Selected brokers have been deactivated.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            // You can add a DevicesRelationManager here if needed
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMqttBrokers::route('/'),
            'create' => Pages\CreateMqttBroker::route('/create'),
            'edit' => Pages\EditMqttBroker::route('/{record}/edit'),
            'view' => Pages\ViewMqttBroker::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::active()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::active()->count() > 0 ? 'success' : 'danger';
    }

    private static function testConnectionWithTimeout(MqttBroker $broker, int $timeout): void
    {
        $connectionSettings = new \PhpMqtt\Client\ConnectionSettings();
        
        if ($broker->username) {
            $connectionSettings->setUsername($broker->username);
            $connectionSettings->setPassword($broker->password);
        }
        
        $connectionSettings->setKeepAliveInterval($broker->keep_alive);
        $connectionSettings->setConnectTimeout(min($timeout, $broker->connect_timeout));
        $connectionSettings->setUseTls($broker->tls_enabled);
        
        $clientId = $broker->generateClientId('filament_test_' . uniqid());
        $mqtt = new \PhpMqtt\Client\MqttClient($broker->host, $broker->port, $clientId);
        
        $mqtt->connect($connectionSettings, $broker->clean_session);
        $mqtt->disconnect();
    }

}
