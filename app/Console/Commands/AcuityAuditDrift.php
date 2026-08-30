<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AcuityService;
use App\Services\SlackNotifier;
use App\Services\EmailNotifier;

class AcuityAuditDrift extends Command
{
    protected $signature = 'acuity:audit-drift
        {--from= : From date (YYYY-MM-DD), default today}
        {--to= : To date (YYYY-MM-DD), default +60 days}
        {--calendarName= : Optional calendar name filter}
        {--threshold=1 : Alert if missing >= threshold}
        {--probe-extra : Probe sample of extra to classify}
        {--probe-max=25 : Max probe size}
        {--slice-days=7 : Days per Acuity API slice (must keep slice items <= 200)}
        {--email-to= : Comma-separated recipient emails (overrides ALERT_EMAIL_TO)}
    ';

    protected $description = 'Audit upcoming window against Acuity and alert to Slack if drift > threshold.';

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: now()->toDateString());
        $to = (string) ($this->option('to') ?: now()->addDays(60)->toDateString());
        $calendarName = (string) ($this->option('calendarName') ?: '');
        $threshold = (int) $this->option('threshold');
        $probe = (bool) $this->option('probe-extra');
        $probeMax = (int) $this->option('probe-max');
        $sliceDays = max(1, (int) $this->option('slice-days'));

        Log::info('Acuity audit-drift starting', [
            'from' => $from,
            'to' => $to,
            'calendar' => $calendarName ?: '(all)',
            'slice_days' => $sliceDays,
        ]);

        $svc = new AcuityService();
        $idsApi = $this->fetchAcuityIds($svc, $from, $to, $calendarName, $sliceDays);

        $toNext = (new \DateTime($to))->modify('+1 day')->format('Y-m-d');
        $q = DB::table('class_sessions')->where('session_date','>=',$from)->where('session_date','<',$toNext);
        if ($calendarName !== '') {
            $calNorm = strtolower(trim($calendarName));
            $q->where(function ($w) use ($calNorm) {
                $w->whereRaw('LOWER(TRIM(COALESCE(calendar_norm, ' . "''" . '))) = ?', [$calNorm])
                  ->orWhereRaw('LOWER(TRIM(COALESCE(calendar_name, ' . "''" . '))) = ?', [$calNorm])
                  ->orWhereRaw("LOWER(json_extract(acuity_data, '$.calendar')) = ?", [$calNorm])
                  ->orWhereRaw("LOWER(json_extract(acuity_data, '$.calendarName')) = ?", [$calNorm])
                  ->orWhereRaw("LOWER(json_extract(acuity_data, '$.calendar.name')) = ?", [$calNorm]);
            });
        }
        $idsDb = $q->pluck('acuity_appointment_id')->map(fn($v)=>(string)$v)->unique();

        $missing = $idsApi->diff($idsDb);
        $extra = $idsDb->diff($idsApi);

        $msg = sprintf('Acuity drift: window %s→%s cal=%s | api=%d db=%d missing=%d extra=%d',
            $from, $to, $calendarName ?: '(all)', $idsApi->count(), $idsDb->count(), $missing->count(), $extra->count());
        $this->info($msg);
        Log::info($msg);

        if ($probe && $extra->count()) {
            $removed = 0; $present = 0; $errors = 0;
            foreach ($extra->take(max(1,$probeMax)) as $pid) {
                try {
                    $data = $svc->getAppointment($pid);
                    if (is_array($data) && !empty($data)) { $present++; } else { $removed++; }
                } catch (\Throwable $e) { $errors++; }
            }
            $probeLine = "Probe: present={$present} removed_or_notfound={$removed} errors={$errors}";
            $this->line($probeLine);
            Log::info('Acuity audit-drift probe', compact('present', 'removed', 'errors'));
        }

        if ($missing->count() >= max(1,$threshold)) {
            // Slack alert (if configured)
            $slack = new SlackNotifier();
            $slack->post(':warning: '.$msg);

            // Email alert
            $emailsOpt = (string) ($this->option('email-to') ?: '');
            $recipients = $emailsOpt !== '' ? preg_split('/\s*,\s*/', $emailsOpt, -1, PREG_SPLIT_NO_EMPTY) : null;
            (new EmailNotifier($recipients))->send('Acuity drift detected', $msg);
        }

        return self::SUCCESS;
    }

    /**
     * Fetch every appointment id from Acuity for the [from, to] window.
     *
     * Acuity's /appointments endpoint silently ignores both `page` and `lastID`
     * query parameters (verified 2026-04-24, see docs/daily/2026-04-24.md). The
     * only reliable way to retrieve > max items per call is to slice the date
     * window into sub-windows small enough that each sub-window's confirmed
     * appointment count fits inside one max=200 response. For Lumina today
     * (~25 sessions/day across all calendars), 7-day slices typically stay
     * under 200, but busy weeks (term starts, group classes) can exceed it.
     *
     * If a slice returns exactly 200 items we assume the cap was hit and
     * recursively halve it until each sub-slice fits. Recursion is bounded
     * to 5 levels deep — beyond that we log a warning and accept whatever
     * we got, rather than fan out indefinitely on a misbehaving API.
     */
    protected function fetchAcuityIds(AcuityService $svc, string $from, string $to, string $calendarName, int $sliceDays): Collection
    {
        $perCallMax = 200; // Acuity hard limit per /appointments response
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $rawAppointments = collect();
        $slices = 0;
        $apiCalls = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $sliceFrom = $cursor->toDateString();
            $sliceToCarbon = $cursor->copy()->addDays($sliceDays - 1);
            if ($sliceToCarbon->gt($end)) {
                $sliceToCarbon = $end->copy();
            }
            $sliceTo = $sliceToCarbon->toDateString();

            $rawAppointments = $rawAppointments->concat(
                $this->fetchSlice($svc, $sliceFrom, $sliceTo, $perCallMax, depth: 0, apiCalls: $apiCalls)
            );
            $slices++;

            $cursor = $sliceToCarbon->copy()->addDay();
        }

        $filtered = $rawAppointments;
        if ($calendarName !== '') {
            $filtered = $filtered->filter(function ($a) use ($calendarName) {
                $ex = \App\Services\Acuity\AppointmentExtractor::extract($a);
                return strtolower(trim((string)($ex['calendar'] ?? ''))) === strtolower(trim($calendarName));
            });
        }

        $unique = $filtered
            ->map(fn ($row) => isset($row['id']) ? (string) $row['id'] : null)
            ->filter()
            ->unique()
            ->values();

        Log::info('Acuity audit-drift slice fetch complete', [
            'top_level_slices' => $slices,
            'api_calls' => $apiCalls,
            'raw_appointments' => $rawAppointments->count(),
            'unique_ids' => $unique->count(),
        ]);

        return $unique;
    }

    /**
     * Fetch a single date-window slice. If the API returns exactly $perCallMax
     * items we assume the cap was hit, halve the window, and recurse on each
     * half. Bounded at 5 levels (a 7-day window halved 5 times = ~5h slices —
     * deeper than that we accept truncation and warn).
     */
    protected function fetchSlice(AcuityService $svc, string $sliceFrom, string $sliceTo, int $perCallMax, int $depth, int &$apiCalls): Collection
    {
        $apiCalls++;
        $appts = $svc->fetchAppointmentsPage(
            ['minDate' => $sliceFrom, 'maxDate' => $sliceTo],
            page: 1,
            perPage: $perCallMax,
        );
        $count = is_array($appts) ? count($appts) : 0;

        if ($count < $perCallMax || $sliceFrom === $sliceTo || $depth >= 5) {
            if ($count >= $perCallMax) {
                Log::warning('Acuity audit-drift slice hit max even after halving — accepting truncation', [
                    'slice_from' => $sliceFrom,
                    'slice_to' => $sliceTo,
                    'depth' => $depth,
                    'count' => $count,
                ]);
            }
            return collect($appts);
        }

        $from = Carbon::parse($sliceFrom);
        $to = Carbon::parse($sliceTo);
        $diffDays = (int) $from->diffInDays($to);
        $midOffset = max(1, (int) floor($diffDays / 2));
        $midA = $from->copy()->addDays($midOffset - 1)->toDateString();
        $midB = $from->copy()->addDays($midOffset)->toDateString();

        return $this->fetchSlice($svc, $sliceFrom, $midA, $perCallMax, $depth + 1, $apiCalls)
            ->concat($this->fetchSlice($svc, $midB, $sliceTo, $perCallMax, $depth + 1, $apiCalls));
    }
}
