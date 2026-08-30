<?php

namespace App\Support;

use App\Models\ClassSession;
use App\Models\Student;

/**
 * Resolves an inbound Acuity sync event to an active Student row, or
 * spawns a fresh Student linked to an archived predecessor via
 * previous_student_id (the "second-chance" flow for returning the referral partner
 * students who were previously archived for inactivity).
 *
 * Returned Student is either:
 *   - existing active row (caller fills + saves to update)
 *   - new Student() with previous_student_id pre-set when an archived
 *     predecessor matched by email (caller fills + saves to create)
 *   - new Student() (fresh, no predecessor) when no match anywhere
 *   - null when there are no identifiers at all (caller should skip)
 *
 * The helper never saves — the caller controls the persist step so it
 * can apply its own payload, set acuity_client_id, and run downstream
 * hooks (flagIfDuplicate, linkSessionsToStudent, etc.) consistently.
 */
class StudentEmailResolver
{
    public function resolveOrSpawn(?string $clientId, ?string $normalizedEmail): ?Student
    {
        $clientIdStr = ! empty($clientId) ? (string) $clientId : null;
        $emailLower = $normalizedEmail ? strtolower($normalizedEmail) : null;

        if ($clientIdStr) {
            $student = Student::query()
                ->whereNull('archived_at')
                ->where('acuity_client_id', $clientIdStr)
                ->first();
            if ($student) {
                return $student;
            }
        }

        if ($emailLower) {
            $student = Student::query()
                ->whereNull('archived_at')
                ->whereRaw('LOWER(email) = ?', [$emailLower])
                ->first();
            if ($student) {
                return $student;
            }
        }

        if ($clientIdStr) {
            $linkedSession = ClassSession::query()
                ->whereNotNull('student_id')
                ->where(function ($q) use ($clientIdStr) {
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')) = ?", [$clientIdStr])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId')) = ?", [$clientIdStr]);
                })
                ->orderByDesc('session_date')
                ->first();

            if ($linkedSession?->student_id) {
                $candidate = Student::query()
                    ->whereNull('archived_at')
                    ->find($linkedSession->student_id);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        $predecessor = $this->findArchivedPredecessor($emailLower);
        if ($predecessor) {
            $spawn = new Student();
            $spawn->previous_student_id = $predecessor->id;
            return $spawn;
        }

        if (! $clientIdStr && ! $emailLower) {
            return null;
        }

        return new Student();
    }

    public function findArchivedPredecessor(?string $emailLower): ?Student
    {
        if (! $emailLower) {
            return null;
        }

        return Student::query()
            ->whereNotNull('archived_at')
            ->whereRaw('LOWER(email) = ?', [$emailLower])
            ->orderByDesc('archived_at')
            ->first();
    }
}
