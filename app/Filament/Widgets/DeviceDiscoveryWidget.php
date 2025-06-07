<?php

namespace App\Filament\Widgets;

use App\Models\Device;
use App\Models\Sensor;
use App\Services\MqttDeviceService;
use Filament\Widgets\Widget;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class DeviceDiscoveryWidget extends Widget
{
    protected static string $view = 'filament.widgets.device-discovery-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 2;
    
    public function getViewData(): array
    {
        return [
            'deviceStats' => $this->getDeviceStats(),
            'sensorStats' => $this->getSensorStats(),
            'discoveryStats' => $this->getDiscoveryStats(),
        ];
    }
    
    public function discoverAllDevices(): void
    {
        try {
            $service = new MqttDeviceService();
            $service->discoverAllDevices();
            
            Notification::make()
                ->title('Discovery request sent')
                ->body('Global device discovery request has been sent')
                ->success()
                ->send();
                
            // Store discovery timestamp in cache
            Cache::put('last_discovery_request', now()->toIso8601String(), now()->addDays(1));
            Cache::increment('discovery_request_count', 1);
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Discovery failed')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    protected function getDeviceStats(): array
    {
        $devices = Device::query();
        
        return [
            'total' => $devices->count(),
            'online' => $devices->where('status', 'online')->count(),
            'offline' => $devices->where('status', 'offline')->count(),
            'recent' => $devices->where('created_at', '>=', now()->subHours(24))->count(),
            'types' => Device::select('device_type')
                ->selectRaw('count(*) as count')
                ->groupBy('device_type')
                ->pluck('count', 'device_type')
                ->toArray(),
        ];
    }
    
    protected function getSensorStats(): array
    {
        $sensors = Sensor::query();
        
        return [
            'total' => $sensors->count(),
            'types' => Sensor::select('sensor_type')
                ->selectRaw('count(*) as count')
                ->groupBy('sensor_type')
                ->pluck('count', 'sensor_type')
                ->toArray(),
            'warnings' => $sensors->where('status', 'warning')->count(),
            'critical' => $sensors->where('status', 'critical')->count(),
        ];
    }
    
    protected function getDiscoveryStats(): array
    {
        return [
            'last_discovery' => Cache::get('last_discovery_request'),
            'discovery_count' => Cache::get('discovery_request_count', 0),
            'recent_discoveries' => Device::where('updated_at', '>=', now()->subHours(1))->count(),
        ];
    }
}
