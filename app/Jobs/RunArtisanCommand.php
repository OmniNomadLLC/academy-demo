<?php

namespace App\Jobs;

use App\Models\SyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunArtisanCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 60 minutes for long syncs

    public string $signature;
    public array $options;
    public ?int $ranBy;
    public ?int $logId;

    public function __construct(string $signature, array $options = [], ?int $ranBy = null, ?int $logId = null)
    {
        $this->signature = $signature;
        $this->options = $options;
        $this->ranBy = $ranBy;
        $this->logId = $logId;
    }

    public function handle(): void
    {
        try {
            @ini_set('max_execution_time', '0');
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
        } catch (\Throwable $e) {
            // Ignore environment restrictions; queue workers may not allow changing limits.
        }

        $log = $this->prepareLog();

        try {
            $exit = Artisan::call($this->signature, $this->options);
            $output = Artisan::output();
            if (is_string($output) && strlen($output) > 65536) {
                $output = substr($output, 0, 65536) . "\n... (truncated)";
            }
            $log->update([
                'status' => $exit === 0 ? 'success' : 'error',
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $log->update([
                'status' => 'error',
                'output' => $msg,
            ]);
            Log::error('RunArtisanCommand failed', [
                'command' => $this->signature,
                'options' => $this->options,
                'error' => $msg,
            ]);
            throw $e;
        }
    }

    protected function prepareLog(): SyncLog
    {
        if ($this->logId) {
            $log = SyncLog::find($this->logId);
            if ($log) {
                $log->update([
                    'status' => 'running',
                    'output' => null,
                ]);

                return $log;
            }
        }

        $log = SyncLog::create([
            'command' => $this->signature,
            'params' => $this->options,
            'status' => 'running',
            'ran_by' => $this->ranBy,
        ]);

        $this->logId = $log->id;

        return $log;
    }
}
