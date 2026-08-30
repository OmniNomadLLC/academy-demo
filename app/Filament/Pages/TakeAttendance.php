<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Traits\HasAttendanceAuditLog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use App\Models\Student;
use App\Models\AttendanceLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceAction;

class TakeAttendance extends Page
{
    use RequiresRegionAccess;
    use HasAttendanceAuditLog;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Attendance';
    protected static ?string $title = 'Attendance';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.take-attendance';
    protected static ?string $slug = 'take-attendance';
    public static string $requiredRegion = 'UK';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public ?int $sessionId = null;
    public array $statuses = []; // [student_id => 'present'|'late'|'absent']
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
        if (!$this->sessionId) {
            Notification::make()->title('Missing session')->danger()->send();
            return;
        }
        if ($this->sessionMeta()) {
            $this->loadExistingStatuses();
        }
    }

    public function sessionMeta(): ?object
    {
        if (!$this->sessionId) return null;
        $row = DB::table('class_sessions')->where('id', $this->sessionId)->first();
        if (!$row) return null;
        if (strtolower((string)($row->location ?? '')) !== 'uk') return null;
        return $row;
    }

    public function roster(): array
    {
        $meta = $this->sessionMeta();
        if (!$meta) return [];
        $date = Carbon::parse($meta->session_date)->toDateString();
        $start = $meta->start_time; $end = $meta->end_time;
        $loc = $meta->location; $cal = $meta->calendar_name;

        $sessions = DB::table('class_sessions')->whereDate('session_date', $date)
            ->where('start_time', $start)
            ->where('end_time', $end)
            ->where('location', $loc)
            ->where('calendar_name', $cal)
            ->where(function ($w) {
                $w->where('canceled', false)->orWhereNull('canceled');
            })
            ->where(function ($w) {
                $w->whereNull('status')
                    ->orWhereNotIn('status', ['cancelled', 'canceled']);
            })
            ->get();

        if ($sessions->isEmpty()) {
            return [];
        }

        $studentIds = $sessions->pluck('student_id')->filter()->unique()->values()->all();
        $emails = [];

        foreach ($sessions as $s) {
            foreach ([$s->student_email ?? null, $s->client_email ?? null] as $candidate) {
                if (is_string($candidate) && str_contains($candidate, '@')) {
                    $emails[] = strtolower(trim($candidate));
                }
            }

            $payload = is_string($s->acuity_data) ? json_decode($s->acuity_data, true) : (array) ($s->acuity_data ?? []);
            foreach (['client.email', 'email', 'clientEmail'] as $path) {
                $em = data_get($payload, $path);
                if (is_string($em) && str_contains($em, '@')) {
                    $emails[] = strtolower(trim($em));
                }
            }
        }

        $emails = array_values(array_unique($emails));
        $hasIds = !empty($studentIds);
        $hasEmails = !empty($emails);

        if (!$hasIds && !$hasEmails) {
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
            ->where(function ($q) use ($hasIds, $studentIds, $hasEmails, $emails) {
                if ($hasIds) {
                    $q->whereIn('students.id', $studentIds);
                }
                if ($hasEmails) {
                    if ($hasIds) {
                        $q->orWhereIn('students.email_norm', $emails);
                    } else {
                        $q->whereIn('students.email_norm', $emails);
                    }
                }
            })
            ->get();

        if ($students->isEmpty()) {
            return [];
        }

        return collect($students)
            ->map(function ($s) {
                $s->name = trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: ($s->email ?? '');
                return (array) $s;
            })
            ->sortBy(fn ($row) => mb_strtolower($row['name'] ?? '', 'UTF-8'))
            ->values()
            ->all();
    }

    protected function loadExistingStatuses(): void
    {
        if (!$this->sessionId) {
            return;
        }
        $records = DB::table('attendance_records')
            ->select('student_id', 'status')
            ->where('class_session_id', $this->sessionId)
            ->whereIn('status', ['present','late','absent'])
            ->get();
        foreach ($records as $row) {
            $sid = (int) $row->student_id;
            if ($sid > 0 && in_array($row->status, ['present', 'late', 'absent'])) {
                $this->statuses[$sid] = $row->status;
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
        if (!in_array($status, ['present', 'late', 'absent'])) {
            return;
        }
        foreach ($this->roster() as $row) {
            $this->statuses[(int) $row['id']] = $status;
        }
        $this->recountTotals();
    }

    public function setStatus(int $studentId, string $status): void
    {
        if (!in_array($status, ['present', 'late', 'absent'])) {
            return;
        }
        $sid = (int) $studentId;
        if ($sid <= 0) {
            return;
        }
        $this->statuses[$sid] = $status;
        $this->recountTotals();
    }

    public function save(): void
    {
        $meta = $this->sessionMeta();
        if (!$meta) {
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
            if (!isset($this->statuses[$sid]) || !in_array($this->statuses[$sid], ['present','late','absent'])) {
                Notification::make()->title('Please select a status for all students')->warning()->send();
                return;
            }
        }
        $now = now();
        $user = auth()->user();
        $userRole = strtolower((string) ($user?->role ?? ''));

        // Snapshot existing statuses to determine marked vs updated
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
                    [
                        'marked_at' => $now,
                        'marked_by' => auth()->id(),
                    ]
                );
            }
            DB::commit();
            AttendanceAction::create([
                'class_session_id' => $this->sessionId,
                'user_id' => auth()->id(),
                'action' => 'save',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()->title('Failed to save attendance')->danger()->send();
            return;
        }

        // Write attendance log entries
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
        if (!$meta) { Notification::make()->title('Invalid or non-UK session')->danger()->send(); return; }

        // Guard: prevent sending emails more than once per session
        if (!empty($meta->email_sent_at)) {
            $sentOn = Carbon::parse($meta->email_sent_at)->format('d-m-Y H:i');
            Notification::make()
                ->title('Email already sent')
                ->body("Manager emails were already sent for this session on {$sentOn}.")
                ->warning()
                ->send();
            return;
        }

        $roster = $this->roster();
        if (empty($roster)) { Notification::make()->title('No roster to submit')->warning()->send(); return; }
        // Validate all selected
        foreach ($roster as $row) {
            $sid = (int) $row['id'];
            if (!isset($this->statuses[$sid]) || !in_array($this->statuses[$sid], ['present','late','absent'])) {
                Notification::make()->title('Please select a status for all students')->warning()->send();
                return;
            }
        }
        $now = now();
        $sentCount = 0;
        $user = auth()->user();
        $userRole = strtolower((string) ($user?->role ?? ''));

        // Snapshot existing statuses to determine marked vs updated
        $existingStatuses = DB::table('attendance_records')
            ->where('class_session_id', $this->sessionId)
            ->pluck('status', 'student_id');

        DB::beginTransaction();
        try {
            foreach ($roster as $row) {
                $sid = (int) $row['id'];
                $st = $this->statuses[$sid];
                AttendanceRecord::recordStatus(
                    $this->sessionId,
                    $sid,
                    $st,
                    [
                        'marked_at' => $now,
                        'marked_by' => auth()->id(),
                    ]
                );
            }
            DB::commit();
            AttendanceAction::create([
                'class_session_id' => $this->sessionId,
                'user_id' => auth()->id(),
                'action' => 'submit',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()->title('Failed to save attendance')->danger()->send();
            return;
        }

        // Write attendance log entries for the status records
        $this->writeAttendanceLogs($roster, $existingStatuses, $userRole, $now);

        // Send emails for absences
        foreach ($roster as $row) {
            $sid = (int) $row['id']; $st = $this->statuses[$sid];
            if ($st === 'absent') {
                $managerEmail = null;

                if (!empty($row['manager_id'])) {
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
                            $st,
                            [
                                'marked_at' => $now,
                                'marked_by' => auth()->id(),
                                'sent_at' => now(),
                            ]
                        );
                        // Log the email_sent action
                        $this->writeAttendanceLog($sid, $userRole, 'email_sent', $st, null, $now);
                        $sentCount++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
            // Recompute rate and alert
            try {
                if (Student::where('id', $sid)->exists()) {
                    $this->dispatch('attendance-updated', studentId: $sid);
                }
            } catch (\Throwable $e) {}
        }

        // Stamp email_sent_at on the session so Send cannot be triggered again
        if ($sentCount > 0) {
            DB::table('class_sessions')
                ->where('id', $this->sessionId)
                ->update(['email_sent_at' => $now]);
        }

        // Toast with link to reports for this session
        try {
            $url = route('filament.admin.pages.uk-attendance-reports', ['sid' => $this->sessionId]);
        } catch (\Throwable $e) { $url = null; }
        $msg = "Attendance submitted; {$sentCount} manager email" . ($sentCount !== 1 ? 's' : '') . ' sent';
        if ($url) { $msg .= " – View report »"; }
        Notification::make()->title($msg)->success()->send();
        $this->recountTotals();
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), $this->auditLogViewData());
    }
}
