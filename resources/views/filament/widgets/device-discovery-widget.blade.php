<x-filament::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold tracking-tight">Device Discovery Dashboard</h2>
            
            <div>
                <button wire:click="discoverAllDevices" class="inline-flex items-center justify-center font-medium rounded-lg border transition-colors focus:outline-none focus:ring-offset-2 focus:ring-2 focus:ring-inset filament-button h-9 px-4 text-sm text-white shadow focus:ring-white border-transparent bg-primary-600 hover:bg-primary-500 focus:bg-primary-700 focus:ring-offset-primary-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-1">Discover All Devices</span>
                </button>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <!-- Device Stats Card -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-primary-100 rounded-md p-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Total Devices</dt>
                                <dd class="flex items-baseline">
                                    <div class="text-2xl font-semibold text-gray-900">{{ $deviceStats['total'] }}</div>
                                    <div class="ml-2 flex items-baseline text-sm font-semibold">
                                        <span class="text-green-600">{{ $deviceStats['online'] }} online</span>
                                        <span class="mx-2 text-gray-500">|</span>
                                        <span class="text-red-600">{{ $deviceStats['offline'] }} offline</span>
                                    </div>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-4 sm:px-6">
                    <div class="text-sm">
                        <div class="font-medium text-gray-700">Device Types:</div>
                        <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1">
                            @forelse($deviceStats['types'] as $type => $count)
                                <div class="flex items-center">
                                    <span class="inline-block w-2 h-2 rounded-full mr-2 
                                        @if($type === 'SENSOR_MONITOR')
                                            bg-green-500
                                        @elseif($type === 'WEATHER_STATION')
                                            bg-blue-500
                                        @elseif($type === 'IRRIGATION_CONTROLLER')
                                            bg-yellow-500
                                        @else
                                            bg-gray-500
                                        @endif
                                    "></span>
                                    <span class="text-xs text-gray-600">{{ $type }}: {{ $count }}</span>
                                </div>
                            @empty
                                <div class="text-xs text-gray-500">No devices found</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sensor Stats Card -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-indigo-100 rounded-md p-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Total Sensors</dt>
                                <dd class="flex items-baseline">
                                    <div class="text-2xl font-semibold text-gray-900">{{ $sensorStats['total'] }}</div>
                                    @if($sensorStats['warnings'] > 0 || $sensorStats['critical'] > 0)
                                        <div class="ml-2 flex items-baseline text-sm font-semibold">
                                            @if($sensorStats['warnings'] > 0)
                                                <span class="text-yellow-600">{{ $sensorStats['warnings'] }} warnings</span>
                                            @endif
                                            
                                            @if($sensorStats['warnings'] > 0 && $sensorStats['critical'] > 0)
                                                <span class="mx-2 text-gray-500">|</span>
                                            @endif
                                            
                                            @if($sensorStats['critical'] > 0)
                                                <span class="text-red-600">{{ $sensorStats['critical'] }} critical</span>
                                            @endif
                                        </div>
                                    @endif
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-4 sm:px-6">
                    <div class="text-sm">
                        <div class="font-medium text-gray-700">Sensor Types:</div>
                        <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1">
                            @forelse($sensorStats['types'] as $type => $count)
                                <div class="flex items-center">
                                    <span class="inline-block w-2 h-2 rounded-full mr-2 
                                        @if($type === 'temperature')
                                            bg-red-500
                                        @elseif($type === 'humidity')
                                            bg-blue-500
                                        @elseif($type === 'light')
                                            bg-yellow-500
                                        @elseif($type === 'wifi_signal')
                                            bg-purple-500
                                        @else
                                            bg-gray-500
                                        @endif
                                    "></span>
                                    <span class="text-xs text-gray-600">{{ $type }}: {{ $count }}</span>
                                </div>
                            @empty
                                <div class="text-xs text-gray-500">No sensors found</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Discovery Stats Card -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Discovery Status</dt>
                                <dd class="flex items-baseline">
                                    <div class="text-2xl font-semibold text-gray-900">{{ $discoveryStats['discovery_count'] }}</div>
                                    <div class="ml-2 text-sm text-gray-500">discovery requests</div>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-4 sm:px-6">
                    <div class="text-sm">
                        <div class="font-medium text-gray-700">Discovery Information:</div>
                        <div class="mt-1 space-y-1">
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Last Discovery:</span>
                                <span class="text-xs font-medium text-gray-900">
                                    {{ $discoveryStats['last_discovery'] ? \Carbon\Carbon::parse($discoveryStats['last_discovery'])->diffForHumans() : 'Never' }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Recent Discoveries:</span>
                                <span class="text-xs font-medium text-gray-900">{{ $discoveryStats['recent_discoveries'] }} devices in the last hour</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">New Devices (24h):</span>
                                <span class="text-xs font-medium text-gray-900">{{ $deviceStats['recent'] }} devices</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900">Discovery Instructions</h3>
                <div class="mt-2 max-w-xl text-sm text-gray-500">
                    <p>
                        Click the "Discover All Devices" button to send a global discovery request to all connected devices.
                        Devices will respond with their information, including available sensors.
                    </p>
                </div>
                <div class="mt-3">
                    <div class="rounded-md bg-blue-50 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3 flex-1 md:flex md:justify-between">
                                <p class="text-sm text-blue-700">
                                    You can also discover individual devices from the Devices page by clicking the "Discover Device" button.
                                </p>
                                <p class="mt-3 text-sm md:mt-0 md:ml-6">
                                    <a href="{{ route('filament.admin.resources.devices.index') }}" class="whitespace-nowrap font-medium text-blue-700 hover:text-blue-600">
                                        Go to Devices
                                        <span aria-hidden="true"> &rarr;</span>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament::widget>
