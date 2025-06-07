<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LandResource\Pages;
use App\Models\Land;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Dotswan\MapPicker\Fields\Map;
use Filament\Forms\Set;

class LandResource extends Resource
{
    protected static ?string $model = Land::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Farm Management';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Hidden field for user_id - THIS IS THE FIX
                Forms\Components\Hidden::make('user_id')
                    ->default(fn () => auth()->id())
                    ->required(),
                
                Forms\Components\Section::make('Land Information')
                    ->schema([
                        Forms\Components\TextInput::make('land_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\ColorPicker::make('color')
                            ->nullable()
                            ->extraAttributes([
                                'style' => 'z-index: 9999; position: relative;'
                            ]),
                        Forms\Components\Toggle::make('enabled')
                            ->default(true),
                    ])->columns(2),
                
                Forms\Components\Section::make('Location Data')
                    ->schema([
                        Map::make('location')
                            ->label('Land Location')
                            ->columnSpanFull()
                            ->defaultLocation(latitude: 39.526, longitude: -107.727)
                            ->draggable(true)
                            ->clickable(true)
                            ->zoom(15)
                            ->minZoom(0)
                            ->maxZoom(28)
                            ->tilesUrl("https://tile.openstreetmap.de/{z}/{x}/{y}.png")
                            ->detectRetina(true)
                            
                            // Single marker for location selection
                            ->showMarker(true)
                            ->markerColor("#3b82f6")
                            
                            // Essential Controls Only
                            ->showFullscreenControl(true)
                            ->showZoomControl(true)
                            
                            // GeoMan Integration - Only Polygon and Rectangle
                            ->geoMan(true)
                            ->geoManEditable(true)
                            ->geoManPosition('topleft')
                            ->drawMarker(false)       // Disabled - using single marker instead
                            ->drawCircle(false)       // Disabled
                            ->drawCircleMarker(false) // Disabled
                            ->drawPolyline(false)     // Disabled
                            ->drawPolygon(true)       // Enabled - Polygon drawing
                            ->drawRectangle(true)     // Enabled - Rectangle drawing
                            ->editPolygon(true)       // Edit existing shapes
                            ->deleteLayer(true)       // Delete shapes
                            ->dragMode(false)         // Disabled
                            ->cutPolygon(false)       // Disabled
                            ->rotateMode(false)       // Disabled
                            ->setColor('#3388ff')
                            ->setFilledColor('#cad9ec')
                            
                            // Extra Styling
                            ->extraStyles([
                                'min-height: 400px',
                                'border-radius: 8px',
                                'z-index: 1'
                            ])
                            
                            // State Management
                            ->afterStateUpdated(function (Set $set, ?array $state): void {
                                if (isset($state['lat']) && isset($state['lng'])) {
                                    $set('latitude', $state['lat']);
                                    $set('longitude', $state['lng']);
                                }
                                if (isset($state['geojson'])) {
                                    $set('geojson', json_encode($state['geojson']));
                                }
                            })
                            ->afterStateHydrated(function ($state, $record, Set $set): void {
                                if ($record) {
                                    $set('location', [
                                        'lat' => $record->latitude ?? 39.526,
                                        'lng' => $record->longitude ?? -107.727,
                                        'geojson' => $record->geojson ? json_decode($record->geojson, true) : null
                                    ]);
                                }
                            }),

                        Forms\Components\TextInput::make('latitude')
                            ->hiddenLabel()
                            ->hidden(),
                        
                        Forms\Components\TextInput::make('longitude')
                            ->hiddenLabel()
                            ->hidden(),
                        
                        Forms\Components\Textarea::make('geojson')
                            ->label('GeoJSON Data')
                            ->nullable()
                            ->disabled()
                            ->columnSpanFull()
                            ->readOnly(),
                        
                        Forms\Components\KeyValue::make('data')
                            ->label('Extra Characteristics')
                            ->keyLabel('Characteristic Name')
                            ->valueLabel('Value')
                            ->keyPlaceholder('e.g., Soil Type, Irrigation System, Crop History')
                            ->valuePlaceholder('e.g., Clay, Drip Irrigation, Corn 2024')
                            ->addActionLabel('Add Data')
                            ->helperText('Add any additional characteristics or properties specific to this land plot. This helps you keep track of important details like soil conditions, previous crops, irrigation methods, or any other relevant information.')
                            ->nullable()
                            ->columnSpanFull()
                            ->reorderable()
                            ->deletable(true)
                            ->addable(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('land_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('latitude')
                    ->label('Latitude')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 6) : 'Not set'),
                Tables\Columns\TextColumn::make('longitude')
                    ->label('Longitude')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 6) : 'Not set'),
                Tables\Columns\ColorColumn::make('color')
                    ->sortable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('devices_count')
                    ->counts('devices')
                    ->label('Devices'),
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
            'index' => Pages\ListLands::route('/'),
            'create' => Pages\CreateLand::route('/create'),
            'edit' => Pages\EditLand::route('/{record}/edit'),
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
