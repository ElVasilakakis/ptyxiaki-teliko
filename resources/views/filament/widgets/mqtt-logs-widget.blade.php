<x-filament::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold tracking-tight">MQTT Logs</h2>
            
            <div class="flex items-center space-x-2">
                <select wire:model.live="filter" wire:change="updateFilter($event.target.value)" class="text-sm border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                    <option value="all">All Logs</option>
                    <option value="error">Errors Only</option>
                    <option value="warning">Warnings & Errors</option>
                    <option value="info">Info Only</option>
                    <option value="discovery">Discovery Logs</option>
                    <option value="sensor">Sensor Logs</option>
                </select>
                
                <select wire:model.live="limit" wire:change="updateLimit($event.target.value)" class="text-sm border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                    <option value="10">10 entries</option>
                    <option value="20">20 entries</option>
                    <option value="50">50 entries</option>
                    <option value="100">100 entries</option>
                </select>
                
                <button wire:click="$refresh" class="inline-flex items-center justify-center font-medium rounded-lg border transition-colors focus:outline-none focus:ring-offset-2 focus:ring-2 focus:ring-inset filament-button h-9 px-4 text-sm text-white shadow focus:ring-white border-transparent bg-primary-600 hover:bg-primary-500 focus:bg-primary-700 focus:ring-offset-primary-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-1">Refresh</span>
                </button>
                
                <button wire:click="clearLogs" wire:confirm="Are you sure you want to clear the logs? A backup will be created." class="inline-flex items-center justify-center font-medium rounded-lg border transition-colors focus:outline-none focus:ring-offset-2 focus:ring-2 focus:ring-inset filament-button h-9 px-4 text-sm text-white shadow focus:ring-white border-transparent bg-danger-600 hover:bg-danger-500 focus:bg-danger-700 focus:ring-offset-danger-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-1">Clear Logs</span>
                </button>
            </div>
        </div>
        
        <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-300">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">Time</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Level</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Message</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Context</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse($logs as $log)
                            <tr>
                                <td class="whitespace-nowrap py-2 pl-4 pr-3 text-sm text-gray-500 sm:pl-6">
                                    {{ $log['timestamp'] }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset 
                                        @if($log['level'] === 'error')
                                            text-red-700 bg-red-50 ring-red-600/20
                                        @elseif($log['level'] === 'warning')
                                            text-yellow-700 bg-yellow-50 ring-yellow-600/20
                                        @elseif($log['level'] === 'info')
                                            text-blue-700 bg-blue-50 ring-blue-600/20
                                        @else
                                            text-gray-700 bg-gray-50 ring-gray-600/20
                                        @endif
                                    ">
                                        {{ ucfirst($log['level']) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-500">
                                    {{ $log['message'] }}
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-500">
                                    @if(!empty($log['context']))
                                        <details>
                                            <summary class="cursor-pointer text-primary-600 hover:text-primary-500">View Details</summary>
                                            <div class="mt-2 p-2 bg-gray-50 rounded text-xs overflow-x-auto">
                                                <pre>{{ json_encode($log['context'], JSON_PRETTY_PRINT) }}</pre>
                                            </div>
                                        </details>
                                    @else
                                        <span class="text-gray-400">No context</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-4 text-sm text-gray-500 text-center">
                                    No logs found. Try changing the filter or refreshing.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mt-4 text-sm text-gray-500">
            Showing {{ count($logs) }} of {{ $limit }} logs (filtered by: {{ ucfirst($filter) }})
        </div>
    </x-filament::section>
</x-filament::widget>
