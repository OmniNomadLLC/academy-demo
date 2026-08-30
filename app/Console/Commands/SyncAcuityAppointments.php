<?php

namespace App\Console\Commands;

use App\Models\AcuitySyncLog;
use App\Services\Acuity\AppointmentSliceImporter;
use App\Services\Acuity\AppointmentSliceOptions;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncAcuityAppointments extends Command
{
    protected $signature = 'acuity:sync-appointments 
                            {--days=30 : Days in the past to include}
                            {--forward=60 : Days in the future to include}
                            {--limit=200 : Maximum appointments to fetch (0 = unlimited)}
                            {--sliceDays=7 : Slice window size in days}
                            {--pageSize=100 : Page size per API request}
                            {--maxRetries=5 : Max HTTP retries}
                            {--retryBaseMs=500 : Base backoff milliseconds}
                            {--dryRun : If set, fetch and log counts but do not write to DB}
                            {--sliceIndex= : 1-based slice index to process only that slice}
                            {--from= : Explicit start date (YYYY-MM-DD)}
                            {--to= : Explicit end date (YYYY-MM-DD)}
                            {--linkAfterSlice : Run email link backfill after each processed slice}
                            {--calendarId= : Limit sync to a specific Acuity calendar ID}';

    protected $description = 'Sync appointments from Acuity to Class Sessions';

    public function handle()
    {
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        @ini_set('default_socket_timeout', '60');
        if (function_exists('set_time_limit')) { @set_time_limit(0); }

        $this->info('🔄 Starting Acuity appointments sync...');

        $syncLog = AcuitySyncLog::create([
            'sync_type' => 'appointments',
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $importer = app(AppointmentSliceImporter::class);
            $days = (int) $this->option('days');
            $forward = (int) $this->option('forward');
            $limit = max(0, (int) $this->option('limit'));
            $sliceDays = max(1, (int) $this->option('sliceDays'));
            $pageSize = (int) $this->option('pageSize');
            $maxRetries = (int) $this->option('maxRetries');
            $retryBaseMs = (int) $this->option('retryBaseMs');
            $dryRun = (bool) $this->option('dryRun');
            $linkAfterSlice = (bool) $this->option('linkAfterSlice');
            $calendarIdOption = $this->option('calendarId');
            $calendarId = null;
            if ($calendarIdOption !== null && $calendarIdOption !== '') {
                if (! is_numeric($calendarIdOption)) {
                    $this->error('The --calendarId option must be numeric.');
                    return Command::FAILURE;
                }

                $calendarId = (int) $calendarIdOption;
                $this->info('Filtering to calendar ID '.$calendarId);
            }

            if ($pageSize < 25) {
                $pageSize = 25;
            }
            if ($pageSize > 500) {
                $pageSize = 500;
            }
            if ($maxRetries < 0) {
                $maxRetries = 0;
            }
            if ($maxRetries > 10) {
                $maxRetries = 10;
            }
            if ($retryBaseMs < 0) {
                $retryBaseMs = 0;
            }
            if ($retryBaseMs > 5000) {
                $retryBaseMs = 5000;
            }

            $fromOpt = $this->option('from');
            $toOpt = $this->option('to');
            if ($fromOpt && $toOpt) {
                $windowStart = Carbon::parse($fromOpt)->startOfDay();
                $windowEnd = Carbon::parse($toOpt)->endOfDay();
            } else {
                $windowStart = now()->subDays($days)->startOfDay();
                $windowEnd = now()->addDays($forward)->endOfDay();
            }

            $created = 0;
            $updated = 0;
            $errors = 0;
            $matchedById = 0;
            $matchedByEmail = 0;
            $storedUnlinked = 0;
            $totalFetched = 0;
            $totalRetries = 0;

            $slices = [];
            $cursor = clone $windowStart;
            while ($cursor < $windowEnd) {
                $end = (clone $cursor)->addDays($sliceDays)->subSecond();
                if ($end > $windowEnd) {
                    $end = clone $windowEnd;
                }
                $slices[] = [$cursor->copy(), $end->copy()];
                $cursor = (clone $end)->addSecond();
            }

            $sliceTotal = count($slices);
            $singleIndex = (int) ($this->option('sliceIndex') ?? 0);
            $this->info('total_slices=' . $sliceTotal . ', single_index=' . ($singleIndex ?: 0));

            foreach ($slices as $i => [$sliceFrom, $sliceTo]) {
                $sliceIndex = $i + 1;
                $isTarget = $singleIndex === 0 || $singleIndex === $sliceIndex;
                if (! $isTarget) {
                    continue;
                }

                if (function_exists('set_time_limit')) {
                    @set_time_limit(0);
                }

                $minDate = $sliceFrom->format('Y-m-d');
                $maxDate = $sliceTo->format('Y-m-d');
                $this->info("📥 Slice {$sliceIndex}/{$sliceTotal}: {$minDate} .. {$maxDate}");

                $result = $importer->import(new AppointmentSliceOptions(
                    minDate: $minDate,
                    maxDate: $maxDate,
                    pageSize: $pageSize,
                    maxRetries: $maxRetries,
                    retryBaseMs: $retryBaseMs,
                    dryRun: $dryRun,
                    linkAfterSlice: $linkAfterSlice,
                    limit: $limit > 0 ? $limit : null,
                    alreadyFetched: $totalFetched,
                    calendarId: $calendarId,
                ));

                $totalFetched += $result->fetched;
                $created += $result->created;
                $updated += $result->updated;
                $storedUnlinked += $result->unlinked;
                $matchedByEmail += $result->matchedByEmail;
                $matchedById += $result->matchedById;
                $errors += $result->errors;
                $totalRetries += $result->retries;

                $this->info(sprintf(
                    '[slice %d/%d] range=%s..%s fetched=%d created=%d updated=%d unlinked=%d retries=%d duration_ms=%d pageSize=%d maxRetries=%d retryBaseMs=%d%s',
                    $sliceIndex,
                    $sliceTotal,
                    $minDate,
                    $maxDate,
                    $result->fetched,
                    $result->created,
                    $result->updated,
                    $result->unlinked,
                    $result->retries,
                    $result->durationMs,
                    $pageSize,
                    $maxRetries,
                    $retryBaseMs,
                    $dryRun ? ' dryRun=1' : ''
                ));

                if ($limit > 0 && $totalFetched >= $limit) {
                    break;
                }

                if ($singleIndex > 0 && $sliceIndex === $singleIndex) {
                    break;
                }
            }

            $syncLog->update([
                'status' => 'completed',
                'completed_at' => now(),
                'records_processed' => $totalFetched,
                'records_created' => $created,
                'records_updated' => $updated,
                'error_message' => $errors > 0 ? "{$errors} appointment(s) had processing errors" : null,
                'sync_data' => [
                    'total_retries' => $totalRetries,
                    'matched_email' => $matchedByEmail,
                    'matched_id' => $matchedById,
                    'unlinked' => $storedUnlinked,
                ],
            ]);

            $this->info('✅ Sync completed successfully!');
            $this->info("📈 Created: {$created} sessions");
            $this->info("🔄 Updated: {$updated} sessions");
            $this->info("🔎 Matched by email: {$matchedByEmail}, by ID: {$matchedById}, unlinked: {$storedUnlinked}");
            if ($errors > 0) {
                $this->warn("⚠️  Errors: {$errors} appointment(s) could not be processed");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if (isset($syncLog)) {
                $syncLog->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }
            \Log::error('SyncAcuityAppointments failed', ['exception' => $e]);
            $this->error('Sync failed: '.$e->getMessage());
            return Command::FAILURE;
        }
    }

}
