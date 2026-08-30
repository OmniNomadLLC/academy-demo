<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportUpcomingCsv extends Command
{
    protected $signature = 'exports:upcoming-csv
        {--from= : From date (YYYY-MM-DD), default today}
        {--to= : To date (YYYY-MM-DD), default +60 days}
        {--region= : Region filter (e.g., UK, Spain, France)}
        {--calendar= : Calendar name filter}
        {--teacher_id= : Teacher ID filter}
        {--status=* : One or more statuses to include}
        {--path= : Output path (default storage/app/exports/upcoming-<timestamp>.csv)}
    ';

    protected $description = 'Export upcoming sessions from DB as CSV for manual review';

    public function handle(): int
    {
        $region = (string) ($this->option('region') ?? '');
        $calendar = (string) ($this->option('calendar') ?? '');
        $teacherId = $this->option('teacher_id') !== null ? (int) $this->option('teacher_id') : null;
        $statuses = (array) ($this->option('status') ?? []);
        $from = (string) ($this->option('from') ?: now()->toDateString());
        $to = (string) ($this->option('to') ?: now()->addDays(60)->toDateString());

        $q = DB::table('class_sessions as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_id')
            ->select(['cs.session_date','cs.start_time','cs.end_time','cs.location','cs.status','cs.calendar_name','cs.category_norm','u.name as teacher_name','u.email as teacher_email'])
            ->whereBetween('cs.session_date', [$from, $to])
            ->where(function ($w) {
                $w->where('cs.canceled', false)->orWhereNull('cs.canceled')->orWhereIn('cs.status', ['scheduled','confirmed']);
            })
            ->orderBy('cs.session_date')->orderBy('cs.start_time');

        if ($region) $q->whereRaw('LOWER(cs.location) = ?', [strtolower($region)]);
        if ($calendar) {
            $q->where(function ($w) use ($calendar) {
                $w->whereRaw("json_extract(cs.acuity_data, '$.calendar') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.calendarName') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.calendar.name') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.Calendar') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.CalendarName') = ?", [$calendar]);
            });
        }
        if ($teacherId) $q->where('cs.teacher_id', $teacherId);
        if (!empty($statuses)) $q->whereIn('cs.status', $statuses);

        $path = $this->option('path');
        if (!$path) {
            $dir = 'exports';
            $filename = 'upcoming-'.now()->format('Ymd_His').'.csv';
            $path = $dir.'/'.$filename;
        }

        // Ensure directory
        if (!Storage::exists(dirname($path))) Storage::makeDirectory(dirname($path));

        $full = Storage::path($path);
        $fh = fopen($full, 'w');
        if ($fh === false) {
            $this->error('Unable to open file for writing: '.$full);
            return self::FAILURE;
        }
        fputcsv($fh, ['Date','Start','End','Region','Status','Calendar','Category','Teacher']);
        $count = 0;
        $q->chunk(1000, function ($rows) use (&$count, $fh) {
            foreach ($rows as $r) {
                $teacher = $r->teacher_name ?: $r->teacher_email ?: '';
                $cal = $this->trimJsonQuotes($r->calendar_name);
                $cat = $this->trimJsonQuotes($r->category_norm);
                fputcsv($fh, [
                    $r->session_date,
                    $r->start_time,
                    $r->end_time,
                    $r->location,
                    $r->status,
                    $cal,
                    $cat,
                    $teacher,
                ]);
                $count++;
            }
        });
        fclose($fh);

        $this->info("Wrote {$count} rows to ".$full);
        return self::SUCCESS;
    }

    private function trimJsonQuotes(?string $v): ?string
    {
        if ($v === null) return null;
        $s = trim($v);
        return trim($s, '"');
    }
}

