<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AcuityDoctor extends Command
{
    protected $signature = 'acuity:doctor {--days=60}';
    protected $description = 'Diagnose Acuity ingest and DB alignment: schema, counts, calendars, unlinked samples.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $from = now()->toDateString();
        $to = now()->addDays($days)->toDateString();

        $this->info("Window: {$from} .. {$to}");

        // Schema checks
        $cols = DB::getSchemaBuilder()->getColumnListing('class_sessions');
        $need = ['acuity_appointment_id','session_date','start_time','end_time','status','canceled','location','calendar_name','calendar_norm','category_norm','client_email','link_status'];
        $missing = array_values(array_diff($need, $cols));
        $this->line('Missing columns: '.(empty($missing) ? '(none)' : implode(', ', $missing)));

        // Totals in window
        $total = DB::table('class_sessions')
            ->whereBetween('session_date', [$from, $to])
            ->where(function($w){ $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status',['scheduled','confirmed']); })
            ->count();
        $this->line('Total sessions (win+cnd): '.$total);

        // By link status
        $byLink = DB::table('class_sessions')
            ->select('link_status', DB::raw('count(*) as cnt'))
            ->whereBetween('session_date', [$from, $to])
            ->groupBy('link_status')->pluck('cnt','link_status')->toArray();
        $this->line('By link_status: '.json_encode($byLink));

        // Top 20 calendars
        $cals = DB::table('class_sessions')
            ->select(DB::raw("LOWER(TRIM(COALESCE(calendar_norm, ''))) as cal"), DB::raw('count(*) as cnt'))
            ->whereBetween('session_date', [$from, $to])
            ->groupBy('cal')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();
        $this->line('Top calendars:');
        foreach ($cals as $row) {
            $this->line('  '.($row->cal ?: '(unknown)').' => '.$row->cnt);
        }

        // Unlinked sample
        $sample = DB::table('class_sessions')
            ->select('id','session_date','start_time','calendar_name','category_norm','client_email','acuity_appointment_id')
            ->whereBetween('session_date', [$from, $to])
            ->whereNull('student_id')
            ->orderBy('session_date')
            ->limit(15)
            ->get();
        $this->line('Sample unlinked (15):');
        foreach ($sample as $s) {
            $this->line('  #'.$s->id.' '.$s->session_date.' '.$s->start_time.' cal='.(string)$s->calendar_name.' cat='.(string)$s->category_norm.' email='.(string)$s->client_email.' appt='.$s->acuity_appointment_id);
        }

        return self::SUCCESS;
    }
}

