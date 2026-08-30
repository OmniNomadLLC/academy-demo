<?php

namespace App\Services\Reporting;

use App\Support\Concerns\InterpretsAcuityFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UkReportIntelligenceService
{
    use InterpretsAcuityFields;

    public const STUDENT_RISK_LIMIT = 5;
    public const CLASS_RISK_LIMIT = 5;

    protected string $timezone;

    public function __construct(?string $timezone = null)
    {
        $this->timezone = $timezone ?? config('app.timezone', 'UTC');
    }

    public function buildInsights(): array
    {
        return [
            'attendance_trend' => $this->attendanceTrendInsight(),
            'student_risks' => $this->studentRiskInsights(),
            'class_risks' => $this->classRiskInsights(),
        ];
    }

    protected function attendanceTrendInsight(): ?array
    {
        $now = Carbon::now($this->timezone)->endOfDay();
        $currentFrom = $now->copy()->subDays(6)->startOfDay();
        $previousEnd = $currentFrom->copy()->subDay()->endOfDay();
        $previousFrom = $previousEnd->copy()->subDays(6)->startOfDay();

        $currentAverage = $this->attendanceAverageBetween($currentFrom, $now);
        $previousAverage = $this->attendanceAverageBetween($previousFrom, $previousEnd);

        if ($currentAverage === null && $previousAverage === null) {
            return null;
        }

        $delta = null;
        $alert = null;

        if ($currentAverage !== null && $previousAverage !== null) {
            $delta = round($currentAverage - $previousAverage, 1);

            if ($delta <= -10) {
                $alert = [
                    'type' => 'attendance_drop',
                    'value' => (int) round($delta),
                    'severity' => 'warning',
                ];
            }
        }

        return [
            'current_average' => $currentAverage,
            'previous_average' => $previousAverage,
            'delta' => $delta,
            'alert' => $alert,
            'window' => [
                'current' => [$currentFrom->toDateString(), $now->toDateString()],
                'previous' => [$previousFrom->toDateString(), $previousEnd->toDateString()],
            ],
        ];
    }

    protected function attendanceAverageBetween(Carbon $from, Carbon $to): ?float
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->selectRaw("SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) as attended")
            ->selectRaw("SUM(CASE WHEN ar.status IN ('present','late','absent') THEN 1 ELSE 0 END) as total_marked")
            ->whereIn('ar.status', ['present', 'late', 'absent'])
            ->whereBetween('cs.session_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) {
                $query->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk']);
            });

        \App\Models\ClassSession::applyExcludeAssessmentsToQuery($query, 'cs');

        $result = $query->first();

        logger('ATTENDANCE CHECK', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $result,
            'average' => $result && $result->total_marked ? round(($result->attended / max(1, $result->total_marked)) * 100, 1) : null,
        ]);

        if (! $result || ! $result->total_marked) {
            return null;
        }

        return round(($result->attended / max(1, $result->total_marked)) * 100, 1);
    }

    protected function studentRiskInsights(): array
    {
        $now = Carbon::now($this->timezone);
        $absencesCutoff = $now->copy()->subDays(6)->startOfDay();

        $baseQuery = $this->studentRiskBaseQuery($now, $absencesCutoff);

        $wrappedForCount = DB::query()->fromSub($this->studentRiskBaseQuery($now, $absencesCutoff), 'risked');
        $total = (int) $wrappedForCount
            ->where('risk_score', '>', 0)
            ->count();

        $students = DB::query()
            ->fromSub($baseQuery, 'risked')
            ->where('risk_score', '>', 0)
            ->orderByDesc('risk_score')
            ->orderBy('attendance_rate')
            ->limit(self::STUDENT_RISK_LIMIT)
            ->get()
            ->map(function ($row) use ($now) {
                $reasons = [];
                if ($row->attendance_rate !== null && $row->attendance_rate < 60) {
                    $reasons[] = 'Attendance below 60%';
                }
                if ($row->next_appointment_date === null || Carbon::parse($row->next_appointment_date) <= $now) {
                    $reasons[] = 'No upcoming class scheduled';
                }
                if ((int) $row->absences_last7 >= 2) {
                    $reasons[] = '2+ absences in last 7 days';
                }

                return [
                    'id' => (int) $row->id,
                    'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: ($row->email ?? 'Student #' . $row->id),
                    'attendance_rate' => $row->attendance_rate !== null ? round((float) $row->attendance_rate, 1) : null,
                    'next_class_at' => $row->next_appointment_date,
                    'absences_last7' => (int) $row->absences_last7,
                    'risk_score' => (int) $row->risk_score,
                    'reasons' => $reasons,
                ];
            })
            ->all();

        $payload = [
            'count' => $total,
            'students' => $students,
        ];

        if ($total > 0) {
            logger('TRIGGER: HIGH RISK', $payload);
        }

        return $payload;
    }

    protected function studentRiskBaseQuery(Carbon $now, Carbon $absencesCutoff): Builder
    {
        $absenceSubquery = DB::table('attendance_records')
            ->select('student_id', DB::raw('COUNT(*) as absences_last7'))
            ->where('status', 'absent')
            ->where('marked_at', '>=', $absencesCutoff->toDateTimeString())
            ->groupBy('student_id');

        $riskExpression = '(
            CASE WHEN s.attendance_rate IS NOT NULL AND s.attendance_rate < ? THEN 1 ELSE 0 END +
            CASE WHEN s.next_appointment_date IS NULL OR s.next_appointment_date <= ? THEN 1 ELSE 0 END +
            CASE WHEN COALESCE(recent_absences.absences_last7, 0) >= ? THEN 1 ELSE 0 END
        )';

        return DB::table('students as s')
            ->leftJoinSub($absenceSubquery, 'recent_absences', function ($join) {
                $join->on('recent_absences.student_id', '=', 's.id');
            })
            ->select([
                's.id',
                's.first_name',
                's.last_name',
                's.email',
                's.attendance_rate',
                's.next_appointment_date',
            ])
            ->selectRaw('COALESCE(recent_absences.absences_last7, 0) as absences_last7')
            ->selectRaw($riskExpression . ' as risk_score', [85, $now->toDateTimeString(), 2])
            ->whereNull('s.deleted_at')
            ->where(function ($query) {
                $query->where('s.in_uk', true)
                    ->orWhereRaw('LOWER(COALESCE(s.location, "")) = ?', ['uk']);
            });
    }

    protected function classRiskInsights(): array
    {
        $now = Carbon::now($this->timezone);
        $windowEnd = $now->copy()->addHours(48);

        $calendarExpr = $this->qualifyAcuityExpression($this->calendarExpr());
        $typeIdExpr = $this->qualifyAcuityExpression($this->appointmentTypeIdExpr());
        $typeLabelExpr = $this->qualifyAcuityExpression($this->appointmentTypeLabelExpr());

        $rows = DB::table('class_sessions as cs')
            ->leftJoin('users as teacher', 'teacher.id', '=', 'cs.teacher_id')
            ->select([
                DB::raw('MIN(cs.id) as id'),
                'cs.session_date',
                'cs.start_time',
                DB::raw("COALESCE(NULLIF(MAX(cs.calendar_name), ''), 'Unknown calendar') as calendar_label"),
                DB::raw("COALESCE(MAX({$typeLabelExpr}), MAX({$typeIdExpr}), 'Class') as appointment_label"),
                DB::raw('COUNT(*) as booking_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN cs.student_id IS NOT NULL THEN cs.student_id END) as student_count'),
                'cs.teacher_id',
                DB::raw('MAX(teacher.name) as teacher_name'),
            ])
            ->where(function ($query) use ($now, $windowEnd) {
                $query->whereRaw(
                    "CONCAT(cs.session_date, ' ', COALESCE(cs.start_time, '00:00:00')) BETWEEN ? AND ?",
                    [$now->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
                );
            })
            ->where(function ($query) {
                $query->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
                    ->orWhereRaw('LOWER(COALESCE(cs.category_norm, "")) = ?', ['uk']);
            })
            ->where(function ($query) {
                $query->where('cs.canceled', false)
                    ->orWhereNull('cs.canceled')
                    ->orWhereIn('cs.status', ['scheduled', 'confirmed']);
            })
            ->groupBy(
                'cs.session_date',
                'cs.start_time',
                'cs.teacher_id',
                DB::raw($calendarExpr),
                DB::raw($typeIdExpr)
            )
            ->orderBy('cs.session_date')
            ->orderBy('cs.start_time')
            ->get();

        $risks = $rows
            ->map(function ($row) {
                $issues = [];
                $riskLevel = null;

                $studentCount = (int) $row->student_count;
        if ($studentCount <= 0) {
            $issues[] = 'No students assigned';
            $riskLevel = 'critical';
        } elseif ($studentCount <= 2) {
            $issues[] = 'Only 1 student';
            $riskLevel = $riskLevel ?? 'warning';
        }

                if ($row->teacher_id === null) {
                    $issues[] = 'Missing teacher';
                    $riskLevel = 'critical';
                }

                if (empty($issues)) {
                    return null;
                }

                return [
                    'class_id' => (int) $row->id,
                    'date' => $row->session_date,
                    'start_time' => $row->start_time,
                    'calendar' => $row->calendar_label,
                    'appointment_label' => $row->appointment_label,
                    'student_count' => $studentCount,
                    'risk_level' => $riskLevel ?? 'warning',
                    'issues' => $issues,
                ];
            })
            ->filter()
            ->values();

        return [
            'count' => $risks->count(),
            'classes' => $risks->take(self::CLASS_RISK_LIMIT)->values()->all(),
        ];
    }

    protected function qualifyAcuityExpression(string $expression): string
    {
        return str_replace(
            ['acuity_data', 'calendar_norm', 'category_norm'],
            ['cs.acuity_data', 'cs.calendar_norm', 'cs.category_norm'],
            $expression,
        );
    }
}
