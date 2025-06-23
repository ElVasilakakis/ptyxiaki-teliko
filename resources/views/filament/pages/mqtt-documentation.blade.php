{{-- resources/views/filament/pages/mqtt-documentation.blade.php --}}

<x-filament-panels::page>
    <div x-data="{ activeTab: 'overview' }" class="space-y-6">
        {{-- Header Section --}}
        <x-filament::section>
            <x-slot name="heading">
                ESP32 Environmental Sensor Monitor with GPS
            </x-slot>
            
            <x-slot name="description">
                Complete MQTT documentation for the ESP32 device including all subscriptions, publications, and geofence testing capabilities. Device alternates between INSIDE and OUTSIDE Xorafi 1 polygon every 2 minutes.
            </x-slot>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <x-filament::card>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-primary-600">ESP32-DEV-001</div>
                        <div class="text-sm text-gray-500">Device ID</div>
                    </div>
                </x-filament::card>
                
                <x-filament::card>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-success-600">v1.3.0</div>
                        <div class="text-sm text-gray-500">Firmware Version</div>
                    </div>
                </x-filament::card>
                
                <x-filament::card>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-warning-600">Geofence Testing</div>
                        <div class="text-sm text-gray-500">Special Feature</div>
                    </div>
                </x-filament::card>
                
                <x-filament::card>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600">QoS 1</div>
                        <div class="text-sm text-gray-500">MQTT Quality</div>
                    </div>
                </x-filament::card>
            </div>
        </x-filament::section>

        {{-- Navigation Tabs --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8">
                <button 
                    @click="activeTab = 'overview'"
                    :class="{ 'border-primary-500 text-primary-600': activeTab === 'overview', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'overview' }"
                    class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <x-heroicon-o-document-text class="w-5 h-5" />
                    <span>Overview</span>
                </button>
                
                <button 
                    @click="activeTab = 'subscriptions'"
                    :class="{ 'border-primary-500 text-primary-600': activeTab === 'subscriptions', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'subscriptions' }"
                    class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <x-heroicon-o-arrow-down-on-square class="w-5 h-5" />
                    <span>Subscriptions</span>
                </button>
                
                <button 
                    @click="activeTab = 'publications'"
                    :class="{ 'border-primary-500 text-primary-600': activeTab === 'publications', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'publications' }"
                    class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <x-heroicon-o-arrow-up-on-square class="w-5 h-5" />
                    <span>Publications</span>
                </button>
                
                <button 
                    @click="activeTab = 'geofence'"
                    :class="{ 'border-primary-500 text-primary-600': activeTab === 'geofence', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'geofence' }"
                    class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <x-heroicon-o-map class="w-5 h-5" />
                    <span>Geofence Testing</span>
                </button>
            </nav>
        </div>

        {{-- Tab Content --}}
        <div class="mt-6">
            {{-- Overview Tab --}}
            <div x-show="activeTab === 'overview'" x-transition>
                {{ $this->getOverviewInfolist() }}
            </div>

            {{-- Subscriptions Tab --}}
            <div x-show="activeTab === 'subscriptions'" x-transition>
                {{ $this->getSubscriptionsInfolist() }}
            </div>

            {{-- Publications Tab --}}
            <div x-show="activeTab === 'publications'" x-transition>
                {{ $this->getPublicationsInfolist() }}
            </div>

            {{-- Geofence Testing Tab --}}
            <div x-show="activeTab === 'geofence'" x-transition>
                {{ $this->getGeofenceInfolist() }}
            </div>
        </div>

        {{-- Quick Reference Section --}}
        <x-filament::section>
            <x-slot name="heading">
                MQTT Broker Configuration & Quick Reference
            </x-slot>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Connection Details</h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Broker:</span>
                            <code class="text-primary-600 font-mono">broker.emqx.io</code>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Port:</span>
                            <code class="text-primary-600 font-mono">1883</code>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Username:</span>
                            <code class="text-primary-600 font-mono">mqttuser</code>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Default QoS:</span>
                            <code class="text-warning-600 font-mono">1</code>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Sensor Capabilities</h4>
                    <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <li class="flex items-center space-x-2">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-green-500" />
                            <span>DHT22 Temperature & Humidity</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-green-500" />
                            <span>GPS with Geofence Testing</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-green-500" />
                            <span>Remote LED Control</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-green-500" />
                            <span>Auto-Discovery Protocol</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-green-500" />
                            <span>Real-time Status Updates</span>
                        </li>
                    </ul>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Geofence Testing</h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Test Polygon:</span>
                            <code class="text-purple-600 font-mono">Xorafi 1</code>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Toggle Interval:</span>
                            <code class="text-purple-600 font-mono">2 minutes</code>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Current Mode:</span>
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded text-xs font-medium">
                                Alternating INSIDE/OUTSIDE
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Manual Control:</span>
                            <code class="text-warning-600 font-mono">toggle_geofence</code>
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Footer with Device Statistics --}}
        <x-filament::section>
            <x-slot name="heading">
                Device Statistics & Performance
            </x-slot>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600">9</div>
                    <div class="text-sm text-gray-500">Total MQTT Topics</div>
                </div>
                
                <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                    <div class="text-2xl font-bold text-green-600">5</div>
                    <div class="text-sm text-gray-500">Sensor Types</div>
                </div>
                
                <div class="text-center p-4 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                    <div class="text-2xl font-bold text-purple-600">4</div>
                    <div class="text-sm text-gray-500">Control Commands</div>
                </div>
                
                <div class="text-center p-4 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600">10s</div>
                    <div class="text-sm text-gray-500">Data Publish Rate</div>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
