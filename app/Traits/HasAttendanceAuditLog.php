<?php

namespace App\Traits;

use App\Models\AttendanceAction;
use App\Models\AttendanceLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared audit-logging logic for both admin and portal TakeAttendance pages.
 *
 * Requires the consuming class to have:
 *   - public ?int $sessionId
 *   - public array $statuses
 *   - public function sessionMeta(): ?object
 */
trait HasAttendanceAuditLog
{
    // -------------------------------------------------------------------------
    // Writing log entries
    // -------------------------------------------------------------------------

    /**
     * Write one attendance_log row per roster entry.
     * action = 'marked'  when no prior attendance_record existed for that student.
     * action = 'updated' when one already existed (any status).
     *
     * @param  array<int, array<string, mixed>>  $roster
     * @param  \Illuminate\Support\Collection<int,string>  $existingStatuses  student_id → status
     */
    protected function writeAttendanceLogs(
        array $roster,
        \Illuminate\Support\Collection $existingStatuses,
        string $userRole,
        Carbon $now
    ): void {
        foreach ($roster as $row) {
            $sid = (int) $row['id'];
            $newStatus = $this->statuses[$sid] ?? null;
            if (! $newStatus) {
                continue;
            }
            $oldStatus = $existingStatuses->get($sid);
            $action    = $oldStatus ? 'updated' : 'marked';
            $this->writeAttendanceLog($sid, $userRole, $action, $newStatus, $oldStatus ?: null, $now);
        }
    }

    protected function writeAttendanceLog(
        int $studentId,
        string $userRole,
        string $action,
        ?string $newStatus,
        ?string $oldStatus,
        Carbon $now
    ): void {
        try {
            AttendanceLog::create([
                'class_session_id' => $this->sessionId,
                'student_id'       => $studentId,
                'user_id'          => auth()->id(),
                'user_role'        => $userRole,
                'action'           => $action,
                'old_status'       => $oldStatus,
                'new_status'       => $newStatus,
                'performed_at'     => $now,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // -------------------------------------------------------------------------
    // Reading log entries for the view
    // -------------------------------------------------------------------------

    protected function canSeeAuditLog(): bool
    {
        $user = auth()->user();
        return $user && $user->hasRole('super_admin', 'admin', 'head_teacher');
    }

    protected function getAttendanceLogs(): \Illuminate\Support\Collection
    {
        if (! $this->sessionId) {
            return collect();
        }

        return AttendanceLog::query()
            ->with(['user', 'student'])
            ->where('class_session_id', $this->sessionId)
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->get();
    }

    protected function actionHistory(string $action): array
    {
        if (! $this->sessionId) {
            return [];
        }

        return AttendanceAction::query()
            ->leftJoin('users', 'users.id', '=', 'attendance_actions.user_id')
            ->where('attendance_actions.class_session_id', $this->sessionId)
            ->where('attendance_actions.action', $action)
            ->orderByDesc('attendance_actions.created_at')
            ->select([
                'attendance_actions.created_at as audit_timestamp',
                'users.name as user_name',
                'users.email as user_email',
            ])
            ->get()
            ->map(fn ($row) => $this->formatAuditRow($row))
            ->filter()
            ->values()
            ->all();
    }

    protected function formatAuditRow(?object $row): ?array
    {
        if (! $row || empty($row->audit_timestamp)) {
            return null;
        }

        $timestamp = Carbon::parse($row->audit_timestamp)->timezone(config('app.timezone'));

        return [
            'user'      => $row->user_name ?: ($row->user_email ?: 'Unknown user'),
            'formatted' => $timestamp->format('d-m-Y H:i'),
        ];
    }

    // -------------------------------------------------------------------------
    // View data bundle — call from getViewData() in both page classes
    // -------------------------------------------------------------------------

    protected function auditLogViewData(): array
    {
        $canSeeAuditLog = $this->canSeeAuditLog();
        $saveHistory    = $this->actionHistory('save');
        $submitHistory  = $this->actionHistory('submit');
        $meta           = $this->sessionMeta();

        return [
            'latestSaveAudit'   => $saveHistory[0] ?? null,
            'latestSubmitAudit' => $submitHistory[0] ?? null,
            'saveAuditHistory'  => $saveHistory,
            'submitAuditHistory' => $submitHistory,
            'emailSentAt'       => $meta && $meta->email_sent_at
                                      ? Carbon::parse($meta->email_sent_at)
                                      : null,
            'canSeeAuditLog'    => $canSeeAuditLog,
            'attendanceLogs'    => $canSeeAuditLog ? $this->getAttendanceLogs() : collect(),
        ];
    }
}
