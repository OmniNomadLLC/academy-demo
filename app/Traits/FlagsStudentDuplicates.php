<?php

namespace App\Traits;

use App\Models\Student;
use App\Models\StudentMergeSuggestion;
use Illuminate\Support\Facades\Log;

trait FlagsStudentDuplicates
{
    /**
     * After a new Student is created via Acuity sync, check whether an existing
     * active student has the same first + last name. If so, create a pending
     * StudentMergeSuggestion so an admin can review and confirm the merge.
     *
     * Uses min/max ID ordering so the pair (a, b) is always stored consistently
     * regardless of which record was created first.
     */
    protected function flagIfDuplicate(Student $newStudent): void
    {
        if (! $newStudent->wasRecentlyCreated) {
            return;
        }

        if (empty(trim((string) $newStudent->first_name)) || empty(trim((string) $newStudent->last_name))) {
            return;
        }

        try {
            $matches = Student::whereRaw('LOWER(first_name) = ?', [strtolower($newStudent->first_name)])
                ->whereRaw('LOWER(last_name) = ?', [strtolower($newStudent->last_name)])
                ->where('id', '!=', $newStudent->id)
                ->whereNull('deleted_at')
                ->whereNull('archived_at')
                ->get();

            foreach ($matches as $existing) {
                $aId = min($existing->id, $newStudent->id);
                $bId = max($existing->id, $newStudent->id);

                $suggestion = StudentMergeSuggestion::firstOrCreate(
                    ['student_a_id' => $aId, 'student_b_id' => $bId],
                    ['reason' => 'same_name', 'status' => 'pending']
                );

                Log::info('FlagsStudentDuplicates: merge suggestion created', [
                    'suggestion_id' => $suggestion->id,
                    'student_a_id'  => $aId,
                    'student_b_id'  => $bId,
                    'was_new'       => $suggestion->wasRecentlyCreated,
                    'new_student'   => $newStudent->id,
                    'matched'       => $existing->id,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let duplicate detection block the sync pipeline
            Log::warning('FlagsStudentDuplicates: error during duplicate check', [
                'student_id' => $newStudent->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
