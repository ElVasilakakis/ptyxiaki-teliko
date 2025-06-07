<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ViewMqttLogs extends Command
{
    protected $signature = 'mqtt:logs {--lines=50 : Number of lines to display} {--follow : Keep watching for new logs}';
    protected $description = 'View MQTT logs with filtering options';

    public function handle()
    {
        $logPath = storage_path('logs/mqtt.log');
        $lines = $this->option('lines');
        $follow = $this->option('follow');
        
        if (!File::exists($logPath)) {
            $this->error("MQTT log file not found at: {$logPath}");
            return Command::FAILURE;
        }
        
        $this->info("Displaying last {$lines} lines of MQTT logs:");
        $this->newLine();
        
        // Display initial logs
        $this->displayLogs($logPath, $lines);
        
        // If follow option is enabled, keep watching for new logs
        if ($follow) {
            $this->info("Watching for new logs (press Ctrl+C to stop)...");
            $this->newLine();
            
            $lastSize = filesize($logPath);
            
            while (true) {
                clearstatcache(true, $logPath);
                $currentSize = filesize($logPath);
                
                if ($currentSize > $lastSize) {
                    $newContent = file_get_contents($logPath, false, null, $lastSize, $currentSize - $lastSize);
                    $this->line($newContent);
                    $lastSize = $currentSize;
                }
                
                sleep(1);
            }
        }
        
        return Command::SUCCESS;
    }
    
    protected function displayLogs(string $logPath, int $lines)
    {
        // Use tail command on Unix-like systems for efficiency
        if (PHP_OS_FAMILY !== 'Windows') {
            system("tail -n {$lines} {$logPath}");
            return;
        }
        
        // Fallback for Windows
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX); // Seek to end of file
        $totalLines = $file->key(); // Get total lines
        
        $startLine = max(0, $totalLines - $lines);
        $currentLine = 0;
        
        $file->rewind(); // Rewind to beginning
        
        while (!$file->eof()) {
            if ($currentLine >= $startLine) {
                $this->line($file->current());
            }
            $file->next();
            $currentLine++;
        }
    }
}
