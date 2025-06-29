<div>
    @php
        // Get the state data passed from the ViewEntry
        $state = $getState();
        $devices = collect($state['devices'] ?? []);
        $lands = collect($state['lands'] ?? []);
        $centerLat = $state['center_lat'] ?? 0;
        $centerLng = $state['center_lng'] ?? 0;
        $hasLocation = $state['has_location'] ?? false;
        $mapId = $state['mapId'] ?? 'device-location-map';
    @endphp

    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @endpush

    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @endpush

    @if($hasLocation)
        <div class="w-full h-96 rounded-lg border border-gray-200 dark:border-gray-700" id="{{ $mapId }}"></div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            @if($devices->isNotEmpty())
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">📡 Device Location</h4>
                @foreach($devices as $device)
                <div class="text-sm text-blue-800 dark:text-blue-200">
                    <p><strong>{{ $device['name'] }}</strong></p>
                    <p>Type: {{ str_replace('_', ' ', $device['type']) }}</p>
                    <p>Status: <span class="font-semibold">{{ ucfirst($device['status']) }}</span></p>
                    <p>Coordinates: {{ number_format($device['lat'], 6) }}, {{ number_format($device['lng'], 6) }}</p>
                    @if($device['altitude'])
                    <p>Altitude: {{ $device['altitude'] }}m</p>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
            
            @if($lands->isNotEmpty())
            <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
                <h4 class="font-semibold text-green-900 dark:text-green-100 mb-2">🏞️ Land Location</h4>
                @foreach($lands as $land)
                <div class="text-sm text-green-800 dark:text-green-200">
                    <p><strong>{{ $land['name'] }}</strong></p>
                    <p>Center: {{ number_format($land['lat'], 6) }}, {{ number_format($land['lng'], 6) }}</p>
                    @if($land['area'])
                    <p>Area: {{ $land['area'] }} hectares</p>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
        </div>

        @push('scripts')
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const mapId = '{{ $mapId }}';
            const devices = @json($devices->toArray());
            const lands = @json($lands->toArray());
            const centerLat = {{ $centerLat }};
            const centerLng = {{ $centerLng }};
            
            // Initialize map
            const map = L.map(mapId).setView([centerLat, centerLng], 15);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Device icons and colors
            const deviceIcons = {
                'SENSOR_MONITOR': '📊',
                'WEATHER_STATION': '🌤️',
                'IRRIGATION_CONTROLLER': '💧',
                'ESP32_DEVICE': '📡'
            };
            
            const statusColors = {
                'online': '#10b981',
                'offline': '#ef4444',
                'error': '#f59e0b',
                'maintenance': '#6b7280'
            };
            
            // Add device markers
            devices.forEach(device => {
                const icon = deviceIcons[device.type] || '📍';
                const color = statusColors[device.status] || '#6b7280';
                
                const marker = L.marker([device.lat, device.lng], {
                    icon: L.divIcon({
                        html: `<div style="background-color: ${color}; border-radius: 50%; padding: 8px; text-align: center; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); font-size: 16px;">${icon}</div>`,
                        className: 'custom-device-marker',
                        iconSize: [40, 40],
                        iconAnchor: [20, 20]
                    })
                }).addTo(map);
                
                marker.bindPopup(`
                    <div class="p-3">
                        <h3 class="font-bold text-lg text-blue-900">${device.name}</h3>
                        <p class="text-sm text-gray-600">Type: ${device.type.replace(/_/g, ' ')}</p>
                        <p class="text-sm">Status: <span class="font-semibold" style="color: ${color}">${device.status.toUpperCase()}</span></p>
                        <p class="text-xs text-gray-500 mt-1">📍 ${device.lat.toFixed(6)}, ${device.lng.toFixed(6)}</p>
                        ${device.altitude ? `<p class="text-xs text-gray-500">⛰️ ${device.altitude}m altitude</p>` : ''}
                    </div>
                `);
            });
            
            // Add land markers and boundaries
            lands.forEach(land => {
                // Land center marker
                const landMarker = L.marker([land.lat, land.lng], {
                    icon: L.divIcon({
                        html: '<div style="background-color: #059669; color: white; border-radius: 6px; padding: 6px 12px; font-size: 14px; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">🏞️</div>',
                        className: 'custom-land-marker',
                        iconSize: [80, 35],
                        iconAnchor: [40, 17]
                    })
                }).addTo(map);
                
                landMarker.bindPopup(`
                    <div class="p-3">
                        <h3 class="font-bold text-lg text-green-900">${land.name}</h3>
                        <p class="text-sm text-gray-600">Land Area</p>
                        <p class="text-xs text-gray-500 mt-1">📍 ${land.lat.toFixed(6)}, ${land.lng.toFixed(6)}</p>
                        ${land.area ? `<p class="text-xs text-gray-500">📐 ${land.area} hectares</p>` : ''}
                    </div>
                `);
                
                // Add land boundary if available
                if (land.boundary) {
                    try {
                        const boundary = typeof land.boundary === 'string' 
                            ? JSON.parse(land.boundary) 
                            : land.boundary;
                        
                        if (Array.isArray(boundary) && boundary.length > 0) {
                            const polygon = L.polygon(boundary, {
                                color: '#059669',
                                fillColor: '#10b981',
                                fillOpacity: 0.2,
                                weight: 2,
                                dashArray: '5, 5'
                            }).addTo(map);
                            
                            polygon.bindPopup(`
                                <div class="p-2">
                                    <strong class="text-green-900">${land.name}</strong>
                                    <br><span class="text-sm text-gray-600">Land Boundary</span>
                                </div>
                            `);
                        }
                    } catch (e) {
                        console.warn('Invalid boundary data for land:', land.name);
                    }
                }
            });
            
            // Auto-fit map bounds if we have multiple markers
            if (devices.length > 0 || lands.length > 0) {
                const group = new L.featureGroup();
                
                devices.forEach(device => {
                    group.addLayer(L.marker([device.lat, device.lng]));
                });
                
                lands.forEach(land => {
                    group.addLayer(L.marker([land.lat, land.lng]));
                });
                
                if (group.getLayers().length > 1) {
                    map.fitBounds(group.getBounds().pad(0.1));
                }
            }
        });
        </script>
        @endpush
    @else
        <div class="flex items-center justify-center h-32 bg-gray-50 dark:bg-gray-800 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600">
            <div class="text-center">
                <div class="text-4xl mb-2">📍</div>
                <p class="text-gray-500 dark:text-gray-400">No location data available</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Set device coordinates or request GPS location</p>
            </div>
        </div>
    @endif
</div>
