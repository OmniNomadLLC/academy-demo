<?php

namespace App\Jobs;

use App\Services\AcuityService;
use App\Models\AcuitySyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class FullAcuitySync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public int $backoff = 1;

    protected $options;

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    public function handle()
    {
        $this->info('🚀 Starting full Acuity sync job...');
        
        $syncLog = AcuitySyncLog::create([
            'sync_type' => 'full_sync_job',
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $isQuick = $this->options['quick'] ?? false;
            
            if ($isQuick) {
                Log::info('⚡ Running quick full sync job...');
                
                // Quick sync - smaller limits
                Artisan::call('acuity:sync-clients', ['--limit' => 50]);
                Artisan::call('acuity:sync-appointments', ['--days' => 7, '--limit' => 50]);
                
            } else {
                Log::info('🔄 Running complete full sync job...');
                
                // Full sync - larger limits
                Artisan::call('acuity:sync-clients', ['--limit' => 200]);
                Artisan::call('acuity:sync-appointments', ['--days' => 30, '--limit' => 200]);
            }
            
            $syncLog->update([
                'status' => 'completed',
                'completed_at' => now(),
                'records_processed' => 1, // Job completed
            ]);
            
            Log::info('✅ Full sync job completed successfully!');
            
        } catch (\Exception $e) {
            $syncLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            
            Log::error('❌ Full sync job failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function info($message)
    {
        Log::info($message);
    }
}
