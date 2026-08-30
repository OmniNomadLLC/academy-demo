<?php

namespace App\Jobs;

use App\Services\AcuityService;
use App\Services\LocationMappingService;
use App\Services\Acuity\AppointmentExtractor;
use App\Support\EmailNormalizer;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Support\AcuitySchoolClassSynchronizer;
use App\Support\StudentEmailResolver;
use App\Traits\FlagsStudentDuplicates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SyncAcuityAppointment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use FlagsStudentDuplicates;

    public int $tries = 1;
    public int $timeout = 300;
    public int $backoff = 1;

    protected $appointmentId;
    protected ?string $webhookAction = null;
    protected ?AcuitySchoolClassSynchronizer $schoolClassSynchronizer = null;

    public function __construct($appointmentId, ?string $webhookAction = null)
    {
        $this->appointmentId = $appointmentId;
        $this->webhookAction = $webhookAction;
    }

    public function handle(AcuitySchoolClassSynchronizer $schoolClassSynchronizer)
    {
        $this->schoolClassSynchronizer = $schoolClassSynchronizer;
        try {
            $acuity = new AcuityService();
            $appointment = $acuity->getAppointment($this->appointmentId);

            if (!$appointment) {
                // If webhook indicates a cancellation, mark locally as cancelled even if API no longer returns it
                $action = is_string($this->webhookAction) ? strtolower($this->webhookAction) : '';
                if ($action && (str_contains($action, 'cancel') || str_contains($action, 'canceled') || str_contains($action, 'cancelled'))) {
                    \App\Models\ClassSession::query()
                        ->where('acuity_appointment_id', (string) $this->appointmentId)
                        ->update([
                            'status' => 'cancelled',
                            'canceled' => true,
                        ]);
                    Log::info("Marked appointment {$this->appointmentId} as cancelled locally (missing in API)");
                    return;
                }
                Log::warning("Appointment {$this->appointmentId} not found in Acuity");
                return;
            }

            // Defensive guard: ensure required fields are present
            if (empty($appointment['datetime'])) {
                Log::warning("Appointment {$this->appointmentId} missing datetime; skipping");
                return;
            }

            $processed = $this->syncAppointment($appointment);

            if ($processed) {
                Log::info("Successfully synced appointment {$this->appointmentId}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to sync appointment {$this->appointmentId}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function syncAppointment($appointmentData)
    {
        // Extract tolerant fields
        $ex = AppointmentExtractor::extract($appointmentData);
        // Use category to infer location
        $category = $ex['category'] ?? 'UK';
        $location = LocationMappingService::getLocationFromCategory($category);

        $clientId = $ex['clientId'] ?? null;
        $email = EmailNormalizer::fromAcuity($appointmentData) ?? ($ex['clientEmail'] ?? '');

        $student = $this->resolveStudentFromAppointment($clientId, $email, $appointmentData);
        $studentId = $student?->id;

        if ($student?->wasRecentlyCreated) {
            if ($student->previous_student_id) {
                Log::warning(sprintf(
                    'Spawned new student #%d (%s) linked to archived previous_student_id #%d for second-chance flow.',
                    $student->id,
                    $student->email ?? '?',
                    $student->previous_student_id
                ));
            }
            $this->flagIfDuplicate($student);
        }

        // Find or create school class with location
        $schoolClass = $this->findOrCreateSchoolClass($appointmentData, $location);
        
        // Find teacher by Acuity calendar ID when available; fallback to first active teacher/admin
        $calendarId = data_get($appointmentData, 'calendarID')
            ?? data_get($appointmentData, 'calendarId')
            ?? data_get($appointmentData, 'calendar.id')
            ?? (is_scalar(data_get($appointmentData, 'calendar')) ? data_get($appointmentData, 'calendar') : null);

        $appointmentTypeId = data_get($appointmentData, 'appointmentTypeID')
            ?? data_get($appointmentData, 'appointmentTypeId')
            ?? data_get($appointmentData, 'appointmentType.id')
            ?? data_get($appointmentData, 'typeID')
            ?? data_get($appointmentData, 'TypeID');
        $appointmentTypeId = is_scalar($appointmentTypeId) ? (string) $appointmentTypeId : null;

        $teacher = null;

        if ($appointmentTypeId && Schema::hasTable('teacher_appointment_type_assignments')) {
            $teacher = User::query()
                ->whereIn('role', User::TEACHING_ROLES)
                ->where('is_active', true)
                ->whereHas('teacherAppointmentTypeAssignments', function ($query) use ($appointmentTypeId) {
                    $query->where('acuity_appointment_type_id', $appointmentTypeId);
                })
                ->first();
        }

        if (! $teacher && $calendarId) {
            $teacher = User::whereIn('role', User::TEACHING_ROLES)
                ->where('is_active', true)
                ->where(function ($query) use ($calendarId) {
                    $calendarId = (string) $calendarId;
                    $query->where('acuity_calendar_id', $calendarId)
                        ->orWhereJsonContains('teacher_calendar_ids', $calendarId);
                })
                ->first();
        }

        if (! $teacher) {
            $teacher = User::whereIn('role', User::TEACHING_ROLES)->where('is_active', true)->first()
                ?? User::where('role', 'admin')->first();
        }

        Log::info('[SyncAcuityAppointment] Teacher mapping', [
            'appointmentId' => $this->appointmentId,
            'calendarId' => $calendarId,
            'appointmentTypeId' => $appointmentTypeId,
            'teacher_id' => $teacher?->id,
            'teacher_email' => $teacher?->email,
        ]);

        $appointmentDate = Carbon::parse($appointmentData['datetime']);
        $tz = data_get($appointmentData, 'timezone');
        if (is_string($tz) && trim($tz) !== '') {
            // Align stored date/time to Acuity's timezone to match UI expectations
            $appointmentDate = $appointmentDate->setTimezone($tz);
        }
        
        // Build update payload and include student_id only if the column exists
        $existingSession = ClassSession::query()
            ->where('acuity_appointment_id', $appointmentData['id'])
            ->first();

        $teacherId = $teacher ? $teacher->id : null;
        $assignedTeacherId = $teacherId;

        if ($existingSession && $existingSession->cover_teacher_id) {
            $teacherId = $existingSession->teacher_id;
            $assignedTeacherId = $existingSession->assigned_teacher_id ?? $assignedTeacherId;
        }

        $cancelled = $this->shouldMarkCancelled($appointmentData);
        if ($cancelled) {
            $appointmentData['canceled'] = true;
        }

        $update = [
            'school_class_id' => $schoolClass->id,
            'teacher_id' => $teacherId,
            'assigned_teacher_id' => $assignedTeacherId,
            'session_date' => $appointmentDate->toDateString(),
            'start_time' => $appointmentDate->format('H:i:s'),
            'end_time' => $appointmentDate->copy()->addMinutes((int)($appointmentData['duration'] ?? 60))->format('H:i:s'),
            'status' => $this->mapAcuityStatus($appointmentData),
            'canceled' => $cancelled,
            'location' => $location,
            'calendar_name' => $ex['calendar'] ?? ($appointmentData['calendar'] ?? null),
            'calendar_norm' => $ex['calendar_norm'] ?? null,
            'category_norm' => $ex['category_norm'] ?? ($appointmentData['category'] ?? null),
            'client_email' => $email ?: null,
            'student_email' => $email ?: null,
            'max_students' => 10,
            'notes' => $appointmentData['notes'] ?? null,
            'acuity_data' => $appointmentData,
            'link_status' => $studentId ? (!empty($email) ? 'linked_by_email' : 'linked_by_client') : 'unlinked',
            'student_id' => $studentId,
        ];

        $classSession = ClassSession::updateOrCreate(
            ['acuity_appointment_id' => $appointmentData['id']],
            $update
        );

        if ($teacher && isset($schoolClass) && $schoolClass->exists && $schoolClass->teacher_id !== $teacher->id) {
            $schoolClass->teacher_id = $teacher->id;
            $schoolClass->save();
        }

        if ($teacher && isset($classSession) && $appointmentTypeId && Schema::hasTable('teacher_appointment_type_assignments')) {
            // Ensure future syncs respect the assignment cache
            $teacher->teacherAppointmentTypeAssignments()
                ->where('acuity_appointment_type_id', $appointmentTypeId)
                ->update(['acuity_calendar_id' => $calendarId ? (string) $calendarId : null]);
        }

        if (!$studentId) {
            Log::info("Appointment {$this->appointmentId} stored unlinked (no student match)");
        }

        if ($student) {
            $this->updateStudentSchedule($student);
        }
        return true;
    }

    // Student records are now auto-created/updated during appointment sync to keep data in step with Acuity.

    private function resolveStudentFromAppointment(?string $clientId, ?string $email, array $appointmentData): ?Student
    {
        $normalizedEmail = EmailNormalizer::normalize($email);
        $emailRaw = data_get($appointmentData, 'email')
            ?? data_get($appointmentData, 'client.email')
            ?? data_get($appointmentData, 'clientEmail')
            ?? ($normalizedEmail ?: null);
        $emailRaw = is_string($emailRaw) ? trim($emailRaw) : null;

        $student = app(StudentEmailResolver::class)->resolveOrSpawn($clientId, $normalizedEmail);

        if (! $student) {
            Log::warning('SyncAcuityAppointment: skipping student creation (no identifiers)', [
                'appointment_id' => $this->appointmentId,
            ]);
            return null;
        }

        $payload = [
            'first_name' => data_get($appointmentData, 'firstName') ?: data_get($appointmentData, 'client.firstName') ?: '',
            'last_name' => data_get($appointmentData, 'lastName') ?: data_get($appointmentData, 'client.lastName') ?: '',
            'email' => $emailRaw ?? $normalizedEmail,
            'phone' => data_get($appointmentData, 'phone') ?: data_get($appointmentData, 'client.phone'),
            'registration_date' => now()->toDateString(),
            'is_active' => true,
            'notes' => data_get($appointmentData, 'notes') ?: null,
        ];

        if (! Student::emailNormIsGenerated()) {
            $payload['email_norm'] = $normalizedEmail;
        }

        $student->fill(array_filter($payload, fn ($value) => $value !== null));

        // Skip acuity_client_id assignment when this is a respawn — the archived predecessor
        // still holds the unique slot. Future syncs resolve the spawn via email/session-history.
        if (! empty($clientId) && empty($student->acuity_client_id) && empty($student->previous_student_id)) {
            $student->acuity_client_id = (string) $clientId;
        }

        $student->setAcuityCategoryAndLocation($appointmentData['category'] ?? null);
        $student->save();

        Log::info('SyncAcuityAppointment resolved student', [
            'appointment_id' => $this->appointmentId,
            'student_id' => $student->id,
            'client_id' => $clientId,
            'email' => $emailRaw ?? $normalizedEmail,
            'created' => $student->wasRecentlyCreated,
        ]);

        return $student;
    }

    protected function updateStudentSchedule(Student $student): void
    {
        $studentId = $student->id;
        if (! $studentId) {
            return;
        }

        $today = Carbon::today(config('app.timezone'));

        $next = ClassSession::query()
            ->where('student_id', $studentId)
            ->whereDate('session_date', '>=', $today->toDateString())
            ->where(function ($w) {
                $w->where('canceled', false)
                    ->orWhereNull('canceled')
                    ->orWhereIn('status', ['scheduled', 'confirmed']);
            })
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->first();

        $latest = ClassSession::query()
            ->where('student_id', $studentId)
            ->whereDate('session_date', '<', $today->toDateString())
            ->where(function ($w) {
                $w->where('canceled', false)
                    ->orWhereNull('canceled')
                    ->orWhereIn('status', ['scheduled', 'confirmed']);
            })
            ->orderByDesc('session_date')
            ->orderByDesc('start_time')
            ->first();

        $student->next_appointment_date = $next?->session_date;
        $student->last_appointment_date = $latest?->session_date ?? $student->last_appointment_date;

        $isActive = false;
        if ($student->last_appointment_date && Carbon::parse($student->last_appointment_date)->gte($today->copy()->subDays(14))) {
            $isActive = true;
        }
        if (! $isActive && $student->next_appointment_date && Carbon::parse($student->next_appointment_date)->lte($today->copy()->addDays(45))) {
            $isActive = true;
        }

        if (Schema::hasColumn('students', 'is_active_recent')) {
            $student->is_active_recent = $isActive;
        }

        $student->save();
    }

    private function findOrCreateSchoolClass($appointmentData, $location)
    {
        $synchronizer = $this->schoolClassSynchronizer ?? app(AcuitySchoolClassSynchronizer::class);

        return $synchronizer->syncFromAppointment($appointmentData, [
            'location' => $location,
        ]);
    }

    private function mapAcuityStatus($appointmentData)
    {
        $datetime = Carbon::parse($appointmentData['datetime']);

        if (isset($appointmentData['canceled']) && $appointmentData['canceled']) {
            return 'cancelled';
        }

        return $datetime->isPast() ? 'completed' : 'scheduled';
    }

    private function shouldMarkCancelled(array $appointmentData): bool
    {
        if (!empty($appointmentData['canceled'])) {
            return true;
        }

        $status = strtolower((string)($appointmentData['status'] ?? ''));
        if (in_array($status, ['canceled', 'cancelled'], true)) {
            return true;
        }

        $action = strtolower((string)($this->webhookAction ?? ''));
        if ($action && str_contains($action, 'cancel')) {
            return true;
        }

        return false;
    }
}
