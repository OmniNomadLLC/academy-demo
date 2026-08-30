<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\DB;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DataHealth extends BaseWidget
{
    protected static ?int $sort = -1; // just below AdvancedSyncStatus

    public static function canView(): bool
    {
        // Always allow; this widget is only injected on the Control Panel via getHeaderWidgets().
        return true;
    }

    protected function getStats(): array
    {
        $totalStudents = (int) DB::table('students')->whereNull('deleted_at')->count();
        $withLast = (int) DB::table('students')->whereNull('deleted_at')->whereNotNull('last_appointment_date')->count();
        $withNext = (int) DB::table('students')->whereNull('deleted_at')->whereNotNull('next_appointment_date')->count();
        $pctLast = $totalStudents > 0 ? round(($withLast / max(1,$totalStudents)) * 100) : 0;

        $today = now()->toDateString();
        $next45 = now()->addDays(45)->toDateString();
        $past30 = now()->subDays(30)->toDateString();

        $upcomingSessions = (int) DB::table('class_sessions')
            ->whereBetween('session_date', [$today, $next45])
            ->where(function ($w) {
                $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']);
            })
            ->count();

        $recentSessions = (int) DB::table('class_sessions')
            ->whereBetween('session_date', [$past30, $today])
            ->where(function ($w) {
                $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed','completed']);
            })
            ->count();

        $colorPct = $pctLast >= 80 ? 'success' : ($pctLast >= 50 ? 'warning' : 'danger');

        return [
            Stat::make('Students', number_format($totalStudents))
                ->description('Total records')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(route('filament.admin.resources.students.index')),

            Stat::make('With Last Appt', $pctLast.'%')
                ->description($withLast.' with last_appointment_date')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($colorPct),

            Stat::make('Upcoming (45d)', number_format($upcomingSessions))
                ->description('Future class sessions')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($upcomingSessions > 0 ? 'info' : 'warning'),

            Stat::make('Recent (30d)', number_format($recentSessions))
                ->description('Past class sessions')
                ->descriptionIcon('heroicon-m-clock')
                ->color($recentSessions > 0 ? 'info' : 'warning'),
        ];
    }
}
