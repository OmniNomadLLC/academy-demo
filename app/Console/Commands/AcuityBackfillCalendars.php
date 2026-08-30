<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

class AcuityBackfillCalendars extends Command
{
    protected $signature = 'acuity:backfill-calendars
        {--from=2025-06-01 : Start date (YYYY-MM-DD)}
        {--to=2025-12-31 : End date (YYYY-MM-DD)}
        {--sliceDays=7 : Number of days per slice for appointment sync}
        {--pageSize=100 : Page size per Acuity request}
        {--maxRetries=5 : Max HTTP retries per slice}
        {--retryBaseMs=500 : Base backoff in milliseconds}
        {--dryRun : Fetch data but skip database writes and client sync}
        {--skipClients : Skip the final client sync step}
        {--includeNameless : Include calendars that have no name/label (defaults to skip)}';

    protected $description = 'Retrieve all Acuity calendars, import appointments for a date range, then sync students.';

    public function handle(AcuityService $acuity): int
    {
        $fromInput = $this->option('from') ?: '2025-06-01';
        $toInput = $this->option('to') ?: '2025-12-31';

        try {
            $from = CarbonImmutable::parse($fromInput)->startOfDay();
            $to = CarbonImmutable::parse($toInput)->endOfDay();
        } catch (\Throwable $e) {
            $this->error('Invalid --from or --to date provided.');
            return Command::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');
            return Command::FAILURE;
        }

        $sliceDays = max(1, (int) $this->option('sliceDays'));
        $pageSize = max(25, min(500, (int) $this->option('pageSize')));
        $maxRetries = max(0, min(10, (int) $this->option('maxRetries')));
        $retryBaseMs = max(0, min(5000, (int) $this->option('retryBaseMs')));
        $dryRun = (bool) $this->option('dryRun');
        $skipClients = (bool) $this->option('skipClients');
        $includeNameless = (bool) $this->option('includeNameless');

        $this->info(sprintf(
            'Fetching Acuity calendars for window %s → %s',
            $from->toDateString(),
            $to->toDateString()
        ));

        try {
            $rawCalendars = $acuity->getCalendars();
        } catch (\Throwable $e) {
            $this->error('Failed to retrieve calendars: '.$e->getMessage());
            return Command::FAILURE;
        }

        $calendars = collect($rawCalendars)
            ->map(function (array $calendar) {
                $id = Arr::get($calendar, 'id')
                    ?? Arr::get($calendar, 'calendarID')
                    ?? Arr::get($calendar, 'calendarId')
                    ?? Arr::get($calendar, 'ID');

                $name = trim((string) (
                    Arr::get($calendar, 'name')
                    ?? Arr::get($calendar, 'calendar')
                    ?? Arr::get($calendar, 'label')
                    ?? ''
                ));

                return [
                    'id' => $id,
                    'name' => $name !== '' ? $name : null,
                ];
            })
            ->filter(fn ($calendar) => $calendar['id'] !== null && $calendar['id'] !== '')
            ->map(function ($calendar) {
                $id = $calendar['id'];
                if (! is_numeric($id)) {
                    return null;
                }

                return [
                    'id' => (int) $id,
                    'name' => $calendar['name'] ?? null,
                ];
            })
            ->filter()
            ->sortBy(fn ($calendar) => strtolower($calendar['name'] ?? ''))
            ->values();

        if (! $includeNameless) {
            $namelessCount = $calendars->filter(fn ($calendar) => empty($calendar['name']))->count();
            $calendars = $calendars->reject(fn ($calendar) => empty($calendar['name']))->values();

            if ($namelessCount > 0) {
                $this->comment(sprintf('Skipped %d calendar(s) with no name. Use --includeNameless to include them.', $namelessCount));
            }
        }

        if ($calendars->isEmpty()) {
            $this->error('No eligible calendars found after filtering.');
            return Command::FAILURE;
        }

        if ($calendars->isEmpty()) {
            $this->error('No Acuity calendars were returned. Aborting.');
            return Command::FAILURE;
        }

        $this->info('Calendars found: '.$calendars->count());
        $this->table(['ID', 'Name'], $calendars->map(fn ($cal) => [
            $cal['id'],
            $cal['name'] ?? '(Unnamed calendar)',
        ])->toArray());

        $summary = [];
        foreach ($calendars as $calendar) {
            $calendarId = $calendar['id'];
            $calendarName = $calendar['name'] ?? '(Unnamed calendar)';

            $this->line(sprintf('→ Importing %s (ID %d)', $calendarName, $calendarId));

            $params = [
                '--from' => $from->toDateString(),
                '--to' => $to->toDateString(),
                '--sliceDays' => $sliceDays,
                '--pageSize' => $pageSize,
                '--maxRetries' => $maxRetries,
                '--retryBaseMs' => $retryBaseMs,
                '--calendarId' => $calendarId,
            ];

            if ($dryRun) {
                $params['--dryRun'] = true;
            }

            $exit = Artisan::call('acuity:sync-appointments', $params);
            $cmdOutput = trim(Artisan::output());

            $summary[] = [
                'id' => $calendarId,
                'name' => $calendarName,
                'status' => $exit === Command::SUCCESS ? 'ok' : 'failed',
            ];

            if ($exit === Command::SUCCESS) {
                $this->info('   ✔ Appointments import completed');
            } else {
                $this->error('   ✖ Appointments import failed (exit '.$exit.')');
                if ($cmdOutput !== '') {
                    $this->line($cmdOutput);
                }
            }
        }

        if (! $dryRun && ! $skipClients) {
            $this->info('Syncing Acuity clients after appointment imports...');
            $exitClients = Artisan::call('acuity:sync-clients', ['--limit' => 0]);
            $clientsOutput = trim(Artisan::output());
            if ($exitClients === Command::SUCCESS) {
                $this->info('   ✔ Client sync completed');
            } else {
                $this->error('   ✖ Client sync failed (exit '.$exitClients.')');
                if ($clientsOutput !== '') {
                    $this->line($clientsOutput);
                }
                return Command::FAILURE;
            }
        } elseif ($dryRun) {
            $this->comment('Dry run mode — skipped client sync.');
        }

        $this->line('Summary:');
        $this->table(['Calendar ID', 'Name', 'Status'], array_map(function ($row) {
            return [$row['id'], $row['name'], $row['status']];
        }, $summary));

        $hasFailures = collect($summary)->contains(fn ($row) => $row['status'] !== 'ok');
        if ($hasFailures) {
            $this->warn('One or more calendar imports failed. See output above.');
            return Command::FAILURE;
        }

        $this->info('All calendar imports completed successfully.');
        return Command::SUCCESS;
    }
}
