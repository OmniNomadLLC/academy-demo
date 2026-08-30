<?php

namespace App\Filament\Portal\Pages;

use App\Models\AttendanceAction;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherRoster;
use App\Traits\HasAttendanceAuditLog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TakeAttendance extends Page
{
    use HasAttendanceAuditLog;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Attendance';
    protected static string $view = 'filament.pages.take-attendance';
    protected static string $routePath = 'take-attendance';

    public ?int $sessionId = null;
    public array $statuses = [];
    public int $presentCount = 0;
    public int $lateCount = 0;
    public int $absentCount = 0;
    public bool $rosterExpanded = true;
    public bool $logExpanded = false;

    public function toggleRoster(): void
    {
        $this->rosterExpanded = ! $this->rosterExpanded;
    }

    public function toggleLog(): void
    {
        $this->logExpanded = ! $this->logExpanded;
    }

    public function mount(): void
    {
        $sid = (int) request()->query('sessionId');
        $this->sessionId = $sid > 0 ? $sid : null;

        if (! $this->sessionId) {
            Notification::make()->title('Missing session')->danger()->send();
            return;
        }

        if (! $this->sessionMeta()) {
            Notification::make()->title('Invalid or non-UK session')->danger()->send();
            return;
        }

        if (! $this->authorizedForSession()) {
            abort(403);
        }

        $this->loadExistingStatuses();
    }

    protected function authorizedForSession(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return true;
        }

        if (in_array($role, User::TEACHING_ROLES, true)) {
            return TeacherRoster::sessions($user)
                ->cloneWithout(['orders', 'limit', 'offset'])
                ->where('class_sessions.id', $this->sessionId)
                ->exists();
        }

        return false;
    }

    public function sessionMeta(): ?object
    {
        if (! $this->sessionId) {
            return null;
        }

        $row = DB::table('class_sessions')->where('id', $this->sessionId)->first();

        if (! $row) {
            return null;
        }

        if (strtolower((string) ($row->location ?? '')) !== 'uk') {
            return null;
        }

        return $row;
    }

    public function roster(): array
    {
        $meta = $this->sessionMeta();
        if (! $meta) {
            return [];
        }

        $date = Carbon::parse($meta->session_date)->toDateString();
        $start = $meta->start_time;
        $end = $meta->end_time;
        $loc = $meta->location;
        $cal = $meta->calendar_name;

        $sessions = DB::table('class_sessions')
            ->whereDate('session_date', $date)
            ->where('start_time', $start)
            ->where('end_time', $end)
            ->where('location', $loc)
            ->where('calendar_name', $cal)
            ->where(function ($w) {
                $w->where('canceled', false)
                    ->orWhereNull('canceled');
            })
            ->get();

        if ($sessions->isEmpty()) {
            return [];
        }

        $studentIds = $sessions->pluck('student_id')->filter()->unique()->values()->all();
        $emails = [];

        foreach ($sessions as $session) {
            foreach ([$session->student_email ?? null, $session->client_email ?? null] as $candidate) {
                if (is_string($candidate) && str_contains($candidate, '@')) {
                    $emails[] = strtolower(trim($candidate));
                }
            }

            $payload = is_string($session->acuity_data)
                ? json_decode($session->acuity_data, true)
                : (array) ($session->acuity_data ?? []);

            foreach (['client.email', 'email', 'clientEmail'] as $path) {
                $email = data_get($payload, $path);
                if (is_string($email) && str_contains($email, '@')) {
                    $emails[] = strtolower(trim($email));
                }
            }
        }

        $emails = array_values(array_unique($emails));
        $hasIds = ! empty($studentIds);
        $hasEmails = ! empty($emails);

        if (! $hasIds && ! $hasEmails) {
            return [];
        }

        $students = DB::table('students')
            ->leftJoin('managers', 'students.manager_id', '=', 'managers.id')
            ->select(
                'students.id',
                'students.first_name',
                'students.last_name',
                'students.email',
                'students.manager_id',
                'students.attendance_rate',
                'students.email_norm',
                'managers.email as manager_email'
            )
            ->whereNull('students.deleted_at')
            ->where(function ($query) use ($hasIds, $studentIds, $hasEmails, $emails) {
                if ($hasIds) {
                    $query->whereIn('students.id', $studentIds);
                }

                if ($hasEmails) {
                    if ($hasIds) {
                        $query->orWhereIn('students.email_norm', $emails);
                    } else {
                        $query->whereIn('students.email_norm', $emails);
                    }
                }
            })
            ->get();

        if ($students->isEmpty()) {
            return [];
        }

        return collect($students)
            ->map(function ($student) {
                $student->name = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')) ?: ($student->email ?? '');
                return (array) $student;
            })
            ->sortBy(fn ($row) => mb_strtolower($row['name'] ?? '', 'UTF-8'))
            ->values()
            ->all();
    }

    protected function loadExistingStatuses(): void
    {
        if (! $this->sessionId) {
            return;
        }

        $records = DB::table('attendance_records')
            ->select('student_id', 'status')
            ->where('class_session_id', $this->sessionId)
            ->whereIn('status', ['present', 'late', 'absent'])
            ->get();

        foreach ($records as $record) {
            $sid = (int) $record->student_id;
            if ($sid > 0 && in_array($record->status, ['present', 'late', 'absent'])) {
                $this->statuses[$sid] = $record->status;
            }
        }

        $this->recountTotals();
    }

    public function updatedStatuses(): void
    {
        $this->recountTotals();
    }

    protected function recountTotals(): void
    {
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0];

        foreach ($this->statuses as $state) {
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        $this->presentCount = $counts['present'];
        $this->lateCount = $counts['late'];
        $this->absentCount = $counts['absent'];
    }

    public function markAll(string $status): void
    {
        if (! in_array($status, ['present', 'late', 'absent'])) {
            return;
        }

        foreach ($this->roster() as $row) {
            $this->statuses[(int) $row['id']] = $status;
        }

        $this->recountTotals();
    }

    public function setStatus(int $studentId, string $status): void
    {
        if (! in_array($status, ['present', 'late', 'absent'])) {
            return;
        }

        $this->statuses[$studentId] = $status;
        $this->recountTotals();
    }

    public function save(): void
    {
        $meta = $this->sessionMeta();
        if (! $meta) {
            Notification::make()->title('Invalid or non-UK session')->danger()->send();
            return;
        }

        $roster = $this->roster();
        if (empty($roster)) {
            Notification::make()->title('No roster to save')->warning()->send();
            return;
        }

        foreach ($roster as $row) {
            $sid = (int) $row['id'];
            if (! isset($this->statuses[$sid]) || ! in_array($this->statuses[$sid], ['present', 'late', 'absent'])) {
                Notification::make()->title('Please select a status for all students')->warning()->send();
                return;
            }
        }

        $now      = now();
        $userRole = strtolower((string) (auth()->user()?->role ?? ''));

        // Snapshot existing statuses to distinguish marked vs updated in the log
        $existingStatuses = DB::table('attendance_records')
            ->where('class_session_id', $this->sessionId)
            ->pluck('status', 'student_id');

        DB::beginTransaction();
        try {
            foreach ($roster as $row) {
                $sid = (int) $row['id'];
                AttendanceRecord::recordStatus(
                    $this->sessionId,
                    $sid,
                    $this->statuses[$sid],
                    ['marked_at' => $now, 'marked_by' => auth()->id()]
                );
            }
            DB::commit();
            AttendanceAction::create([
                'class_session_id' => $this->sessionId,
                'user_id'          => auth()->id(),
                'action'           => 'save',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()->title('Failed to save attendance')->danger()->send();
            return;
        }

        $this->writeAttendanceLogs($roster, $existingStatuses, $userRole, $now);

        foreach ($roster as $row) {
            $sid = (int) $row['id'];
            $student = Student::find($sid);
            if ($student) {
                $student->recomputeAttendanceRate();
            }

            $this->dispatch('attendance-updated', studentId: $sid);
        }

        $this->recountTotals();

        Notification::make()->title('Attendance saved')->success()->send();
    }

    public function submit(): void
    {
        $meta = $this->sessionMeta();
        if (! $meta) {
            Notification::make()->title('Invalid or non-UK session')->danger()->send();
            return;
        }

        // Guard: prevent sending emails more than once per session
        if (! empty($meta->email_sent_at)) {
            $sentOn = Carbon::parse($meta->email_sent_at)->format('d-m-Y H:i');
            Notification::make()
                ->title('Email already sent')
                ->body("Manager emails were already sent for this session on {$sentOn}.")
                ->warning()
                ->send();
            return;
        }

        $roster = $this->roster();
        if (empty($roster)) {
            Notification::make()->title('No roster to submit')->warning()->send();
            return;
        }

        foreach ($roster as $row) {
            $sid = (int) $row['id'];
            if (! isset($this->statuses[$sid]) || ! in_array($this->statuses[$sid], ['present', 'late', 'absent'])) {
                Notification::make()->title('Please select a status for all students')->warning()->send();
                return;
            }
        }

        $now        = now();
        $sentCount  = 0;
        $userRole   = strtolower((string) (auth()->user()?->role ?? ''));

        // Snapshot existing statuses to distinguish marked vs updated in the log
        $existingStatuses = DB::table('attendance_records')
            ->where('class_session_id', $this->sessionId)
            ->pluck('status', 'student_id');

        DB::beginTransaction();
        try {
            foreach ($roster as $row) {
                $sid   = (int) $row['id'];
                $state = $this->statuses[$sid];
                AttendanceRecord::recordStatus(
                    $this->sessionId,
                    $sid,
                    $state,
                    ['marked_at' => $now, 'marked_by' => auth()->id()]
                );
            }

            DB::commit();
            AttendanceAction::create([
                'class_session_id' => $this->sessionId,
                'user_id'          => auth()->id(),
                'action'           => 'submit',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()->title('Failed to save attendance')->danger()->send();
            return;
        }

        // Log status marks/updates for all students
        $this->writeAttendanceLogs($roster, $existingStatuses, $userRole, $now);

        // Send emails for absences
        foreach ($roster as $row) {
            $sid   = (int) $row['id'];
            $state = $this->statuses[$sid];

            if ($state === 'absent') {
                $managerEmail = null;

                if (! empty($row['manager_id'])) {
                    $manager = DB::table('managers')->where('id', $row['manager_id'])->first();
                    if ($manager && filter_var($manager->email, FILTER_VALIDATE_EMAIL)) {
                        $managerEmail = $manager->email;
                    }
                }

                if (! $managerEmail) {
                    $fallback = $row['manager_email'] ?? null;
                    if (is_string($fallback) && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
                        $managerEmail = $fallback;
                    }
                }

                if ($managerEmail) {
                    try {
                        \Mail::mailer('admin')->to($managerEmail)->queue(new \App\Mail\ManagerAbsentNotice($row, $meta));
                        AttendanceRecord::recordStatus(
                            $this->sessionId,
                            $sid,
                            $state,
                            ['marked_at' => $now, 'marked_by' => auth()->id(), 'sent_at' => now()]
                        );
                        $this->writeAttendanceLog($sid, $userRole, 'email_sent', $state, null, $now);
                        $sentCount++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            if (Student::where('id', $sid)->exists()) {
                $this->dispatch('attendance-updated', studentId: $sid);
            }
        }

        // Stamp email_sent_at on the session so Send cannot be triggered again
        if ($sentCount > 0) {
            DB::table('class_sessions')
                ->where('id', $this->sessionId)
                ->update(['email_sent_at' => $now]);
        }

        $message = "Attendance submitted; {$sentCount} manager email" . ($sentCount !== 1 ? 's' : '') . ' sent';

        Notification::make()->title($message)->success()->send();
        $this->recountTotals();
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), $this->auditLogViewData());
    }
}
