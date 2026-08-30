<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SessionsPeek extends Command
{
    protected $signature = 'sessions:peek
        {--limit=5 : Number of rows to show}
        {--calendar= : Filter by normalized calendar name}
        {--teacher= : Filter by teacher email}
        {--ids=* : Specific acuity_appointment_id values to show}
        {--from= : From date (YYYY-MM-DD)}
        {--to= : To date (YYYY-MM-DD)}
    ';

    protected $description = 'Print key fields for class_sessions to inspect stored data';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $ids = (array) $this->option('ids');
        $cal = $this->option('calendar');
        $teacher = $this->option('teacher');
        $from = $this->option('from');
        $to = $this->option('to');

        $q = DB::table('class_sessions as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_id')
            ->select([
                'cs.id',
                'cs.acuity_appointment_id',
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.status',
                'cs.canceled',
                'cs.calendar_norm',
                'cs.category_norm',
                'u.email as teacher_email',
                'u.name as teacher_name',
                'cs.acuity_data',
            ])
            ->orderBy('cs.session_date')
            ->orderBy('cs.start_time');

        if (!empty($ids)) {
            $q->whereIn('cs.acuity_appointment_id', array_map('strval', $ids));
        }
        if (is_string($cal) && $cal !== '') {
            $q->whereRaw("LOWER(TRIM(COALESCE(cs.calendar_norm, ''))) = ?", [strtolower(trim($cal))]);
        }
        if (is_string($teacher) && $teacher !== '') {
            $q->whereRaw('LOWER(u.email) = ?', [strtolower(trim($teacher))]);
        }
        if ($from) { $q->whereDate('cs.session_date', '>=', $from); }
        if ($to)   { $q->whereDate('cs.session_date', '<=', $to); }

        if ($limit > 0) { $q->limit($limit); }

        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->warn('No rows matched.');
            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $data = is_array($r->acuity_data) ? $r->acuity_data : (json_decode((string) $r->acuity_data, true) ?: []);
            $rawCategory = $data['category'] ?? ($data['Category'] ?? null);
            $rawCalendar = $data['calendar'] ?? ($data['calendarName'] ?? (data_get($data, 'calendar.name') ?? null));
            $tz = $data['timezone'] ?? null;

            $this->line(str_repeat('-', 80));
            $this->info("id={$r->id} appt={$r->acuity_appointment_id} date={$r->session_date} {$r->start_time}-{$r->end_time} status={$r->status} canceled=".(int)$r->canceled);
            $this->line("teacher={$r->teacher_name} <{$r->teacher_email}>");
            $this->line("calendar_norm={$r->calendar_norm} raw_calendar=".(string)$rawCalendar);
            $this->line("category_norm={$r->category_norm} raw_category=".(string)$rawCategory);
            if ($tz) $this->line("timezone={$tz}");
        }

        $this->line(str_repeat('-', 80));
        $this->info('Rows shown: '.$rows->count());
        return self::SUCCESS;
    }
}
