<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AcuityAuditWindow extends Command
{
    protected $signature = 'acuity:audit-window
        {--from= : From date (YYYY-MM-DD)}
        {--to= : To date (YYYY-MM-DD)}
        {--calendarId= : Filter by Acuity calendar ID}
        {--calendarName= : Filter by calendar name (case-insensitive)}
        {--limit=0 : Max appointments to fetch from Acuity (0=default paging)}
        {--fill-missing : Dispatch sync jobs for missing appointment IDs}
        {--probe-extra : Probe a sample of extra DB IDs against API to classify removed}
        {--probe-max=50 : Max number of extra IDs to probe}
    ';

    protected $description = 'Compare DB vs Acuity for a date window (optionally filter by calendarId) and report missing/extra appointment IDs.';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->subDays(30)->toDateString();
        $to = $this->option('to') ?: now()->addDays(60)->toDateString();
        $calendarId = $this->option('calendarId');
        $calendarName = (string) ($this->option('calendarName') ?: '');
        $limit = (int) $this->option('limit');
        $fill = (bool) $this->option('fill-missing');
        $probe = (bool) $this->option('probe-extra');
        $probeMax = (int) $this->option('probe-max');
        $this->info("Auditing window {$from} → {$to}".($calendarId ? " (calendarId={$calendarId})" : '').($calendarName ? " (calendarName='".trim($calendarName)."')" : ''));

        // Fetch from Acuity in date chunks to avoid memory/paging issues
        $acuity = new AcuityService();
        $chunkDays = 1; // per-day chunks
        $maxPagesPerChunk = 400;
        $nameFilter = strtolower(trim($calendarName));
        $idsApiArr = [];
        $totalFetched = 0;
        $cursor = new \DateTime($from);
        $toDt = new \DateTime($to);
        while ($cursor <= $toDt) {
            $chunkStart = $cursor->format('Y-m-d');
            $chunkEnd = (clone $cursor)->modify('+'.($chunkDays-1).' day')->format('Y-m-d');
            if (new \DateTime($chunkEnd) > $toDt) { $chunkEnd = $toDt->format('Y-m-d'); }
            $params = [ 'minDate' => $chunkStart, 'maxDate' => $chunkEnd ];
            if ($calendarId) {
                $params['calendarID'] = $calendarId;
            }

            $page = 1;
            $perPage = 100;
            $chunkFetched = 0;
            do {
                $batch = $acuity->fetchAppointmentsPage($params, $page, $perPage);
                $count = is_array($batch) ? count($batch) : 0;
                if ($count > 0) {
                    foreach ($batch as $row) {
                        if ($nameFilter !== '') {
                            $ex = \App\Services\Acuity\AppointmentExtractor::extract($row);
                            $cal = strtolower(trim((string)($ex['calendar'] ?? '')));
                            if ($cal !== $nameFilter) {
                                continue;
                            }
                        }
                        if ($calendarId) {
                            $rowCalId = (string) (
                                data_get($row, 'calendarID') ??
                                data_get($row, 'calendarId') ??
                                data_get($row, 'calendar.id') ??
                                data_get($row, 'calendar') ?? ''
                            );
                            if ($rowCalId !== (string) $calendarId) {
                                continue;
                            }
                        }
                        $id = isset($row['id']) ? (string) $row['id'] : null;
                        if ($id) {
                            $idsApiArr[$id] = true;
                        }
                    }
                    $chunkFetched += $count;
                    $totalFetched += $count;
                }

                if ($page > $maxPagesPerChunk) {
                    $this->warn("Chunk {$chunkStart} → {$chunkEnd}: reached page {$page}; stopping early to avoid runaway paging.");
                    break;
                }

                $page++;
            } while ($count === $perPage);

            $this->line("Chunk {$chunkStart} → {$chunkEnd}: fetched {$chunkFetched} (total {$totalFetched})");
            $cursor->modify('+'.$chunkDays.' day');
        }
        $idsApi = collect(array_keys($idsApiArr));
        $this->info('Acuity count: '.$idsApi->count());

        // Fetch from DB
        // Use half-open interval [from, toNextDay) to handle datetime values in session_date
        $toNext = (new \DateTime($to))->modify('+1 day')->format('Y-m-d');
        $q = DB::table('class_sessions')
            ->where('session_date', '>=', $from)
            ->where('session_date', '<', $toNext);
        if ($calendarId) {
            $q->where(function ($w) use ($calendarId) {
                $w->whereRaw("json_extract(acuity_data, '$.calendarID') = ?", [(string) $calendarId])
                  ->orWhereRaw("json_extract(acuity_data, '$.calendarId') = ?", [(string) $calendarId])
                  ->orWhereRaw("json_extract(acuity_data, '$.calendar.id') = ?", [(string) $calendarId]);
            });
        }
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
        $idsDb = $q->pluck('acuity_appointment_id')->map(fn($v) => (string) $v)->unique()->values();
        $this->info('DB count: '.$idsDb->count());

        // Compare
        $missing = $idsApi->diff($idsDb);
        $extra = $idsDb->diff($idsApi);
        $this->line('Missing in DB (in Acuity, not in DB): '.$missing->count());
        if ($missing->count()) {
            $this->line(implode(', ', $missing->take(50)->toArray()).($missing->count()>50?' ...':''));
        }
        $this->line('Extra in DB (in DB, not in Acuity): '.$extra->count());
        if ($extra->count()) {
            $this->line(implode(', ', $extra->take(50)->toArray()).($extra->count()>50?' ...':''));
        }

        if ($extra->count()) {
            $extraRows = DB::table('class_sessions')
                ->select(['acuity_appointment_id','status','canceled'])
                ->whereIn('acuity_appointment_id', $extra->take(500)->all())
                ->get();
            $byStatus = collect($extraRows)->groupBy(function ($r) {
                $flag = (isset($r->canceled) && $r->canceled) ? 'canceled' : 'active';
                return (string)($r->status ?: 'unknown').'/'.$flag;
            })->map->count();
            $this->line('Extra breakdown by status/canceled: '.json_encode($byStatus));

            if ($probe) {
                $this->info('Probing extra IDs against API to classify removed...');
                $svc = new \App\Services\AcuityService();
                $probeIds = $extra->take(max(1, $probeMax));
                $removed = 0; $present = 0; $errors = 0;
                foreach ($probeIds as $pid) {
                    try {
                        $data = $svc->getAppointment($pid);
                        if (is_array($data) && !empty($data)) { $present++; }
                        else { $removed++; }
                    } catch (\Throwable $e) { $errors++; }
                }
                $this->line("Probe results: present={$present}, removed_or_notfound={$removed}, errors={$errors}");
            }
        }

        if ($fill && $missing->count()) {
            $this->warn('Dispatching sync jobs for missing IDs...');
            foreach ($missing as $id) {
                \App\Jobs\SyncAcuityAppointment::dispatch($id)->onQueue('acuity');
            }
            $this->info('Dispatched '.$missing->count().' jobs. Run a worker to import them.');
        }

        return self::SUCCESS;
    }
}
