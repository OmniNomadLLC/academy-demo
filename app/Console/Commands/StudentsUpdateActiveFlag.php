<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StudentsUpdateActiveFlag extends Command
{
    protected $signature = 'students:update-active-flag {--past=14} {--future=45} {--chunk=1000}';
    protected $description = 'Persist students.is_active_recent based on upcoming appointments within the configured window';

    public function handle(): int
    {
        $future = (int) $this->option('future');
        $chunk = (int) $this->option('chunk');
        $today = Carbon::today()->toDateString();
        $nowPlus = Carbon::today()->addDays($future)->toDateString();

        $this->info("Updating is_active_recent (non-cancelled class_sessions within {$future}d window)...");

        $activeIds = DB::table('class_sessions')
            ->whereBetween('session_date', [$today, $nowPlus])
            ->where(function ($w) {
                $w->where('canceled', false)->orWhereNull('canceled');
            })
            ->where(function ($w) {
                $w->where('status', '!=', 'cancelled')->orWhereNull('status');
            })
            ->whereNotNull('student_id')
            ->distinct()
            ->pluck('student_id')
            ->all();

        $activeSet = array_fill_keys($activeIds, true);
        $updated = 0; $total = 0;

        DB::table('students')->orderBy('id')->select(['id'])->chunkById($chunk, function ($rows) use (&$updated, &$total, $activeSet) {
            $idsTrue = [];
            $idsFalse = [];
            foreach ($rows as $r) {
                $total++;
                if (isset($activeSet[$r->id])) $idsTrue[] = $r->id; else $idsFalse[] = $r->id;
            }
            if (!empty($idsTrue)) { DB::table('students')->whereIn('id', $idsTrue)->update(['is_active_recent' => true]); $updated += count($idsTrue); }
            if (!empty($idsFalse)) { DB::table('students')->whereIn('id', $idsFalse)->update(['is_active_recent' => false]); $updated += count($idsFalse); }
        });

        $this->info("Updated {$updated} rows across {$total} students. Active: " . count($activeIds));
        return Command::SUCCESS;
    }
}
