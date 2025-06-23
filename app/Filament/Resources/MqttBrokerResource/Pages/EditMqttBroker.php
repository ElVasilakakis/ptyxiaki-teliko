<?php

namespace App\Filament\Resources\MqttBrokerResource\Pages;

use App\Filament\Resources\MqttBrokerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMqttBroker extends EditRecord
{
    protected static string $resource = MqttBrokerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
