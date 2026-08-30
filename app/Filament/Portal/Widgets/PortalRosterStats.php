<?php

namespace App\Filament\Portal\Widgets;

use App\Models\ClassSession;
use App\Models\Manager;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherRoster;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PortalRosterStats extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $role = Str::of($user->role ?? '')->lower()->value();

        if ($role === 'manager') {
            return $this->forManager();
        }

        if (in_array($role, User::TEACHING_ROLES, true)) {
            return $this->forTeacher();
        }

        return $this->forUnscopedRole();
    }

    /**
     * @return array<int, Stat>
     */
    private function forManager(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $manager = Manager::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower((string) $user->email)])
            ->first();

        if (! $manager) {
            return [
                Stat::make('No manager record linked', '—')
                    ->description('Ask an admin to link your portal login to a manager profile.')
                    ->descriptionIcon('heroicon-m-information-circle')
                    ->color('warning'),
            ];
        }

        $scope = $user->managerScope();
        $allowedRegions = $user->allowedRegions();

        $studentsBase = Student::query()
            ->when($scope !== 'region', fn ($query) => $query->where('manager_id', $manager->id))
            ->when($user->restrictsByRegion(), fn ($query) => $query->whereIn('location', $allowedRegions));

        $totalStudents = (clone $studentsBase)->count();
        $lowAttendance = (clone $studentsBase)
            ->whereNotNull('attendance_rate')
            ->where('attendance_rate', '<', 75)
            ->count();

        $today = Carbon::today();
        $studentIdSubquery = (clone $studentsBase)->select('id');
        $activeSessions = ClassSession::query()
            ->where(function ($query) {
                $query->whereNull('canceled')->orWhere('canceled', false);
            })
            ->whereIn('student_id', $studentIdSubquery)
            ->when($user->restrictsByRegion(), fn ($query) => $query->whereIn('location', $allowedRegions));

        $todayClasses = $this->countDistinctClasses(
            (clone $activeSessions)->whereDate('session_date', $today)
        );

        $weekClasses = $this->countDistinctClasses(
            (clone $activeSessions)->whereBetween('session_date', [$today, $today->copy()->addDays(7)])
        );

        return [
            Stat::make('Assigned students', number_format($totalStudents))
                ->description('Students currently linked to your roster')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            Stat::make('Low attendance alerts', number_format($lowAttendance))
                ->description('Below 75% attendance rate')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowAttendance > 0 ? 'danger' : 'success'),
            Stat::make('Upcoming classes (7 days)', number_format($weekClasses))
                ->description($todayClasses > 0 ? ($todayClasses.' happening today') : 'Keep an eye on the week ahead')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($weekClasses > 0 ? 'info' : 'gray'),
        ];
    }

    /**
     * @return array<int, Stat>
     */
    private function forTeacher(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $today = Carbon::today();
        $sessionsQuery = TeacherRoster::sessions($user);

        $todayClasses = $this->countDistinctClasses(
            (clone $sessionsQuery)->whereDate('session_date', $today)
        );

        $upcomingWeek = $this->countDistinctClasses(
            (clone $sessionsQuery)->whereBetween('session_date', [$today, $today->copy()->addDays(7)])
        );

        $students = TeacherRoster::students($user);
        $totalStudents = $students->count();
        $lowAttendance = $students->filter(function (Student $student) {
            $rate = (float) ($student->attendance_rate ?? 0);
            return $rate > 0 && $rate < 75;
        })->count();

        $stats = [
            Stat::make('Today’s classes', number_format($todayClasses))
                ->icon('heroicon-m-clock')
                ->color($todayClasses > 0 ? 'success' : 'gray')
                ->extraAttributes([
                    'class' => 'portal-stat portal-stat-classes',
                    'style' => 'background: linear-gradient(135deg, #ecfccb, #c7f9cc); color:#0f172a; border:none;',
                ]),
            Stat::make('Students taught recently', number_format($totalStudents))
                ->icon('heroicon-m-academic-cap')
                ->color('info')
                ->extraAttributes([
                    'class' => 'portal-stat portal-stat-students',
                    'style' => 'background: linear-gradient(135deg, #e0f2fe, #bae6fd); color:#0f172a; border:none;',
                ]),
            Stat::make('Upcoming week load', number_format($upcomingWeek))
                ->icon('heroicon-m-calendar-days')
                ->color($upcomingWeek > 0 ? 'warning' : 'gray')
                ->extraAttributes([
                    'class' => 'portal-stat portal-stat-week',
                    'style' => 'background: linear-gradient(135deg, #fef3c7, #fde68a); color:#0f172a; border:none;',
                ]),
        ];

        return $stats;
    }

    /**
     * @return array<int, Stat>
     */
    private function forUnscopedRole(): array
    {
        return [
            Stat::make('Portal account ready', 'Assign a teacher or manager role to see personalised stats')
                ->description('Access controls drive the widgets displayed here')
                ->descriptionIcon('heroicon-m-information-circle')
                ->color('gray'),
        ];
    }

    private function countDistinctClasses($query): int
    {
        return (int) (($query
            ->cloneWithout(['columns', 'orders'])
            ->selectRaw("COUNT(DISTINCT CONCAT_WS('|', session_date, start_time, calendar_name)) as aggregate")
            ->value('aggregate')) ?? 0);
    }
}
