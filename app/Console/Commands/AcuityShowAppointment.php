<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AcuityShowAppointment extends Command
{
    protected $signature = 'acuity:show-appointment {id : Acuity appointment ID}
        {--analyze : Print a quick group/single analysis}
        {--db-group : Also check DB for parallel sessions by date/time/calendar/location}';

    protected $description = 'Fetch and pretty-print a single Acuity appointment payload; optionally analyze for group/single and parallel sessions.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $analyze = (bool) $this->option('analyze');
        $checkDb = (bool) $this->option('db-group');

        try {
            $svc = new AcuityService();
            $data = $svc->getAppointment($id);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch from Acuity: '.$e->getMessage());
            return self::FAILURE;
        }

        if (!is_array($data)) {
            $this->warn('No JSON document returned for that ID.');
            return self::SUCCESS;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        if ($analyze) {
            $this->line("");
            $this->info('Analysis:');
            $keys = array_change_key_case(array_fill_keys(array_keys($data), true));

            $hints = [];
            foreach (['class','classid','class_id','group','capacity','maxattendees','maxparticipants','attendees','participants'] as $k) {
                $hints[$k] = array_key_exists($k, $keys);
            }
            $this->line('Key presence: '.json_encode($hints));

            // Rough count hints
            $participantsCount = 0;
            foreach (['attendees','participants'] as $listKey) {
                if (isset($data[$listKey]) && is_array($data[$listKey])) {
                    $participantsCount = max($participantsCount, count($data[$listKey]));
                }
            }
            $this->line('participantsCount guess: '.$participantsCount);

            $possibleGroup = ($participantsCount > 1)
                || ($hints['class'] ?? false)
                || ($hints['group'] ?? false)
                || ($hints['capacity'] ?? false)
                || ($hints['maxattendees'] ?? false)
                || ($hints['maxparticipants'] ?? false);
            $this->line('possibleGroup: '.($possibleGroup ? 'true' : 'false'));
        }

        if ($checkDb) {
            $date = $this->extractStr($data, ['datetime','date','time']);
            $start = $this->extractTime($data);
            $duration = (int) ($data['duration'] ?? 60);
            $end = $this->addMinutesToTime($start, $duration);
            $loc = $this->extractStr($data, ['location','Location']) ?? '';
            $cal = $this->extractCalendarName($data) ?? '';

            if ($date && $start) {
                $count = DB::table('class_sessions')
                    ->whereDate('session_date', substr($date, 0, 10))
                    ->where('start_time', $start)
                    ->where('end_time', $end)
                    ->where('location', $loc)
                    ->where('calendar_name', $cal)
                    ->count();
                $this->line("DB parallel sessions for group key [{$date} {$start}-{$end} {$loc} {$cal}]: {$count}");
            } else {
                $this->warn('Could not derive date/start from payload to check DB group.');
            }
        }

        return self::SUCCESS;
    }

    private function extractStr(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') return $data[$k];
        }
        return null;
    }

    private function extractCalendarName(array $data): ?string
    {
        $candidates = [
            $data['calendar'] ?? null,
            $data['calendarName'] ?? null,
            isset($data['calendar']['name']) ? $data['calendar']['name'] : null,
            $data['Calendar'] ?? null,
            $data['CalendarName'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') return $c;
        }
        return null;
    }

    private function extractTime(array $data): ?string
    {
        $dt = $data['datetime'] ?? null;
        if (is_string($dt) && strlen($dt) >= 16) {
            // Expect format like 2025-09-08T23:00:00+.. or 2025-09-08 23:00:00
            $t = substr(str_replace('T',' ', $dt), 11, 8);
            return preg_match('/^\d{2}:\d{2}:\d{2}$/', $t) ? $t : null;
        }
        return null;
    }

    private function addMinutesToTime(?string $time, int $minutes): ?string
    {
        if (!$time) return null;
        try {
            $dt = new \DateTime('1970-01-01 '.$time);
            $dt->modify("+{$minutes} minutes");
            return $dt->format('H:i:s');
        } catch (\Throwable $e) { return null; }
    }
}

