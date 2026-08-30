<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use App\Jobs\SyncAcuityAppointment;
use App\Jobs\SyncAcuityClient;
use App\Models\AcuitySyncLog;
use Illuminate\Console\Command;

class IncrementalAcuitySync extends Command
{
    protected $signature = 'acuity:sync-incremental';
    protected $description = 'Sync only recent changes from Acuity';

    public function handle()
    {
        $this->info('🔄 Starting incremental sync...');
        
        $syncLog = AcuitySyncLog::create([
            'sync_type' => 'incremental',
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $acuity = new AcuityService();

            $windowStart = now()->subDay();
            $perPage = (int) env('ACUITY_INCREMENTAL_PAGE_SIZE', 200);
            if ($perPage < 25) { $perPage = 25; }
            if ($perPage > 500) { $perPage = 500; }

            $maxPages = (int) env('ACUITY_INCREMENTAL_MAX_PAGES', 40);
            if ($maxPages < 1) { $maxPages = 1; }

            $jobsQueued = 0;
            $filterUsed = null;

            $queryStrategies = [
                [
                    'label' => 'lastUpdated',
                    'params' => ['lastUpdated' => $windowStart->copy()->toIso8601String()],
                ],
                [
                    'label' => 'modifiedSince',
                    'params' => ['modifiedSince' => $windowStart->copy()->toIso8601String()],
                ],
                [
                    'label' => 'dateRange',
                    'params' => [
                        'minDate' => $windowStart->copy()->toDateString(),
                        'maxDate' => now()->copy()->addDays(7)->toDateString(),
                    ],
                ],
            ];

            foreach ($queryStrategies as $strategy) {
                $page = 1;
                $totalForStrategy = 0;
                $label = $strategy['label'];
                $params = $strategy['params'];

                $this->info(sprintf('🔍 Fetching incremental appointments via `%s` filter', $label));

                do {
                    $batch = $acuity->fetchAppointmentsPage($params, $page, $perPage);
                    $count = is_array($batch) ? count($batch) : 0;

                    if ($count === 0) {
                        if ($page === 1) {
                            $this->info(sprintf('ℹ️ No appointments returned for `%s` filter', $label));
                        }
                        break;
                    }

                    foreach ($batch as $appointment) {
                        if (!isset($appointment['id'])) {
                            continue;
                        }
                        SyncAcuityAppointment::dispatch($appointment['id'])->onQueue('acuity-sync');
                        $jobsQueued++;
                        $totalForStrategy++;
                    }

                    if ($count < $perPage) {
                        break;
                    }

                    if ($page >= $maxPages) {
                        $this->warn(sprintf('⚠️ Reached page cap (%d) for `%s` filter; stopping early to avoid timeouts.', $maxPages, $label));
                        break;
                    }

                    $page++;
                } while (true);

                if ($totalForStrategy > 0) {
                    $filterUsed = $label;
                    break;
                }
            }

            $syncLog->update([
                'status' => 'completed',
                'completed_at' => now(),
                'records_processed' => $jobsQueued,
            ]);

            if ($jobsQueued === 0) {
                $this->info('✅ No appointment changes detected in the last 24 hours.');
            } else {
                $this->info(sprintf('✅ Queued %d appointment sync jobs (%s filter).', $jobsQueued, $filterUsed ?? 'unknown'));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $syncLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            
            $this->error('❌ Sync failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
