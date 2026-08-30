<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SessionsBackfillNorms extends Command
{
    protected $signature = 'sessions:backfill-norms {--limit=5000}';
    protected $description = 'Backfill calendar_norm and category_norm from acuity_data JSON; relink teacher by calendar access when possible';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $this->info('Backfilling up to '.$limit.' class_sessions...');

        $rows = DB::table('class_sessions')->select(['id','acuity_data','calendar_name','category_norm','calendar_norm','teacher_id'])->limit($limit)->get();
        $count = 0; $updated = 0; $relinked = 0;

        foreach ($rows as $r) {
            $count++;
            $data = is_array($r->acuity_data) ? $r->acuity_data : (json_decode((string) $r->acuity_data, true) ?: []);
            $rawCal = null;
            foreach ([
                $data['calendar'] ?? null,
                $data['calendarName'] ?? null,
                data_get($data, 'calendar.name'),
                $data['Calendar'] ?? null,
                $data['CalendarName'] ?? null,
            ] as $v) {
                if (is_string($v) && trim($v) !== '') { $rawCal = trim($v); break; }
            }
            $rawCat = $data['category'] ?? ($data['Category'] ?? null);

            $newCalNorm = $rawCal ? strtolower(trim($rawCal)) : null;
            $newCatNorm = $rawCat ? strtolower(trim($rawCat)) : null;

            $set = [];
            if (!$r->calendar_norm && $newCalNorm) $set['calendar_norm'] = $newCalNorm;
            if (!$r->category_norm && $newCatNorm) $set['category_norm'] = $newCatNorm;

            // Relink teacher by users.acuity_calendar_id or teacher_calendar_ids when available
            if ($newCalNorm) {
                $calendarId = data_get($data, 'calendarID') ?? data_get($data, 'calendarId') ?? data_get($data, 'calendar.id');
                if ($calendarId) {
                    $teacher = DB::table('users')
                        ->whereIn('role', ['teacher', 'head_teacher'])
                        ->where('is_active',1)
                        ->where(function ($query) use ($calendarId) {
                            $calendarId = (string) $calendarId;
                            $query->where('acuity_calendar_id', $calendarId)
                                ->orWhereJsonContains('teacher_calendar_ids', $calendarId);
                        })
                        ->first();
                    if ($teacher && (int)($r->teacher_id ?? 0) !== (int)$teacher->id) {
                        $set['teacher_id'] = (int) $teacher->id;
                        $relinked++;
                    }
                }
            }

            if (!empty($set)) {
                DB::table('class_sessions')->where('id',$r->id)->update($set);
                $updated++;
            }
        }

        $this->info("Scanned {$count} sessions; updated {$updated}; relinked {$relinked} teacher refs.");
        return self::SUCCESS;
    }
}
