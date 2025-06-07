<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use App\Models\Land;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-tablet';

    protected static ?string $navigationGroup = 'Farm Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Device Configuration')
                    ->schema([
                        Forms\Components\Select::make('land_id')
                            ->options(Land::all()->pluck('land_name', 'id'))
                            ->required()
                            ->label('Land')
                            ->searchable(),
                        Forms\Components\TextInput::make('api_url')
                            ->label('Link')
                            ->url()
                            ->maxLength(255)
                            ->nullable(),
                        Forms\Components\Toggle::make('enabled')
                            ->default(true),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('device_unique_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('device_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('land.land_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('application_data')
                    ->label('Coordinates')
                    ->formatStateUsing(fn ($state) => isset($state['lat']) && isset($state['lng']) ? "Lat: {$state['lat']}, Lng: {$state['lng']}" : 'No location set'),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
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
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sensors_count')
                    ->counts('sensors')
                    ->label('Sensors'),
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                        'error' => 'Error',
                        'maintenance' => 'Maintenance',
                    ]),
                Tables\Filters\SelectFilter::make('land_id')
                    ->relationship('land', 'land_name')
                    ->label('Land'),
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('User'),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
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
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
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
