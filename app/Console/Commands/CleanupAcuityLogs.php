<?php

namespace App\Console\Commands;

use App\Models\AcuitySyncLog;
use Illuminate\Console\Command;

class CleanupAcuityLogs extends Command
{
    protected $signature = 'acuity:cleanup-logs';
    protected $description = 'Clean up old Acuity sync logs';

    public function handle()
    {
        $deleted = AcuitySyncLog::where('created_at', '<', now()->subDays(30))->delete();
        
        $this->info("Cleaned up {$deleted} old sync log entries.");
        
        return Command::SUCCESS;
    }
}