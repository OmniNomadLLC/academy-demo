<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAcuityImportRun;
use App\Models\AcuityImportRun;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class QueueAcuityImport extends Command
{
    protected $signature = 'acuity:import-appointments
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}
                            {--sliceDays=7 : Number of days per slice}
                            {--pageSize=100 : Page size per API request}
                            {--maxRetries=5 : Max HTTP retries}
                            {--retryBaseMs=500 : Base backoff milliseconds}
                            {--limit=0 : Optional cap on total appointments}
                            {--dryRun : Do not persist class sessions}
                            {--linkAfterSlice : Run link backfill after each slice (placeholder)}';

    protected $description = 'Queue a multi-slice Acuity appointments import run';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('Both --from and --to options are required.');
            return Command::FAILURE;
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        if ($start->isAfter($end)) {
            $this->error('The --from date must be before --to.');
            return Command::FAILURE;
        }

        $sliceDays = max(1, (int) $this->option('sliceDays'));
        $pageSize = max(25, min(500, (int) $this->option('pageSize')));
        $maxRetries = max(0, min(10, (int) $this->option('maxRetries')));
        $retryBaseMs = max(0, min(5000, (int) $this->option('retryBaseMs')));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dryRun');
        $linkAfterSlice = (bool) $this->option('linkAfterSlice');

        $totalDays = $start->diffInDays($end) + 1;
        $totalSlices = (int) ceil($totalDays / $sliceDays);

        $run = AcuityImportRun::create([
            'status' => AcuityImportRun::STATUS_PENDING,
            'window_start' => $start->toDateString(),
            'window_end' => $end->toDateString(),
            'slice_days' => $sliceDays,
            'page_size' => $pageSize,
            'max_retries' => $maxRetries,
            'retry_base_ms' => $retryBaseMs,
            'limit' => $limit > 0 ? $limit : null,
            'dry_run' => $dryRun,
            'link_after_slice' => $linkAfterSlice,
            'total_slices' => $totalSlices,
            'queued_by' => Auth::id(),
        ]);

        ProcessAcuityImportRun::dispatch($run->id);

        $this->info(sprintf(
            'Queued Acuity import run %d covering %s → %s in %d slice(s).',
            $run->id,
            $start->toDateString(),
            $end->toDateString(),
            $totalSlices
        ));

        return Command::SUCCESS;
    }
}
