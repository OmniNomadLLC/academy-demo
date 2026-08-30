<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StudentsBackfillNextAppointment extends Command
{
    protected $signature = 'students:backfill-next-appointment {--chunk=500} {--horizon=180 : days ahead to scan}';
    protected $description = 'Backfill students.next_appointment_date from class_sessions (linked by student_id and by email fallback).';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $horizon = (int) $this->option('horizon');
        $from = Carbon::today()->toDateString();
        $to = Carbon::today()->addDays($horizon)->toDateString();

        $this->info("Backfilling next_appointment_date between {$from} and {$to} in chunks of {$chunk}...");
        $updated = 0; $total = 0;

        DB::table('students')->orderBy('id')->select(['id','email'])->chunkById($chunk, function ($rows) use (&$updated, &$total, $from, $to) {
            $ids = collect($rows)->pluck('id')->all();
            $emails = collect($rows)->pluck('email')->filter()->map(fn($e) => strtolower(trim((string) $e)))->unique()->values()->all();
            $total += count($ids);

            // Linked by student_id
            $byId = DB::table('class_sessions')
                ->select('student_id', DB::raw('MIN(session_date) as next_date'))
                ->whereIn('student_id', $ids)
                ->whereBetween('session_date', [$from, $to])
                ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
                ->groupBy('student_id')
                ->pluck('next_date', 'student_id');
            foreach ($byId as $sid => $date) {
                DB::table('students')->where('id', $sid)->update(['next_appointment_date' => $date]);
                $updated++;
            }

            // Fallback by email for unlinked sessions
            if (!empty($emails)) {
                $emailToId = DB::table('students')->whereIn(DB::raw('LOWER(email)'), $emails)
                    ->pluck('id', DB::raw('LOWER(email) as e'));
                $byEmail = DB::table('class_sessions')
                    ->select(DB::raw("LOWER(COALESCE(student_email, client_email)) as em"), DB::raw('MIN(session_date) as next_date'))
                    ->whereBetween('session_date', [$from, $to])
                    ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
                    ->whereIn(DB::raw("LOWER(COALESCE(student_email, client_email))"), $emails)
                    ->groupBy('em')
                    ->get();
                foreach ($byEmail as $row) {
                    $sid = $emailToId[$row->em] ?? null;
                    if (!$sid) continue;
                    // Only write if next_appointment_date is null or later than this value
                    $current = DB::table('students')->where('id', $sid)->value('next_appointment_date');
                    if (!$current || $row->next_date < $current) {
                        DB::table('students')->where('id', $sid)->update(['next_appointment_date' => $row->next_date]);
                        $updated++;
                    }
                }
            }
        });

        $this->info("Updated {$updated} rows across {$total} students.");
        return Command::SUCCESS;
    }
}

