<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SensorResource\Pages;
use App\Models\Sensor;
use App\Models\Device;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SensorResource extends Resource
{
    protected static ?string $model = Sensor::class;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Farm Management';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Select::make('device_id')
                            ->relationship('device', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        
                        Forms\Components\TextInput::make('sensor_name')
                            ->required()
                            ->maxLength(255),
                        
                        Forms\Components\Select::make('sensor_type')
                            ->options([
                                'temperature' => 'Temperature',
                                'humidity' => 'Humidity',
                                'light' => 'Light',
                                'signal' => 'WiFi Signal',
                                'battery' => 'Battery',
                                'gps_latitude' => 'GPS Latitude',
                                'gps_longitude' => 'GPS Longitude',
                                'gps_altitude' => 'GPS Altitude',
                                'gps_speed' => 'GPS Speed',
                            ])
                            ->required()
                            ->live(),
                        
                        Forms\Components\Textarea::make('description')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        
                        Forms\Components\TextInput::make('location')
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('unit')
                            ->maxLength(50)
                            ->placeholder('e.g., °C, %, dBm'),
                        
                        Forms\Components\Toggle::make('enabled')
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Thresholds & Alerts')
                    ->schema([
                        Forms\Components\Group::make([
                            Forms\Components\TextInput::make('thresholds.min')
                                ->label('Minimum Threshold')
                                ->numeric()
                                ->step(0.01)
                                ->placeholder('Enter minimum value'),
                            
                            Forms\Components\TextInput::make('thresholds.max')
                                ->label('Maximum Threshold')
                                ->numeric()
                                ->step(0.01)
                                ->placeholder('Enter maximum value'),
                        ])
                        ->columns(2),
                        
                        Forms\Components\Toggle::make('alert_enabled')
                            ->label('Enable Alerts')
                            ->live(),
                        
                        Forms\Components\Group::make([
                            Forms\Components\TextInput::make('alert_threshold_min')
                                ->label('Alert Minimum')
                                ->numeric()
                                ->step(0.01)
                                ->visible(fn (Forms\Get $get) => $get('alert_enabled')),
                            
                            Forms\Components\TextInput::make('alert_threshold_max')
                                ->label('Alert Maximum')
                                ->numeric()
                                ->step(0.01)
                                ->visible(fn (Forms\Get $get) => $get('alert_enabled')),
                        ])
                        ->columns(2)
                        ->visible(fn (Forms\Get $get) => $get('alert_enabled')),
                    ]),

                Forms\Components\Section::make('Calibration')
                    ->schema([
                        Forms\Components\TextInput::make('calibration_offset')
                            ->label('Calibration Offset')
                            ->numeric()
                            ->step(0.001)
                            ->placeholder('0.000'),
                        
                        Forms\Components\DateTimePicker::make('last_calibration')
                            ->label('Last Calibration Date'),
                        
                        Forms\Components\TextInput::make('accuracy')
                            ->label('Accuracy (%)')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Current Reading')
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->label('Current Value')
                            ->numeric()
                            ->step(0.000001) // For GPS precision
                            ->disabled()
                            ->dehydrated(false),
                        
                        Forms\Components\DateTimePicker::make('reading_timestamp')
                            ->label('Last Reading')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('device.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sensor_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('sensor_type')
                    ->colors([
                        'success' => 'temperature',
                        'info' => 'humidity',
                        'warning' => 'light',
                        'danger' => 'signal',
                        'primary' => 'battery',
                        'secondary' => fn ($state) => str_starts_with($state, 'gps'),
                    ]),
                Tables\Columns\TextColumn::make('formatted_value')
                    ->label('Current Value')
                    ->badge()
                    ->color(fn (Sensor $record): string => match ($record->status) {
                        'normal' => 'success',
                        'warning' => 'warning',
                        'critical' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'normal',
                        'warning' => 'warning',
                        'danger' => 'critical',
                    ]),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('reading_timestamp')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('thresholds')
                    ->label('Thresholds')
                    ->getStateUsing(function ($record) {
                        $thresholds = $record->thresholds;
                        
                        // Debug: Check what we're actually getting
                        if (is_null($thresholds)) {
                            return 'Not set';
                        }
                        
                        if (is_string($thresholds)) {
                            // If it's still a JSON string, decode it
                            $thresholds = json_decode($thresholds, true);
                        }
                        
                        if (!is_array($thresholds)) {
                            return 'Invalid format';
                        }
                        
                        $min = isset($thresholds['min']) && $thresholds['min'] !== null ? $thresholds['min'] : 'N/A';
                        $max = isset($thresholds['max']) && $thresholds['max'] !== null ? $thresholds['max'] : 'N/A';
                        
                        return "Min: {$min} | Max: {$max}";
                    })
                    ->toggleable(),


            ])
            ->filters([
                Tables\Filters\SelectFilter::make('device_id')
                    ->relationship('device', 'name')
                    ->label('Device'),
                Tables\Filters\SelectFilter::make('sensor_type')
                    ->options([
                        'temperature' => 'Temperature',
                        'humidity' => 'Humidity',
                        'light' => 'Light',
                        'signal' => 'WiFi Signal',
                        'battery' => 'Battery',
                        'gps_latitude' => 'GPS Latitude',
                        'gps_longitude' => 'GPS Longitude',
                        'gps_altitude' => 'GPS Altitude',
                        'gps_speed' => 'GPS Speed',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled'),
                Tables\Filters\TernaryFilter::make('alert_enabled')
                    ->label('Alerts Enabled'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSensors::route('/'),
            'edit' => Pages\EditSensor::route('/{record}/edit'),
        ];
    }
}
