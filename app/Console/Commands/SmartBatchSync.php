<?php

namespace App\Console\Commands;

use App\Jobs\FullAcuitySync;
use App\Models\AcuitySyncLog;
use Illuminate\Console\Command;

class SmartBatchSync extends Command
{
    protected $signature = 'acuity:smart-batch 
                            {--priority=normal : Priority level (low|normal|high)}
                            {--limit=50 : Batch size limit}';
    
    protected $description = 'Smart batch processing of Acuity sync jobs';

    public function handle()
    {
        $priority = $this->option('priority');
        $limit = (int)$this->option('limit');
        
        $this->info("🧠 Starting smart batch sync (Priority: {$priority}, Limit: {$limit})");
        
        // Check recent sync activity
        $recentSyncs = AcuitySyncLog::where('created_at', '>=', now()->subMinutes(30))
            ->where('status', 'completed')
            ->count();
            
        if ($recentSyncs > 10) {
            $this->warn('⚡ High sync activity detected - using smaller batches');
            $limit = min($limit, 25);
        }
        
        // Dispatch smart batch job
        $options = [
            'quick' => $priority === 'low',
            'limit' => $limit,
            'priority' => $priority
        ];
        
        FullAcuitySync::dispatch($options)
            ->onQueue($priority === 'high' ? 'acuity-sync-priority' : 'acuity-sync');
            
        $this->info("✅ Smart batch sync job queued with {$limit} item limit");
        
        return Command::SUCCESS;
    }
}