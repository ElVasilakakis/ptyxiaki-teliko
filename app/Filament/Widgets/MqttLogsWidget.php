<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MqttLogsWidget extends Widget
{
    protected static string $view = 'filament.widgets.mqtt-logs-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 3;
    
    public ?string $filter = 'all';
    
    public int $limit = 20;
    
    public function mount(): void
    {
        $this->filter = session('mqtt_logs_filter', 'all');
        $this->limit = session('mqtt_logs_limit', 20);
    }
    
    protected function getViewData(): array
    {
        return [
            'logs' => $this->getLogs(),
            'filter' => $this->filter,
            'limit' => $this->limit,
        ];
    }
    
    public function updateFilter(string $filter): void
    {
        $this->filter = $filter;
        session(['mqtt_logs_filter' => $filter]);
    }
    
    public function updateLimit(int $limit): void
    {
        $this->limit = $limit;
        session(['mqtt_logs_limit' => $limit]);
    }
    
    public function clearLogs(): void
    {
        $logPath = storage_path('logs/mqtt.log');
        
        if (File::exists($logPath)) {
            // Create a backup before clearing
            $backupPath = storage_path('logs/mqtt_' . now()->format('Y-m-d_H-i-s') . '.log.bak');
            File::copy($logPath, $backupPath);
            
            // Clear the log file
            File::put($logPath, "");
        }
    }
    
    protected function getLogs(): array
    {
        $logPath = storage_path('logs/mqtt.log');
        
        if (!File::exists($logPath)) {
            return [];
        }
        
        $logs = [];
        $lines = $this->getTailOfFile($logPath, 500); // Get last 500 lines to filter from
        
        foreach ($lines as $line) {
            // Skip empty lines
            if (empty(trim($line))) {
                continue;
            }
            
            // Parse the log line
            $log = $this->parseLogLine($line);
            
            // Apply filter
            if ($this->filter !== 'all') {
                if ($this->filter === 'error' && $log['level'] !== 'error') {
                    continue;
                } elseif ($this->filter === 'warning' && !in_array($log['level'], ['warning', 'error'])) {
                    continue;
                } elseif ($this->filter === 'info' && $log['level'] !== 'info') {
                    continue;
                } elseif ($this->filter === 'discovery' && !Str::contains($log['message'], ['discovery', 'Discovery'])) {
                    continue;
                } elseif ($this->filter === 'sensor' && !Str::contains($log['message'], ['sensor', 'Sensor'])) {
                    continue;
                }
            }
            
            $logs[] = $log;
            
            // Limit the number of logs
            if (count($logs) >= $this->limit) {
                break;
            }
        }
        
        return array_reverse($logs); // Show newest first
    }
    
    protected function parseLogLine(string $line): array
    {
        $log = [
            'timestamp' => '',
            'level' => 'info',
            'message' => $line,
            'context' => [],
            'raw' => $line,
        ];
        
        // Try to parse JSON format
        if (Str::startsWith(trim($line), '[')) {
            try {
                $data = json_decode($line, true);
                
                if (is_array($data) && isset($data[0]) && isset($data[1]) && isset($data[2])) {
                    $log['timestamp'] = $data[0];
                    $log['level'] = strtolower($data[1]);
                    $log['message'] = $data[2];
                    $log['context'] = $data[3] ?? [];
                }
            } catch (\Exception $e) {
                // If JSON parsing fails, use the raw line
            }
        } else {
            // Try to parse standard Laravel log format
            $pattern = '/\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?(\d*)?(\+\d{2}:\d{2})?)\] (\w+)\.(\w+): (.*?)( \{.*\})?$/';
            
            if (preg_match($pattern, $line, $matches)) {
                $log['timestamp'] = $matches[1];
                $log['level'] = strtolower($matches[5]);
                $log['message'] = $matches[6];
                
                // Try to parse context JSON if available
                if (isset($matches[7])) {
                    try {
                        $log['context'] = json_decode($matches[7], true) ?? [];
                    } catch (\Exception $e) {
                        $log['context'] = [];
                    }
                }
            }
        }
        
        return $log;
    }
    
    protected function getTailOfFile(string $filePath, int $lines): array
    {
        $result = [];
        
        // For Windows compatibility
        if (PHP_OS_FAMILY === 'Windows') {
            $file = new \SplFileObject($filePath, 'r');
            $file->seek(PHP_INT_MAX); // Seek to end of file
            $totalLines = $file->key(); // Get total lines
            
            $startLine = max(0, $totalLines - $lines);
            $currentLine = 0;
            
            $file->rewind(); // Rewind to beginning
            
            while (!$file->eof()) {
                if ($currentLine >= $startLine) {
                    $result[] = $file->current();
                }
                $file->next();
                $currentLine++;
            }
            
            return $result;
        }
        
        // For Unix-like systems, use the tail command for efficiency
        exec("tail -n {$lines} {$filePath}", $result);
        return $result;
    }
}
