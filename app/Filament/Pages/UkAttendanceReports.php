<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Mail\ManagerAbsentNotice;
use App\Models\AttendanceLog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\WithPagination;

class UkAttendanceReports extends Page
{
    use RequiresRegionAccess;
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Attendance';
    protected static ?string $title = 'Attendance Reports (UK)';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.uk-attendance-reports';
    public static string $requiredRegion = 'UK';

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public ?string $calendar = null;
    public bool $onlyAbsences = false;
    public ?string $attendanceBand = null;
    public ?string $emailStatus = null;
    public int $perPage = 10;

    protected string $paginationTheme = 'tailwind';

    protected $queryString = [
        'page' => ['except' => 1],
    ];

    public static function canAccess(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        $user = Auth::user();
        if (! $user || ! $user->hasDomainAccess('reporting')) {
            return false;
        }

        return parent::canAccess();
    }

    public function mount(): void
    {
        $this->fromDate = request()->query('from');
        $this->toDate = request()->query('to');
        $this->calendar = request()->query('cal');
        $this->onlyAbsences = (bool) request()->boolean('onlyAbsences');
        $this->attendanceBand = request()->query('band');
        $this->emailStatus = request()->query('mail');
    }

    public function reports()
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->leftJoin('users as u', 'u.id', '=', 'ar.marked_by')
            ->whereRaw('LOWER(cs.location) = ?', ['uk'])
            ->whereIn('ar.status', ['present','late','absent'])
            ->select(
                'ar.class_session_id as sid',
                'cs.session_date','cs.start_time','cs.end_time','cs.calendar_name','cs.location',
                DB::raw('MAX(cs.acuity_data) as raw_acuity_data'),
                DB::raw("SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) as c_present"),
                DB::raw("SUM(CASE WHEN ar.status='late' THEN 1 ELSE 0 END) as c_late"),
                DB::raw("SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) as c_absent"),
                DB::raw("SUM(CASE WHEN ar.status='absent' AND ar.sent_at IS NULL THEN 1 ELSE 0 END) as c_absent_unsent"),
                DB::raw("SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) as c_attended"),
                DB::raw("SUM(CASE WHEN ar.status IN ('present','late','absent') THEN 1 ELSE 0 END) as c_total_marked"),
                DB::raw("ROUND(CASE WHEN SUM(CASE WHEN ar.status IN ('present','late','absent') THEN 1 ELSE 0 END) = 0 THEN NULL ELSE ((SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) * 100.0) / SUM(CASE WHEN ar.status IN ('present','late','absent') THEN 1 ELSE 0 END)) END, 2) as attendance_rate"),
                DB::raw('MAX(ar.marked_at) as submitted_at'),
                DB::raw('COUNT(ar.sent_at) as c_sent'),
                DB::raw("MAX(CASE WHEN "
                    ."LOWER(COALESCE(cs.calendar_name, '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cs.acuity_data, '$.type')), '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cs.acuity_data, '$.appointmentType.name')), '')) LIKE '%assessment%' "
                    ."THEN 1 ELSE 0 END) as is_assessment")
            )
            ->groupBy('ar.class_session_id','cs.session_date','cs.start_time','cs.end_time','cs.calendar_name','cs.location')
            ->when(is_string($this->fromDate) && is_string($this->toDate) && trim($this->fromDate) !== '' && trim($this->toDate) !== '', function($q) {
                $q->whereBetween('cs.session_date', [trim($this->fromDate), trim($this->toDate)]);
            })
            ->when(is_string($this->calendar) && trim($this->calendar) !== '', function($q) {
                $q->where('cs.calendar_name', trim($this->calendar));
            })
            ->when($this->onlyAbsences, function($q) {
                $q->havingRaw("SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) > 0");
            })
            ->when($this->attendanceBand === 'lt75', function($q) {
                $q->having('attendance_rate', '<', 75);
            })
            ->when($this->attendanceBand === '75to89', function($q) {
                $q->havingRaw('attendance_rate >= 75 AND attendance_rate < 90');
            })
            ->when($this->attendanceBand === 'gte90', function($q) {
                $q->having('attendance_rate', '>=', 90);
            })
            ->when($this->emailStatus === 'unsent', function($q) {
                $q->having('c_absent_unsent', '>', 0);
            })
            ->when($this->emailStatus === 'sent', function($q) {
                $q->having('c_sent', '>', 0);
            })
            ->when($this->emailStatus === 'none', function($q) {
                $q->having('c_sent', '=', 0);
            })
            ->orderByDesc('submitted_at');

        return $query
            ->paginate($this->perPage)
            ->through(function ($row) {
                $payload = json_decode($row->raw_acuity_data ?? '', true);
                $row->appointment_type = $payload['type'] ?? $payload['appointmentType'] ?? null;
                unset($row->raw_acuity_data);
                return $row;
            });
    }

    public function availableUkCalendars(): array
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereRaw('LOWER(cs.location) = ?', ['uk'])
            ->whereIn('ar.status', ['present', 'late', 'absent']);

        if (is_string($this->fromDate) && is_string($this->toDate) && trim($this->fromDate) !== '' && trim($this->toDate) !== '') {
            $query->whereBetween('cs.session_date', [trim($this->fromDate), trim($this->toDate)]);
        }

        if ($this->onlyAbsences) {
            $query->where('ar.status', 'absent');
        }

        return $query
            ->whereNotNull('cs.calendar_name')
            ->distinct()
            ->orderBy('cs.calendar_name')
            ->pluck('cs.calendar_name', 'cs.calendar_name')
            ->toArray();
    }

    public function resetFilters(): void
    {
        $this->fromDate = null;
        $this->toDate = null;
        $this->calendar = null;
        $this->attendanceBand = null;
        $this->emailStatus = null;
        $this->onlyAbsences = false;
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function applyFilters(): void
    {
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function sendAbsentEmails(int $sessionId): void
    {
        $session = DB::table('class_sessions')->where('id', $sessionId)->first();
        if (! $session || strtolower((string) ($session->location ?? '')) !== 'uk') {
            Notification::make()->title('Invalid session')->danger()->send();
            return;
        }

        $absentees = DB::table('attendance_records as ar')
            ->join('students as s', 's.id', '=', 'ar.student_id')
            ->leftJoin('managers as m', 'm.id', '=', 's.manager_id')
            ->select(
                'ar.id',
                'ar.student_id',
                's.first_name',
                's.last_name',
                's.email',
                's.manager_id',
                'm.email as manager_email'
            )
            ->where('ar.class_session_id', $sessionId)
            ->where('ar.status', 'absent')
            ->whereNull('ar.sent_at')
            ->get();

        if ($absentees->isEmpty()) {
            Notification::make()->title('No pending absence emails')->info()->send();
            return;
        }

        $now = now();
        $sent = 0;

        foreach ($absentees as $row) {
            $managerEmail = $row->manager_email;
            if (! is_string($managerEmail) || ! filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $studentName = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: ($row->email ?? 'Student');
            $payload = [
                'id' => $row->student_id,
                'name' => $studentName,
                'email' => $row->email,
                'manager_id' => $row->manager_id,
                'manager_email' => $managerEmail,
            ];

            try {
                Mail::mailer('admin')->to($managerEmail)->queue(new ManagerAbsentNotice($payload, $session));
                DB::table('attendance_records')->where('id', $row->id)->update(['sent_at' => $now]);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($sent === 0) {
            Notification::make()->title('No absence emails queued')->warning()->send();
            return;
        }

        Notification::make()
            ->title("Absent emails queued ({$sent})")
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function deleteAttendanceRecords(int $sessionId): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasRole('super_admin')) {
            Notification::make()->title('Unauthorized')->danger()->send();
            return;
        }

        $session = DB::table('class_sessions')->where('id', $sessionId)->first();
        if (! $session || strtolower((string) ($session->location ?? '')) !== 'uk') {
            Notification::make()->title('Invalid session')->danger()->send();
            return;
        }

        $records = DB::table('attendance_records')
            ->where('class_session_id', $sessionId)
            ->select('student_id', 'status')
            ->get();

        if ($records->isEmpty()) {
            Notification::make()->title('No attendance records to delete')->info()->send();
            return;
        }

        $now = now();
        $userRole = strtolower((string) ($user->role ?? ''));

        DB::transaction(function () use ($sessionId, $records, $now, $userRole) {
            foreach ($records as $record) {
                try {
                    AttendanceLog::create([
                        'class_session_id' => $sessionId,
                        'student_id'       => $record->student_id,
                        'user_id'          => Auth::id(),
                        'user_role'        => $userRole,
                        'action'           => 'deleted',
                        'old_status'       => $record->status,
                        'new_status'       => null,
                        'performed_at'     => $now,
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            DB::table('attendance_records')
                ->where('class_session_id', $sessionId)
                ->delete();

            DB::table('class_sessions')
                ->where('id', $sessionId)
                ->update(['email_sent_at' => null]);
        });

        Notification::make()
            ->title('Attendance records deleted')
            ->body("Deleted {$records->count()} records for session #{$sessionId}.")
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }
}
