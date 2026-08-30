<?php

namespace App\Jobs;

use App\Models\AcuityImportRun;
use App\Services\Acuity\AppointmentSliceImporter;
use App\Services\Acuity\AppointmentSliceOptions;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAcuityImportRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    private const SLICE_DELAY_SECONDS = 2;

    public function __construct(public int $runId)
    {
        $this->onQueue('acuity');
    }

    public function handle(AppointmentSliceImporter $importer): void
    {
        $lockData = DB::transaction(function () {
            $run = AcuityImportRun::whereKey($this->runId)->lockForUpdate()->first();

            if (! $run) {
                return null;
            }

            if ($run->isFinished() || $run->status === AcuityImportRun::STATUS_CANCELLED) {
                return null;
            }

            if ($run->status === AcuityImportRun::STATUS_PAUSED) {
                return null;
            }

            $windowEnd = CarbonImmutable::parse($run->window_end)->endOfDay();
            $cursor = $run->next_cursor
                ? CarbonImmutable::parse($run->next_cursor)
                : CarbonImmutable::parse($run->window_start)->startOfDay();

            if ($cursor > $windowEnd) {
                $run->markCompleted();
                return null;
            }

            $sliceEnd = $cursor->addDays(max(1, (int) $run->slice_days))->subSecond();
            if ($sliceEnd > $windowEnd) {
                $sliceEnd = $windowEnd;
            }

            $sliceIndex = (int) $run->processed_slices + 1;

            $run->forceFill([
                'status' => AcuityImportRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?: now(),
                'current_slice_start' => $cursor,
                'current_slice_end' => $sliceEnd,
                'current_slice_index' => $sliceIndex,
                'last_activity_at' => now(),
            ])->save();

            return [
                'run' => $run->fresh(),
                'cursor' => $cursor,
                'slice_end' => $sliceEnd,
                'slice_index' => $sliceIndex,
            ];
        });

        if (! $lockData) {
            return;
        }

        /** @var AcuityImportRun $run */
        $run = $lockData['run'];
        $cursor = $lockData['cursor'];
        $sliceEnd = $lockData['slice_end'];
        $sliceIndex = $lockData['slice_index'];

        $minDate = $cursor->toDateString();
        $maxDate = $sliceEnd->toDateString();

        // Log::warning so the entry survives any LOG_LEVEL >= warning. Staging
        // runs LOG_LEVEL=warning (set 2026-04-24 to make scheduler-heartbeat
        // visible); a Log::info here would be silently dropped, leaving
        // post-mortem debuggers blind to job-bootstrap timing — exactly the
        // problem we hit on 2026-04-27 when a stuck worker emitted no entries
        // for hours and we mistakenly hypothesised a pre-fetch hang.
        Log::warning('ProcessAcuityImportRun slice started', [
            'run_id' => $this->runId,
            'slice_index' => $sliceIndex,
            'min_date' => $minDate,
            'max_date' => $maxDate,
        ]);

        try {
            $result = $importer->import(new AppointmentSliceOptions(
                minDate: $minDate,
                maxDate: $maxDate,
                pageSize: (int) $run->page_size,
                maxRetries: (int) $run->max_retries,
                retryBaseMs: (int) $run->retry_base_ms,
                dryRun: (bool) $run->dry_run,
                linkAfterSlice: (bool) $run->link_after_slice,
                limit: $run->limit ?: null,
                alreadyFetched: (int) $run->fetched_count,
            ));
        } catch (\Throwable $e) {
            DB::transaction(function () use ($e) {
                $run = AcuityImportRun::whereKey($this->runId)->lockForUpdate()->first();
                if ($run) {
                    $run->markFailed($e->getMessage());
                }
            });

            Log::error('ProcessAcuityImportRun failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::warning('ProcessAcuityImportRun slice completed', [
            'run_id' => $this->runId,
            'slice_index' => $sliceIndex,
            'min_date' => $minDate,
            'max_date' => $maxDate,
            'fetched' => $result->fetched,
            'created' => $result->created,
            'updated' => $result->updated,
            'errors' => $result->errors,
            'duration_ms' => $result->durationMs,
        ]);

        $nextCursor = $sliceEnd->addSecond();

        $run = DB::transaction(function () use ($result, $nextCursor) {
            $run = AcuityImportRun::whereKey($this->runId)->lockForUpdate()->first();
            if (! $run) {
                return null;
            }

            $run->forceFill([
                'processed_slices' => (int) $run->processed_slices + 1,
                'fetched_count' => (int) $run->fetched_count + $result->fetched,
                'created_count' => (int) $run->created_count + $result->created,
                'updated_count' => (int) $run->updated_count + $result->updated,
                'unlinked_count' => (int) $run->unlinked_count + $result->unlinked,
                'matched_email_count' => (int) $run->matched_email_count + $result->matchedByEmail,
                'matched_id_count' => (int) $run->matched_id_count + $result->matchedById,
                'error_count' => (int) $run->error_count + $result->errors,
                'retries' => (int) $run->retries + $result->retries,
                'last_activity_at' => now(),
                'current_slice_index' => null,
                'current_slice_start' => null,
                'current_slice_end' => null,
            ])->save();

            $windowEnd = CarbonImmutable::parse($run->window_end)->endOfDay();
            $shouldFinish = false;

            if ($run->limit && $run->fetched_count >= $run->limit) {
                $shouldFinish = true;
            } elseif ($nextCursor > $windowEnd) {
                $shouldFinish = true;
            }

            if ($shouldFinish) {
                $run->markCompleted();
                return $run->fresh();
            }

            $run->forceFill([
                'next_cursor' => $nextCursor,
            ])->save();

            return $run->fresh();
        });

        if (! $run) {
            return;
        }

        if ($run->isFinished() || in_array($run->status, [AcuityImportRun::STATUS_PAUSED, AcuityImportRun::STATUS_CANCELLED], true)) {
            return;
        }

        self::dispatch($run->id)->onQueue('acuity')->delay(now()->addSeconds(self::SLICE_DELAY_SECONDS));
    }
}
