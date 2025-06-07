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
                    ]),
                Tables\Filters\TernaryFilter::make('enabled'),
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
            // 'view' => Pages\ViewSensor::route('/{record}'),
            'edit' => Pages\EditSensor::route('/{record}/edit'),
        ];
    }
}
