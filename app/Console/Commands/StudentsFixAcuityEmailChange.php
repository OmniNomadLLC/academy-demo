<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes duplicate student records caused by an email change in Acuity Scheduling.
 *
 * Root cause: when a student's email is updated in Acuity, both SyncAcuityAppointment
 * and SyncAcuityClient fail to find the existing student (old email no longer matches,
 * acuity_client_id often not stored), so they create a second student record.
 *
 * Detection strategy: find Acuity clientId values that appear in class_sessions.acuity_data
 * for more than one distinct student. Those groups need merging.
 *
 * Merge logic:
 *   - "Primary" student: prefers the one with acuity_client_id already set;
 *     ties broken by lowest id (oldest record).
 *   - All foreign-key references (sessions, attendance, assessments, notes,
 *     enrollment messages, employment) are moved to the primary.
 *   - Primary gets the newest email from Acuity data and acuity_client_id stamped.
 *   - Secondary records are soft-deleted.
 */
class StudentsFixAcuityEmailChange extends Command
{
    protected $signature = 'students:fix-acuity-email-change
                            {--dry-run : Show what would change without touching the database}
                            {--client-id= : Restrict to a single Acuity clientId for targeted fixes}';

    protected $description = 'Merge duplicate students created by an email change in Acuity (detects via session acuity_data JSON).';

    /** Tables with a student_id FK we need to repoint. Add more here if new ones ship. */
    private array $studentFkTables = [
        'class_sessions',
        'attendance_records',
        'student_assessments',
        'student_progress_notes',
        'student_enrollment_messages',
        'employment_outcomes',
        'employment_profiles',
        'student_skill_progress_logs',
    ];

    public function handle(): int
    {
        $dry        = (bool) $this->option('dry-run');
        $filterCid  = $this->option('client-id');

        if ($dry) {
            $this->warn('DRY-RUN — no changes will be written.');
        }

        $groups = $this->findDuplicateGroups($filterCid);

        if (empty($groups)) {
            $this->info('No duplicate student groups found. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d duplicate group(s) to process.', count($groups)));

        $totalMerged = 0;

        foreach ($groups as $clientIdStr => $studentIds) {
            $this->line('');
            $this->comment("Acuity clientId: {$clientIdStr}");

            $students = DB::table('students')
                ->whereIn('id', $studentIds)
                ->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN COALESCE(acuity_client_id,'') <> '' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();

            if ($students->count() < 2) {
                $this->line("  Skipping — fewer than 2 active students found (may already be merged).");
                continue;
            }

            $primary    = $students->first();
            $secondaries = $students->skip(1)->values();

            $newEmail = $this->resolveNewestEmail($clientIdStr, $primary);

            $this->line("  Primary  : #{$primary->id} | email={$primary->email} | acuity_client_id=".($primary->acuity_client_id ?? 'NULL'));
            foreach ($secondaries as $s) {
                $this->line("  Secondary: #{$s->id} | email={$s->email} | acuity_client_id=".($s->acuity_client_id ?? 'NULL'));
            }
            if ($newEmail && $newEmail !== $primary->email) {
                $this->line("  Email update: '{$primary->email}' → '{$newEmail}'");
            }

            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($primary, $secondaries, $clientIdStr, $newEmail) {
                $secondaryIds = $secondaries->pluck('id')->all();

                // Repoint every FK table
                foreach ($this->studentFkTables as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'student_id')) {
                        continue;
                    }
                    $updated = DB::table($table)
                        ->whereIn('student_id', $secondaryIds)
                        ->update(['student_id' => $primary->id]);
                    if ($updated) {
                        Log::info("StudentsFixAcuityEmailChange: reassigned {$updated} rows in {$table}", [
                            'primary_id'    => $primary->id,
                            'secondary_ids' => $secondaryIds,
                        ]);
                    }
                }

                // Stamp acuity_client_id on primary if missing
                $updates = [];
                if (empty($primary->acuity_client_id)) {
                    $updates['acuity_client_id'] = $clientIdStr;
                }
                // Update email to newest Acuity value if it changed
                if ($newEmail && $newEmail !== $primary->email) {
                    // Guard: only update if no other active student already holds this email
                    $conflict = DB::table('students')
                        ->whereRaw('LOWER(email) = ?', [strtolower($newEmail)])
                        ->where('id', '!=', $primary->id)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (! $conflict) {
                        $updates['email'] = $newEmail;
                        if (Schema::hasColumn('students', 'email_norm')) {
                            $updates['email_norm'] = strtolower($newEmail);
                        }
                    } else {
                        Log::warning('StudentsFixAcuityEmailChange: email conflict, keeping old email', [
                            'primary_id' => $primary->id,
                            'new_email'  => $newEmail,
                        ]);
                    }
                }
                if ($updates) {
                    DB::table('students')->where('id', $primary->id)->update($updates);
                }

                // Soft-delete secondaries
                DB::table('students')
                    ->whereIn('id', $secondaryIds)
                    ->update(['deleted_at' => now()]);
            });

            $this->info("  Merged: secondary IDs [".implode(', ', $secondaries->pluck('id')->all())."] → primary #{$primary->id}");
            $totalMerged++;
        }

        $this->line('');
        if ($dry) {
            $this->warn("Dry-run complete. {$totalMerged} group(s) would be merged. Run without --dry-run to apply.");
        } else {
            $this->info("Done. {$totalMerged} group(s) merged.");
        }

        return self::SUCCESS;
    }

    /**
     * Find Acuity clientId values that are referenced in class_sessions.acuity_data
     * by more than one distinct student_id.
     *
     * @return array<string, int[]>  clientIdStr => [studentId, ...]
     */
    private function findDuplicateGroups(?string $filterClientId): array
    {
        $query = DB::table('class_sessions')
            ->selectRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                ) AS acuity_client_id,
                GROUP_CONCAT(DISTINCT student_id ORDER BY student_id SEPARATOR ',') AS student_ids,
                COUNT(DISTINCT student_id) AS student_count
            ")
            ->whereNotNull('student_id')
            ->whereRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                ) IS NOT NULL
                AND COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                ) != 'null'
            ")
            ->groupByRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                )
            ")
            ->havingRaw('COUNT(DISTINCT student_id) > 1');

        if ($filterClientId) {
            $query->havingRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                ) = ?
            ", [$filterClientId]);
        }

        $rows = $query->get();

        $groups = [];
        foreach ($rows as $row) {
            if (empty($row->acuity_client_id)) {
                continue;
            }
            $groups[$row->acuity_client_id] = array_map('intval', explode(',', $row->student_ids));
        }

        return $groups;
    }

    /**
     * Pick the newest email from the most recent session that has this clientId.
     * Falls back to the primary student's current email.
     */
    private function resolveNewestEmail(string $clientIdStr, object $primary): ?string
    {
        $session = DB::table('class_sessions')
            ->whereRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId'))
                ) = ?
            ", [$clientIdStr])
            ->whereRaw("
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.email')),
                    JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientEmail'))
                ) IS NOT NULL
            ")
            ->orderByDesc('session_date')
            ->select([
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.email')), JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientEmail'))) AS email"),
            ])
            ->first();

        $candidate = $session?->email;

        return ($candidate && filter_var($candidate, FILTER_VALIDATE_EMAIL))
            ? strtolower(trim($candidate))
            : null;
    }
}
