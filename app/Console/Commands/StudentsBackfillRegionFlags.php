<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StudentsBackfillRegionFlags extends Command
{
    protected $signature = 'students:backfill-region-flags {--chunk=500 : Chunk size}';
    protected $description = 'Backfill students.in_uk/in_spain/in_france from class_sessions links and current student.location';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $updated = 0; $total = 0;

        $this->info("Backfilling region flags in chunks of {$chunk}...");

        DB::table('students')->orderBy('id')->select(['id','location','email'])->chunkById($chunk, function ($rows) use (&$updated, &$total) {
            $ids = collect($rows)->pluck('id')->all();
            $emails = collect($rows)->pluck('email')->filter()->map(fn($e) => strtolower(trim((string) $e)))->unique()->values()->all();
            $total += count($ids);

            // Initialize flags from current location
            foreach ($rows as $r) {
                $flags = [ 'in_uk' => false, 'in_spain' => false, 'in_france' => false ];
                $loc = strtolower((string) ($r->location ?? ''));
                if ($loc === 'uk') $flags['in_uk'] = true;
                if ($loc === 'spain') $flags['in_spain'] = true;
                if ($loc === 'france') $flags['in_france'] = true;
                if (array_filter($flags)) {
                    DB::table('students')->where('id', $r->id)->update($flags);
                    $updated++;
                }
            }

            // Flags from linked class_sessions by student_id
            $cs = DB::table('class_sessions')
                ->select('student_id', DB::raw("LOWER(COALESCE(location,'')) as loc"), DB::raw('COUNT(*) as cnt'))
                ->whereIn('student_id', $ids)
                ->where(function ($w) {
                    $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed','completed']);
                })
                ->groupBy('student_id','loc')
                ->get();
            foreach ($cs as $row) {
                $flags = [ 'in_uk' => false, 'in_spain' => false, 'in_france' => false ];
                if ($row->loc === 'uk') $flags['in_uk'] = true;
                if ($row->loc === 'spain') $flags['in_spain'] = true;
                if ($row->loc === 'france') $flags['in_france'] = true;
                if (array_filter($flags)) {
                    DB::table('students')->where('id', $row->student_id)->update($flags);
                    $updated++;
                }
            }

            // Email-based scan to catch unlinked sessions quickly
            if (!empty($emails)) {
                $emailMap = DB::table('students')->whereIn(DB::raw('LOWER(email)'), $emails)
                    ->pluck('id', DB::raw('LOWER(email) as e'));
                $csEmail = DB::table('class_sessions')
                    ->select(DB::raw("LOWER(COALESCE(student_email, client_email)) as em"), DB::raw("LOWER(COALESCE(location,'')) as loc"), DB::raw('COUNT(*) as cnt'))
                    ->whereIn(DB::raw("LOWER(COALESCE(student_email, client_email))"), $emails)
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed','completed']);
                    })
                    ->groupBy('em','loc')
                    ->get();
                foreach ($csEmail as $row) {
                    $sid = $emailMap[$row->em] ?? null;
                    if (!$sid) continue;
                    $flags = [ 'in_uk' => false, 'in_spain' => false, 'in_france' => false ];
                    if ($row->loc === 'uk') $flags['in_uk'] = true;
                    if ($row->loc === 'spain') $flags['in_spain'] = true;
                    if ($row->loc === 'france') $flags['in_france'] = true;
                    if (array_filter($flags)) {
                        DB::table('students')->where('id', $sid)->update($flags);
                        $updated++;
                    }
                }
            }
        });

        $this->info("Done. Updated {$updated} rows across {$total} students.");
        return Command::SUCCESS;
    }
}
