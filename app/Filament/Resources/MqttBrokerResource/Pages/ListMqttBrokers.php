<?php

namespace App\Filament\Resources\MqttBrokerResource\Pages;

use App\Filament\Resources\MqttBrokerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMqttBrokers extends ListRecords
{
    protected static string $resource = MqttBrokerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
