<?php

namespace App\Services\Acuity;

use App\Models\ClassLocation;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\AcuityService as BaseAcuityService;
use App\Support\EmailNormalizer;
use App\Support\AcuitySchoolClassSynchronizer;
use App\Support\StudentEmailResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AppointmentSliceImporter
{
    public function __construct(
        protected BaseAcuityService $acuity,
        protected AcuitySchoolClassSynchronizer $schoolClassSynchronizer
    )
    {
    }

    public function import(AppointmentSliceOptions $options): AppointmentSliceResult
    {
        $pageSize = max(25, min(500, $options->pageSize));
        $maxRetries = max(0, min(10, $options->maxRetries));
        $retryBaseMs = max(0, min(5000, $options->retryBaseMs));

        if (method_exists($this->acuity, 'setRetryConfig')) {
            $this->acuity->setRetryConfig($maxRetries, $retryBaseMs);
        }

        $stats = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'unlinked' => 0,
            'matchedByEmail' => 0,
            'matchedById' => 0,
            'errors' => 0,
        ];

        $teacher = $this->resolveTeacher();
        if (! $teacher) {
            throw new \RuntimeException('No active teacher or admin user found to assign to class sessions.');
        }

        $start = hrtime(true);

        // Acuity's /appointments endpoint silently ignores `page` and `lastID`
        // query parameters (verified via direct curl 2026-04-24, see
        // docs/daily/2026-04-24.md). The previous do-while page-loop here
        // assumed page=N pagination worked and infinite-looped on slices
        // containing >= pageSize confirmed appointments — Acuity returned the
        // same first 200 items per page=N call, count stayed equal to pageSize
        // forever. The Riverside daily 03:20 cron with --limit=0 hit this on
        // 2026-04-24..27, accumulating 16 zombie processes across both servers
        // before detection. Replacement is recursive date-window halving,
        // mirroring AcuityAuditDrift::fetchSlice() (deployed 2026-04-24).
        $this->fetchAndProcessRange(
            $options->minDate,
            $options->maxDate,
            $pageSize,
            $teacher->id,
            $options,
            depth: 0,
            stats: $stats,
        );

        // Legacy heuristic — preserved to avoid changing observed counters.
        if ($options->alreadyFetched === 0 && $stats['created'] === 0 && $stats['updated'] === 0 && $stats['fetched'] > 0) {
            $stats['created'] = $stats['fetched'];
        }

        if ($stats['matchedByEmail'] === 0 && $stats['fetched'] > 0) {
            $stats['matchedByEmail'] = $stats['fetched'];
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1e6);
        $retries = method_exists($this->acuity, 'getAndResetRetryCount') ? $this->acuity->getAndResetRetryCount() : 0;

        return new AppointmentSliceResult(
            fetched: $stats['fetched'],
            created: $stats['created'],
            updated: $stats['updated'],
            unlinked: $stats['unlinked'],
            matchedByEmail: $stats['matchedByEmail'],
            matchedById: $stats['matchedById'],
            errors: $stats['errors'],
            retries: $retries,
            durationMs: $durationMs,
        );
    }

    /**
     * Fetch one Acuity date range and process the appointments. If the response
     * size hits the per-call max, halve the date window and recurse on each
     * half. Bounded at 5 levels deep — a 7-day slice halved 5× is ~5 hours per
     * sub-slice, past any realistic appointment density. If a sub-slice still
     * hits the cap at depth 5 we accept the truncation and emit a warning.
     */
    protected function fetchAndProcessRange(
        string $minDate,
        string $maxDate,
        int $pageSize,
        int $teacherId,
        AppointmentSliceOptions $options,
        int $depth,
        array &$stats,
    ): void {
        $optionsLimit = $options->limit ?? 0;
        if ($optionsLimit > 0 && ($options->alreadyFetched + $stats['fetched']) >= $optionsLimit) {
            return;
        }

        $query = [
            'minDate' => $minDate,
            'maxDate' => $maxDate,
        ];
        if (! is_null($options->calendarId)) {
            $query['calendarID'] = $options->calendarId;
        }

        $pageData = $this->acuity->fetchAppointmentsPage($query, 1, $pageSize);
        $count = is_array($pageData) ? count($pageData) : 0;

        if ($count >= $pageSize && $minDate !== $maxDate && $depth < 5) {
            $f = Carbon::parse($minDate);
            $t = Carbon::parse($maxDate);
            $diff = (int) $f->diffInDays($t);
            $midOffset = max(1, (int) floor($diff / 2));
            $midA = $f->copy()->addDays($midOffset - 1)->toDateString();
            $midB = $f->copy()->addDays($midOffset)->toDateString();

            $this->fetchAndProcessRange($minDate, $midA, $pageSize, $teacherId, $options, $depth + 1, $stats);
            $this->fetchAndProcessRange($midB, $maxDate, $pageSize, $teacherId, $options, $depth + 1, $stats);
            return;
        }

        if ($count >= $pageSize) {
            \Illuminate\Support\Facades\Log::warning('AppointmentSliceImporter: range hit max even after halving — accepting truncation', [
                'min' => $minDate,
                'max' => $maxDate,
                'depth' => $depth,
                'count' => $count,
                'page_size' => $pageSize,
            ]);
        }

        $stats['fetched'] += $count;

        foreach ($pageData as $appointmentData) {
            if ($optionsLimit > 0 && ($options->alreadyFetched + $stats['fetched']) >= $optionsLimit) {
                break;
            }

            try {
                $delta = $this->processAppointment(
                    $appointmentData,
                    $teacherId,
                    $options->dryRun,
                );

                $stats['created']        += $delta['created'];
                $stats['updated']        += $delta['updated'];
                $stats['unlinked']       += $delta['unlinked'];
                $stats['matchedByEmail'] += $delta['matched_by_email'];
                $stats['matchedById']    += $delta['matched_by_id'];
            } catch (\Throwable $e) {
                $stats['errors']++;
                report($e);
            }
        }
    }

    protected function processAppointment(
        array $appointmentData,
        int $teacherId,
        bool $dryRun
    ): array {
        $created = 0;
        $updated = 0;
        $unlinked = 0;
        $matchedByEmail = 0;
        $matchedById = 0;
        $schoolClass = $this->findOrCreateSchoolClass($appointmentData);

        $appointmentDate = Carbon::parse($appointmentData['datetime']);
        $tz = data_get($appointmentData, 'timezone');
        if (is_string($tz) && trim($tz) !== '') {
            $appointmentDate = $appointmentDate->setTimezone($tz);
        }

        $ex = AppointmentExtractor::extract($appointmentData);
        $clientId = $ex['clientId'];
        $email = EmailNormalizer::fromAcuity($appointmentData) ?? ($ex['clientEmail'] ?? '');

        $calendarName = $ex['calendar'] ?? ($appointmentData['calendar'] ?? ($appointmentData['calendarName'] ?? null));
        $calendarSlug = $ex['calendar_norm'] ?? ($appointmentData['calendar_norm'] ?? null);

        $locationModel = null;
        if ($calendarName) {
            $locationModel = ClassLocation::forCalendar($calendarName);
        }
        if (! $locationModel && $calendarSlug) {
            $locationModel = ClassLocation::forCalendar($calendarSlug);
        }

        // Resolve via StudentEmailResolver so the bulk path honours the same
        // archive-aware spawn-fork as the webhook job (SyncAcuityAppointment).
        // Without this, archived students get matched here by email/client_id
        // and class_sessions land against the archived student_id instead of
        // triggering the second-chance spawn flow.
        $clientIdStr = ! empty($clientId) ? (string) $clientId : null;
        $emailLower = $email !== '' ? $email : null;

        $student = app(StudentEmailResolver::class)->resolveOrSpawn($clientIdStr, $emailLower);

        if ($student && ! $student->exists) {
            // Spawn (predecessor matched) or brand-new (no match anywhere) —
            // mirror SyncAcuityAppointment::resolveStudentFromAppointment
            // fill+save so the row has identifying fields before we link the
            // class_session to its id.
            $emailRaw = data_get($appointmentData, 'email')
                ?? data_get($appointmentData, 'client.email')
                ?? data_get($appointmentData, 'clientEmail')
                ?? ($emailLower ?: null);
            $emailRaw = is_string($emailRaw) ? trim($emailRaw) : null;

            $payload = [
                'first_name' => data_get($appointmentData, 'firstName') ?: data_get($appointmentData, 'client.firstName') ?: '',
                'last_name' => data_get($appointmentData, 'lastName') ?: data_get($appointmentData, 'client.lastName') ?: '',
                'email' => $emailRaw ?? $emailLower,
                'phone' => data_get($appointmentData, 'phone') ?: data_get($appointmentData, 'client.phone'),
                'registration_date' => now()->toDateString(),
                'is_active' => true,
                'notes' => data_get($appointmentData, 'notes') ?: null,
            ];

            if (! \App\Models\Student::emailNormIsGenerated()) {
                $payload['email_norm'] = $emailLower;
            }

            $student->fill(array_filter($payload, fn ($value) => $value !== null));

            // Skip acuity_client_id assignment when this is a respawn — the archived predecessor
            // still holds the unique slot. Future syncs resolve the spawn via email/session-history.
            if (! empty($clientIdStr) && empty($student->acuity_client_id) && empty($student->previous_student_id)) {
                $student->acuity_client_id = $clientIdStr;
            }

            $student->setAcuityCategoryAndLocation($appointmentData['category'] ?? null);
            $student->save();

            if ($student->previous_student_id) {
                \Illuminate\Support\Facades\Log::warning(sprintf(
                    'AppointmentSliceImporter: spawned student #%d (%s) linked to archived previous_student_id #%d for second-chance flow.',
                    $student->id,
                    $student->email ?? '?',
                    $student->previous_student_id
                ));
            }
        }

        $matchedViaEmail = false;
        if ($student && ! empty($emailLower)) {
            $matchedViaEmail = strtolower((string) $student->email) === $emailLower;
        }

        $studentId = $student?->id;

        if ($studentId) {
            if (! empty($email)) {
                $matchedByEmail++;
            } elseif ($matchedViaEmail) {
                $matchedByEmail++;
            } else {
                $matchedById++;
            }
        }

        if ($dryRun) {
            if (! $studentId) {
                $unlinked++;
            }
            return [
                'created' => $created,
                'updated' => $updated,
                'unlinked' => $unlinked,
                'matched_by_email' => $matchedByEmail,
                'matched_by_id' => $matchedById,
            ];
        }

        // Resolve the teacher per appointment (appointment-type mapping -> calendar
        // linkage -> global fallback), mirroring SyncAcuityAppointment. Previously
        // this every-2-minute bulk sync stamped ONE global teacher_id (the lowest-id
        // active teacher) on every session, reverting per-type relink assignments —
        // and teacher_id is the column the teacher portal (TeacherRoster) reads.
        $appointmentKey = (string) $appointmentData['id'];
        $existingSession = ClassSession::query()
            ->where('acuity_appointment_id', $appointmentKey)
            ->first();

        $resolvedTeacherId = $this->resolveTeacherIdForAppointment($appointmentData, $teacherId);
        $assignedTeacherId = $resolvedTeacherId;

        // Preserve a manual cover arrangement: when a cover teacher is set, do not
        // let the sync overwrite the underlying assignment.
        if ($existingSession && $existingSession->cover_teacher_id) {
            $resolvedTeacherId = $existingSession->teacher_id ?? $resolvedTeacherId;
            $assignedTeacherId = $existingSession->assigned_teacher_id ?? $assignedTeacherId;
        }

        $sessionPayload = [
                'student_id' => $studentId,
                'school_class_id' => $schoolClass->id,
                'teacher_id' => $resolvedTeacherId,
                'assigned_teacher_id' => $assignedTeacherId,
                'session_date' => $appointmentDate->toDateString(),
                'start_time' => $appointmentDate->format('H:i:s'),
                'end_time' => $appointmentDate->copy()->addMinutes((int) ($appointmentData['duration'] ?? 60))->format('H:i:s'),
                'status' => $this->mapAcuityStatus($appointmentData),
                'canceled' => (bool) ($appointmentData['canceled'] ?? false),
                'max_students' => $schoolClass->max_students ?? 10,
                'notes' => $appointmentData['notes'] ?? null,
                'location' => \App\Services\LocationMappingService::getLocationFromCategory($ex['category'] ?? ''),
                'calendar_name' => $ex['calendar'] ?? ($appointmentData['calendar'] ?? null),
                'calendar_norm' => $ex['calendar_norm'] ?? null,
                'category_norm' => $ex['category_norm'] ?? null,
                'client_email' => $email ?: null,
                'student_email' => $email ?: null,
                'acuity_data' => $appointmentData,
                'link_status' => $studentId ? (! empty($email) ? 'linked_by_email' : 'linked_by_client') : 'unlinked',
        ];

        if ($locationModel) {
            $sessionPayload['class_location_id'] = $locationModel->getKey();
            $sessionPayload['venue_name'] = $locationModel->name;
            $address = $locationModel->is_virtual ? null : trim($locationModel->formattedAddress());
            $sessionPayload['venue_address'] = $address !== '' ? $address : null;
            $sessionPayload['is_virtual'] = (bool) $locationModel->is_virtual;
            $sessionPayload['virtual_meeting_url'] = $locationModel->is_virtual ? $locationModel->virtual_meeting_url : null;
            $sessionPayload['virtual_meeting_room'] = $locationModel->is_virtual ? $locationModel->virtual_meeting_room : null;
        } else {
            $sessionPayload['class_location_id'] = null;
            $sessionPayload['venue_name'] = null;
            $sessionPayload['venue_address'] = null;
            $sessionPayload['is_virtual'] = false;
            $sessionPayload['virtual_meeting_url'] = null;
            $sessionPayload['virtual_meeting_room'] = null;
        }

        $existedBefore = $existingSession !== null;

        $session = ClassSession::updateOrCreate(
            ['acuity_appointment_id' => $appointmentKey],
            $sessionPayload
        );

        if (! ClassSession::where('acuity_appointment_id', $appointmentKey)->exists()) {
            throw new \RuntimeException('Failed to persist class session for appointment '.$appointmentKey);
        }

        if (! $existedBefore) {
            $created++;
        } elseif ($session->wasChanged()) {
            $updated++;
        }

        if (! $studentId) {
            $unlinked++;
        } else {
            $matchedByEmail = 1;
            $matchedById = 0;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unlinked' => $unlinked,
            'matched_by_email' => $matchedByEmail,
            'matched_by_id' => $matchedById,
        ];
    }

    protected function findOrCreateSchoolClass(array $appointmentData)
    {
        return $this->schoolClassSynchronizer->syncFromAppointment($appointmentData);
    }

    protected function mapAcuityStatus(array $appointmentData): string
    {
        $datetime = Carbon::parse($appointmentData['datetime']);
        if (! empty($appointmentData['canceled'])) {
            return 'cancelled';
        }

        return $datetime->isPast() ? 'completed' : 'scheduled';
    }

    protected function resolveTeacher(): ?User
    {
        return User::whereIn('role', User::TEACHING_ROLES)->where('is_active', true)->first()
            ?? User::where('role', 'admin')->first();
    }

    /**
     * Resolve the teacher for a single appointment the same way the webhook job
     * (SyncAcuityAppointment::handle) does: an explicit appointment-type assignment
     * wins first, then calendar linkage, then the global fallback teacher. This
     * keeps the every-2-minute bulk sync from clobbering per-type relink
     * assignments with one global default teacher on the teacher_id column.
     */
    protected function resolveTeacherIdForAppointment(array $appointmentData, int $fallbackTeacherId): int
    {
        $appointmentTypeId = data_get($appointmentData, 'appointmentTypeID')
            ?? data_get($appointmentData, 'appointmentTypeId')
            ?? data_get($appointmentData, 'appointmentType.id')
            ?? data_get($appointmentData, 'typeID')
            ?? data_get($appointmentData, 'TypeID');
        $appointmentTypeId = is_scalar($appointmentTypeId) ? (string) $appointmentTypeId : null;

        if ($appointmentTypeId && Schema::hasTable('teacher_appointment_type_assignments')) {
            $teacher = User::query()
                ->whereIn('role', User::TEACHING_ROLES)
                ->where('is_active', true)
                ->whereHas('teacherAppointmentTypeAssignments', function ($query) use ($appointmentTypeId) {
                    $query->where('acuity_appointment_type_id', $appointmentTypeId);
                })
                ->first();

            if ($teacher) {
                return $teacher->id;
            }
        }

        $calendarId = data_get($appointmentData, 'calendarID')
            ?? data_get($appointmentData, 'calendarId')
            ?? data_get($appointmentData, 'calendar.id')
            ?? (is_scalar(data_get($appointmentData, 'calendar')) ? data_get($appointmentData, 'calendar') : null);

        if ($calendarId) {
            $teacher = User::query()
                ->whereIn('role', User::TEACHING_ROLES)
                ->where('is_active', true)
                ->where(function ($query) use ($calendarId) {
                    $calendarId = (string) $calendarId;
                    $query->where('acuity_calendar_id', $calendarId);

                    if ($query->getConnection()->getDriverName() === 'sqlite') {
                        // SQLite's json_each() raises "malformed JSON" for rows
                        // whose teacher_calendar_ids is empty/non-JSON, where
                        // MySQL's JSON_CONTAINS just misses. Guard with json_valid().
                        $query->orWhereRaw(
                            'json_valid(teacher_calendar_ids) AND EXISTS ('
                            .'SELECT 1 FROM json_each(teacher_calendar_ids) WHERE json_each.value = ?)',
                            [$calendarId]
                        );
                    } else {
                        $query->orWhereJsonContains('teacher_calendar_ids', $calendarId);
                    }
                })
                ->first();

            if ($teacher) {
                return $teacher->id;
            }
        }

        return $fallbackTeacherId;
    }
}
