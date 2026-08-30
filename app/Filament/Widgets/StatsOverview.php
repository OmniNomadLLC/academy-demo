<?php

namespace App\Filament\Widgets;

use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\ClassSession;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        // Distinct students: prefer acuity_client_id distinct when present, else email_norm distinct
        $row = DB::table('students')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as records, COUNT(DISTINCT acuity_client_id) as distinct_ids, COUNT(DISTINCT email_norm) as distinct_emails')
            ->first();
        $records = (int) ($row->records ?? 0);
        $distinctPrefIds = (int) ($row->distinct_ids ?? 0);
        $distinctEmails = (int) ($row->distinct_emails ?? 0);
        $distinctStudents = $distinctPrefIds > 0 ? $distinctPrefIds : $distinctEmails;
        $dupes = max(0, $records - $distinctStudents);
        // Active classes = classes with at least one session in the last 90 days or in the future
        // Cache for 5 minutes to keep the dashboard snappy
        $totalClasses = Cache::remember('dashboard_active_classes', 300, function () {
            return ClassSession::query()
                ->where(function ($q) {
                    $q->whereDate('session_date', '>=', now()->subDays(90))
                      ->orWhereDate('session_date', '>=', today());
                })
                ->distinct('school_class_id')
                ->count('school_class_id');
        });
        $totalTeachers = User::whereIn('role', User::TEACHING_ROLES)->where('is_active', true)->count();
        $totalSessions = ClassSession::where('status', 'completed')->count();

        return [
            Stat::make('Students (distinct)', number_format($distinctStudents))
                ->descriptionIcon('heroicon-m-users')
                ->color($dupes > 0 ? 'danger' : 'success'),

            Stat::make('Active Classes', $totalClasses)
                ->description('Running courses')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info'),

            Stat::make('Teachers', $totalTeachers)
                ->description('Active teachers')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning'),

            Stat::make('Total Sessions', $totalSessions)
                ->description('Lessons completed')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
        ];
    }
}
