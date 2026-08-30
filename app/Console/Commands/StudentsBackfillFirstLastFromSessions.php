<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StudentsBackfillFirstLastFromSessions extends Command
{
    protected $signature = 'students:backfill-first-last {--chunk=1000}';
    protected $description = 'Backfill students.first_appointment_date and last_appointment_date using class_sessions (by student_id or email/client match).';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $this->info("Backfilling first/last appointment dates in chunks of {$chunk}...");
        $updated = 0; $total = 0;

        DB::table('students')->orderBy('id')->select(['id','email','acuity_client_id'])->chunkById($chunk, function ($rows) use (&$updated, &$total) {
            $ids = collect($rows)->pluck('id')->all();
            $emails = collect($rows)->pluck('email')->filter()->map(fn($e) => strtolower(trim((string) $e)))->unique()->values()->all();
            $idsToEmail = [];
            foreach ($rows as $r) { $idsToEmail[strtolower((string) ($r->email ?? ''))] = $r->id; }
            $total += count($ids);

            // By student_id direct link
            $byId = DB::table('class_sessions')
                ->select('student_id', DB::raw('MIN(session_date) as first_date'), DB::raw('MAX(session_date) as last_date'))
                ->whereIn('student_id', $ids)
                ->groupBy('student_id')
                ->get();
            foreach ($byId as $row) {
                DB::table('students')->where('id', $row->student_id)->update([
                    'first_appointment_date' => $row->first_date,
                    'last_appointment_date' => $row->last_date,
                ]);
                $updated++;
            }

            // Fallback by email for unlinked sessions
            if (!empty($emails)) {
                $byEmail = DB::table('class_sessions')
                    ->select(DB::raw("LOWER(COALESCE(student_email, client_email)) as em"), DB::raw('MIN(session_date) as first_date'), DB::raw('MAX(session_date) as last_date'))
                    ->whereIn(DB::raw("LOWER(COALESCE(student_email, client_email))"), $emails)
                    ->groupBy('em')
                    ->get();
                foreach ($byEmail as $row) {
                    $sid = $idsToEmail[$row->em] ?? null;
                    if (!$sid) continue;
                    $cur = DB::table('students')->select('first_appointment_date','last_appointment_date')->where('id',$sid)->first();
                    $first = $cur->first_appointment_date ?? $row->first_date;
                    if ($cur->first_appointment_date === null || $row->first_date < $cur->first_appointment_date) $first = $row->first_date;
                    $last = $cur->last_appointment_date ?? $row->last_date;
                    if ($cur->last_appointment_date === null || $row->last_date > $cur->last_appointment_date) $last = $row->last_date;
                    DB::table('students')->where('id', $sid)->update([
                        'first_appointment_date' => $first,
                        'last_appointment_date' => $last,
                    ]);
                    $updated++;
                }
            }
        });

        $this->info("Updated {$updated} rows across {$total} students.");
        return Command::SUCCESS;
    }
}

