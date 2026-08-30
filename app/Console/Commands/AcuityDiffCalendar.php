<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AcuityService;
use Illuminate\Support\Facades\DB;
use App\Services\Acuity\AppointmentExtractor;

class AcuityDiffCalendar extends Command
{
    protected $signature = 'acuity:diff-cal {--calendar=} {--days=30}';
    protected $description = 'Compare Acuity API vs DB for a given calendar over a date window';

    public function handle(): int
    {
        $cal = (string) $this->option('calendar');
        $days = (int) $this->option('days');
        if ($cal === '') {
            $this->error('--calendar is required');
            return self::FAILURE;
        }

        $from = now()->subDays($days)->format('Y-m-d');
        $to = now()->addDays($days)->format('Y-m-d');

        $svc = new AcuityService();
        $this->info("Fetching Acuity appointments for '{$cal}' from {$from} to {$to}...");
        $api = collect($svc->getAppointments(['minDate' => $from, 'maxDate' => $to]))
            ->filter(function ($a) use ($cal) {
                $ex = AppointmentExtractor::extract($a);
                return strtolower(trim((string) ($ex['calendar'] ?? ''))) === strtolower(trim($cal));
            })
            ->pluck('id')->map(fn($v) => (string) $v)->values();

        $this->info("Querying DB class_sessions for '{$cal}' in same window...");
        $db = DB::table('class_sessions')
            ->whereBetween('session_date', [$from, $to])
            ->whereRaw('LOWER(TRIM(COALESCE(calendar_norm, ' . "''" . '))) = ?', [strtolower(trim($cal))])
            ->pluck('acuity_appointment_id')->map(fn($v) => (string) $v)->values();

        $missingInDb = $api->diff($db)->values();
        $missingInApi = $db->diff($api)->values();

        $this->line('API count: '.$api->count());
        $this->line('DB  count: '.$db->count());
        $this->line('Missing in DB: '.json_encode($missingInDb));
        $this->line('Missing in API: '.json_encode($missingInApi));

        return self::SUCCESS;
    }
}
