<?php

namespace App\Filament\Resources\SensorResource\Pages;

use App\Filament\Resources\SensorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSensors extends ListRecords
{
    protected static string $resource = SensorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
