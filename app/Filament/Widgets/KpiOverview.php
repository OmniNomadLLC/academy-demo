<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use App\Models\Student;
use App\Services\ActiveStudentsCounter;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KpiOverview extends BaseWidget
{
    // Ensure this renders first on the dashboard
    protected static ?int $sort = -999;

    protected function getStats(): array
    {
        $region = session('preferred_region') ?? request()->user()?->preferred_region ?? null; // session wins

        $activeStudents = app(ActiveStudentsCounter::class)->countForRegion($region);

        $classesToday = Cache::remember('dash_kpi_classes_today_v4_'.($region ?: 'all'), 120, function () use ($region) {
            $query = ClassSession::query()
                ->whereDate('session_date', today())
                ->where(function ($w) {
                    $w->where('canceled', false)->orWhereNull('canceled');
                })
                ->where(function ($w) {
                    $w->where('status', '!=', 'cancelled')->orWhereNull('status');
                })
                ->excludingAssessments();

            $this->applyRegionScope($query, $region, 'class');

            // Multi-argument COUNT(DISTINCT a, b) is MySQL-only; concatenate
            // for SQLite so both drivers count distinct (class, time) pairs.
            $pair = $query->getConnection()->getDriverName() === 'sqlite'
                ? "school_class_id || '-' || start_time"
                : 'school_class_id, start_time';

            return (int) $query->toBase()->selectRaw("COUNT(DISTINCT {$pair}) as cnt")->value('cnt');
        });

        $studentsToday = Cache::remember('dash_kpi_students_today_v1_'.($region ?: 'all'), 120, function () use ($region) {
            $query = ClassSession::query()
                ->whereDate('session_date', today())
                ->where(function ($w) {
                    $w->where('canceled', false)->orWhereNull('canceled');
                })
                ->where(function ($w) {
                    $w->where('status', '!=', 'cancelled')->orWhereNull('status');
                })
                ->whereNotNull('student_id');

            $this->applyRegionScope($query, $region, 'class');

            return (int) $query->distinct('student_id')->count('student_id');
        });

        $weekAttendance = Cache::remember('dash_kpi_week_attendance_rate_v2_'.($region ?: 'all'), 120, function () use ($region) {
            $weekStart = today()->startOfWeek()->toDateString();
            $todayStr = today()->toDateString();

            $query = DB::table('attendance_records')
                ->join('class_sessions', 'attendance_records.class_session_id', '=', 'class_sessions.id')
                ->whereBetween('class_sessions.session_date', [$weekStart, $todayStr])
                ->where(function ($w) {
                    $w->where('class_sessions.canceled', false)->orWhereNull('class_sessions.canceled');
                })
                ->where(function ($w) {
                    $w->where('class_sessions.status', '!=', 'cancelled')->orWhereNull('class_sessions.status');
                })
                ->whereIn('attendance_records.status', ['present', 'absent', 'late']);

            ClassSession::applyExcludeAssessmentsToQuery($query, 'class_sessions');

            $this->applyRegionScope($query, $region, 'class');

            $total = (int) $query->count();
            if ($total === 0) {
                return ['rate' => null, 'total' => 0, 'present' => 0];
            }

            $present = (int) (clone $query)->where('attendance_records.status', 'present')->count();

            return [
                'rate'    => (int) round(($present / $total) * 100),
                'total'   => $total,
                'present' => $present,
            ];
        });

        return [
            Stat::make('Active Students', number_format($activeStudents))
                ->description($region ? ('Region: '.$region) : 'All Regions')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('Classes Today', number_format($classesToday))
                ->description('Unique class slots')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),

            Stat::make('Students Today', number_format($studentsToday))
                ->description('Unique students with class')
                ->descriptionIcon('heroicon-m-user-group')
                ->color($studentsToday > 0 ? 'success' : 'gray'),

            Stat::make(
                'Attendance This Week',
                $weekAttendance['rate'] === null ? '—' : $weekAttendance['rate'].'%'
            )
                ->description(
                    $weekAttendance['total'] === 0
                        ? 'No attendance marked yet'
                        : $weekAttendance['present'].' / '.$weekAttendance['total'].' present'
                )
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($this->attendanceColor($weekAttendance['rate'])),
        ];
    }

    protected function attendanceColor(?int $rate): string
    {
        if ($rate === null) {
            return 'gray';
        }
        if ($rate >= 85) {
            return 'success';
        }
        if ($rate >= 70) {
            return 'warning';
        }
        return 'danger';
    }

    protected function applyRegionScope($query, ?string $region, string $type = 'student'): void
    {
        if (! $region) {
            return;
        }

        $regionLower = strtolower($region);
        $flagColumnMap = [
            'uk' => 'in_uk',
            'spain' => 'in_spain',
            'france' => 'in_france',
        ];

        if ($type === 'student') {
            if (isset($flagColumnMap[$regionLower])) {
                $flag = $flagColumnMap[$regionLower];
                $query->where(function ($q) use ($flag, $regionLower) {
                    $q->where($flag, true)
                        ->orWhereRaw('LOWER(location) = ?', [$regionLower]);
                });
            } else {
                $query->whereRaw('LOWER(location) = ?', [$regionLower]);
            }

            return;
        }

        // Class sessions share the `location` column directly.
        $query->whereRaw('LOWER(location) = ?', [$regionLower]);
    }
}
