<style>
.sensor-table {
    width: 100%;
    border-collapse: collapse;
    overflow-x: auto;
}

.sensor-table-container {
    overflow-x: auto;
}

.sensor-table th,
.sensor-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.sensor-table th {
    background-color: #f9fafb;
    font-size: 12px;
    font-weight: 500;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sensor-table tr:hover {
    background-color: #f9fafb;
}

.sensor-table tr.critical-row {
    background-color: #fef2f2;
}

.sensor-table td {
    font-size: 14px;
    color: #111827;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 500;
}

.badge-green {
    background-color: #dcfce7;
    color: #166534;
}

.badge-yellow {
    background-color: #fef3c7;
    color: #92400e;
}

.badge-red {
    background-color: #fee2e2;
    color: #991b1b;
}

.badge-blue {
    background-color: #dbeafe;
    color: #1e40af;
}

.badge-purple {
    background-color: #e9d5ff;
    color: #7c2d12;
}

.badge-indigo {
    background-color: #e0e7ff;
    color: #3730a3;
}

.badge-gray {
    background-color: #f3f4f6;
    color: #374151;
}

.sensor-name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.warning-icon {
    width: 16px;
    height: 16px;
    color: #ef4444;
}

.info-icon {
    width: 16px;
    height: 16px;
    color: #eab308;
}

.status-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.threshold-message {
    font-size: 12px;
    color: #dc2626;
}

.threshold-info {
    font-size: 12px;
}

.threshold-info div {
    color: #6b7280;
}

.active-icon {
    width: 20px;
    height: 20px;
}

.active-icon.green {
    color: #10b981;
}

.active-icon.red {
    color: #ef4444;
}

.no-sensors {
    text-align: center;
    padding: 32px;
    color: #6b7280;
}

@media (prefers-color-scheme: dark) {
    .sensor-table th {
        background-color: #1f2937;
        color: #9ca3af;
    }
    
    .sensor-table tr:hover {
        background-color: #1f2937;
    }
    
    .sensor-table tr.critical-row {
        background-color: #7f1d1d;
    }
    
    .sensor-table td {
        color: #f9fafb;
    }
    
    .badge-green {
        background-color: #14532d;
        color: #bbf7d0;
    }
    
    .badge-yellow {
        background-color: #78350f;
        color: #fde68a;
    }
    
    .badge-red {
        background-color: #7f1d1d;
        color: #fecaca;
    }
    
    .badge-blue {
        background-color: #1e3a8a;
        color: #bfdbfe;
    }
    
    .badge-purple {
        background-color: #581c87;
        color: #ddd6fe;
    }
    
    .badge-indigo {
        background-color: #312e81;
        color: #c7d2fe;
    }
    
    .badge-gray {
        background-color: #1f2937;
        color: #d1d5db;
    }
    
    .threshold-info div {
        color: #9ca3af;
    }
    
    .no-sensors {
        color: #9ca3af;
    }
}
</style>

<div class="sensor-table-container">
    @if($getRecord()->sensors && $getRecord()->sensors->count() > 0)
        <table class="sensor-table">
            <thead>
                <tr>
                    <th>Sensor Name</th>
                    <th>Type</th>
                    <th>Current Value</th>
                    <th>Status</th>
                    <th>Geofence/Thresholds</th>
                    <th>Last Reading</th>
                    <th>Active</th>
                    <th>Location</th>
                    <th>Unit</th>
                    <th>Accuracy</th>
                </tr>
            </thead>
            <tbody>
                @foreach($getRecord()->sensors as $sensor)
                    @php
                        // Check if sensor needs thresholds (non-GPS sensors only)
                        $needsThresholds = $sensor->sensor_type !== 'gps';
                        $thresholds = $sensor->thresholds;
                        $hasThresholds = $thresholds && is_array($thresholds) && (isset($thresholds['min']) || isset($thresholds['max']));
                        $isOutOfThreshold = false;
                        $thresholdMessage = '';
                        
                        // For GPS sensors, check geofencing
                        $isOutsideGeofence = false;
                        $geofenceMessage = '';
                        
                        if ($sensor->sensor_type === 'gps') {
                            $isOutsideGeofence = !$sensor->isInsideGeofence();
                            if ($isOutsideGeofence) {
                                $geofenceMessage = "Outside land boundary";
                            }
                        } else {
                            // For non-GPS sensors, check thresholds
                            if ($hasThresholds && $sensor->value !== null) {
                                $min = $thresholds['min'] ?? null;
                                $max = $thresholds['max'] ?? null;
                                
                                if ($min !== null && $sensor->value < $min) {
                                    $isOutOfThreshold = true;
                                    $thresholdMessage = "Below minimum ({$min})";
                                } elseif ($max !== null && $sensor->value > $max) {
                                    $isOutOfThreshold = true;
                                    $thresholdMessage = "Above maximum ({$max})";
                                }
                            }
                        }
                        
                        // Determine overall status
                        $isCritical = $isOutOfThreshold || $isOutsideGeofence;
                        
                        // Custom status display logic
                        $displayStatus = $sensor->status;
                        $statusColor = '';
                        
                        if ($isCritical) {
                            $displayStatus = 'Critical';
                            $statusColor = 'badge-red';
                        } else {
                            switch ($sensor->status) {
                                case 'normal':
                                    $displayStatus = 'Normal';
                                    $statusColor = 'badge-green';
                                    break;
                                case 'warning':
                                    $displayStatus = 'Adjusted';
                                    $statusColor = 'badge-yellow';
                                    break;
                                case 'critical':
                                    $displayStatus = 'Critical';
                                    $statusColor = 'badge-red';
                                    break;
                                default:
                                    $displayStatus = ucfirst($sensor->status);
                                    $statusColor = 'badge-gray';
                            }
                        }
                        
                        // Sensor type color
                        $typeColor = match($sensor->sensor_type) {
                            'temperature' => 'badge-green',
                            'humidity' => 'badge-blue',
                            'light' => 'badge-yellow',
                            'signal' => 'badge-red',
                            'battery' => 'badge-purple',
                            'gps' => 'badge-indigo',
                            default => 'badge-gray'
                        };
                    @endphp
                    
                    <tr class="{{ $isCritical ? 'critical-row' : '' }}">
                        <td>
                            <div class="sensor-name">
                                {{ $sensor->sensor_name }}
                                @if($isCritical)
                                    <svg class="warning-icon" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                    </svg>
                                @elseif($needsThresholds && !$hasThresholds)
                                    <svg class="info-icon" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $typeColor }}">
                                {{ ucfirst($sensor->sensor_type) }}
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $statusColor }}">
                                @if($sensor->sensor_type === 'gps')
                                    @php
                                        $gpsData = json_decode($sensor->value, true);
                                    @endphp
                                    @if(is_array($gpsData) && isset($gpsData['lat']) && isset($gpsData['lng']))
                                        {{ number_format($gpsData['lat'], 6) }}, {{ number_format($gpsData['lng'], 6) }}
                                    @elseif(isset($sensor->raw_value))
                                        {{ $sensor->raw_value }}
                                    @elseif(is_numeric($sensor->value))
                                        {{ number_format((float)$sensor->value, 6, '.', '') }}
                                    @else
                                        {{ $sensor->value }}
                                    @endif
                                @else
                                    {{ $sensor->formatted_value }}
                                @endif
                            </span>
                        </td>
                        <td>
                            <div class="status-container">
                                <span class="badge {{ $statusColor }}">
                                    {{ $displayStatus }}
                                </span>
                                @if($isOutsideGeofence)
                                    <span class="threshold-message">{{ $geofenceMessage }}</span>
                                @elseif($isOutOfThreshold)
                                    <span class="threshold-message">{{ $thresholdMessage }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if($sensor->sensor_type === 'gps')
                                @if($sensor->device && $sensor->device->land)
                                    <div class="threshold-info">
                                        <div>Geofence: {{ $sensor->device->land->land_name }}</div>
                                        <div>Status: {{ $isOutsideGeofence ? 'Outside' : 'Inside' }}</div>
                                    </div>
                                @else
                                    <span class="badge badge-yellow">
                                        No land assigned
                                    </span>
                                @endif
                            @else
                                @if($hasThresholds)
                                    @php
                                        $min = $thresholds['min'] ?? 'N/A';
                                        $max = $thresholds['max'] ?? 'N/A';
                                    @endphp
                                    <div class="threshold-info">
                                        <div>Min: {{ $min }}</div>
                                        <div>Max: {{ $max }}</div>
                                    </div>
                                @else
                                    <span class="badge badge-yellow">
                                        Please adjust thresholds
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td>
                            {{ $sensor->reading_timestamp ? \Carbon\Carbon::parse($sensor->reading_timestamp)->diffForHumans() : 'Never' }}
                        </td>
                        <td>
                            @if($sensor->enabled)
                                <svg class="active-icon green" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            @else
                                <svg class="active-icon red" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                            @endif
                        </td>
                        <td>{{ $sensor->location ?? 'N/A' }}</td>
                        <td>{{ $sensor->unit ?? 'N/A' }}</td>
                        <td>{{ $sensor->accuracy ? $sensor->accuracy . '%' : 'N/A' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-sensors">
            No sensors connected to this device.
        </div>
    @endif
</div>
