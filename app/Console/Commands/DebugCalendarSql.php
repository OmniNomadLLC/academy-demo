<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugCalendarSql extends Command
{
    protected $signature = 'debug:calendar-sql {needle : Calendar name fragment (e.g. "michael roe")} {--from=2025-08-01} {--to=2026-12-31}';
    protected $description = 'Run a debug count query for class_sessions by calendar name across normalized and JSON fields.';

    public function handle(): int
    {
        $needle = strtolower((string) $this->argument('needle'));
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $like = '%'.$needle.'%';

        $driver = DB::getDriverName();
        // JSON unquote helper per driver
        $json = function (string $expr) use ($driver): string {
            if (in_array($driver, ['mysql', 'mariadb'])) {
                return 'JSON_UNQUOTE('.$expr.')';
            }
            // SQLite/pgsql: json_extract returns text for strings; use as-is
            return $expr;
        };

        $sql = "SELECT COUNT(*) AS c FROM class_sessions\n".
               "WHERE session_date BETWEEN ? AND ?\n".
               "AND (\n".
               "  lower(COALESCE(calendar_norm,'')) LIKE ? OR\n".
               "  lower(COALESCE(calendar_name,'')) LIKE ? OR\n".
               "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendar')").", '')) LIKE ? OR\n".
               "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendarName')").", '')) LIKE ? OR\n".
               "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendar.name')").", '')) LIKE ?\n".
               ")";

        $bindings = [$from, $to, $like, $like, $like, $like, $like];
        $row = DB::selectOne($sql, $bindings);
        $count = (int) ($row->c ?? 0);
        $this->line("Count = {$count}");

        // Optional: print first few matches to verify fields
        if ($count > 0) {
            $sampleSql = "SELECT id, session_date, start_time, calendar_name, calendar_norm FROM class_sessions\n".
                        "WHERE session_date BETWEEN ? AND ? AND (\n".
                        "  lower(COALESCE(calendar_norm,'')) LIKE ? OR\n".
                        "  lower(COALESCE(calendar_name,'')) LIKE ? OR\n".
                        "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendar')").", '')) LIKE ? OR\n".
                        "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendarName')").", '')) LIKE ? OR\n".
                        "  lower(COALESCE(".$json("json_extract(acuity_data, '$.calendar.name')").", '')) LIKE ?\n".
                        ")\n".
                        "ORDER BY session_date ASC, start_time ASC\n".
                        "LIMIT 5";
            $rows = DB::select($sampleSql, $bindings);
            foreach ($rows as $r) {
                $this->line(sprintf("id=%d date=%s %s cal='%s' norm='%s'",
                    $r->id, $r->session_date, $r->start_time, (string) $r->calendar_name, (string) $r->calendar_norm));
            }
        }

        return self::SUCCESS;
    }
}

