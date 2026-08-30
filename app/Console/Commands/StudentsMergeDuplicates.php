<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentsMergeDuplicates extends Command
{
    protected $signature = 'students:merge-duplicates {--dry-run : Preview changes only} {--limit=0 : Limit number of duplicate email groups to process}';
    protected $description = 'Merge duplicate students by email (case-insensitive), reassign attendance, and delete duplicates.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $groups = DB::table('students')
            ->selectRaw('LOWER(email) as email_lc, COUNT(*) as cnt')
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->groupBy(DB::raw('LOWER(email)'))
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')
            ->when($limit > 0, fn($q) => $q->limit($limit))
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate emails found.');
            return self::SUCCESS;
        }

        $this->info('Duplicate groups: '.count($groups));

        $processed = 0; $moved = 0; $deleted = 0;
        $hasStudentIdCol = Schema::hasColumn('class_sessions', 'student_id');

        foreach ($groups as $g) {
            $emailLc = $g->email_lc;
            $ids = DB::table('students')->whereRaw('LOWER(email) = ?', [$emailLc])->pluck('id');
            if ($ids->count() < 2) continue;

            // Choose target: prefer one with acuity_client_id, else lowest id
            $target = DB::table('students')
                ->whereIn('id', $ids)
                ->orderByRaw("CASE WHEN COALESCE(acuity_client_id,'') <> '' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
            if (!$target) continue;
            $targetId = $target->id;
            $otherIds = $ids->filter(fn($i) => $i !== $targetId)->values();

            $this->line("Merging email {$emailLc}: keep #{$targetId}, remove [".implode(',', $otherIds->all()).']');

            if ($dry) { $processed++; continue; }

            // Reassign attendance_records
            $moved += DB::table('attendance_records')->whereIn('student_id', $otherIds)->update(['student_id' => $targetId]);

            // Optionally update class_sessions.student_id if present
            if ($hasStudentIdCol) {
                DB::table('class_sessions')->whereIn('student_id', $otherIds)->update(['student_id' => $targetId]);
            }

            // Delete duplicates
            $deleted += DB::table('students')->whereIn('id', $otherIds)->delete();
            $processed++;
        }

        $this->info("Processed groups: {$processed}");
        if ($dry) {
            $this->info('Dry run complete. No changes applied.');
        } else {
            $this->info("Attendance reassigned: {$moved}, Students deleted: {$deleted}");
        }

        return self::SUCCESS;
    }
}

