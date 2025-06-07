<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SensorResource\Pages;
use App\Models\Sensor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SensorResource extends Resource
{
    protected static ?string $model = Sensor::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Farm Management';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sensor Information')
                    ->schema([
                        Forms\Components\Select::make('device_id')
                            ->relationship('device', 'name')
                            ->required()
                            ->searchable(),
                        Forms\Components\TextInput::make('sensor_type')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sensor_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('unit')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('enabled')
                            ->default(true),
                    ])->columns(2),
                
                Forms\Components\Section::make('Sensor Readings')
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\TextInput::make('accuracy')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\DateTimePicker::make('reading_timestamp')
                            ->nullable(),
                        Forms\Components\KeyValue::make('thresholds')
                            ->keyLabel('Threshold Type')
                            ->valueLabel('Value')
                            ->nullable(),
                    ])->columns(2),
                
                Forms\Components\Section::make('Calibration & Alerts')
                    ->schema([
                        Forms\Components\TextInput::make('calibration_offset')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\DateTimePicker::make('last_calibration')
                            ->nullable(),
                        Forms\Components\Toggle::make('alert_enabled')
                            ->default(false),
                        Forms\Components\TextInput::make('alert_threshold_min')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\TextInput::make('alert_threshold_max')
                            ->numeric()
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sensor_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sensor_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('device.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reading_timestamp')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('alert_enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('device_id')
                    ->relationship('device', 'name')
                    ->label('Device'),
                Tables\Filters\SelectFilter::make('sensor_type')
                    ->options(function () {
                        return Sensor::distinct()->pluck('sensor_type', 'sensor_type')->toArray();
                    }),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
                Tables\Filters\TernaryFilter::make('alert_enabled')
                    ->label('Alert Enabled'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
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
            'index' => Pages\ListSensors::route('/'),
            'create' => Pages\CreateSensor::route('/create'),
            'edit' => Pages\EditSensor::route('/{record}/edit'),
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
