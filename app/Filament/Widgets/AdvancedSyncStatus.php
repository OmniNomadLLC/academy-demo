<?php

namespace App\Filament\Widgets;

use App\Models\AcuitySyncLog;
use Illuminate\Support\Facades\DB;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdvancedSyncStatus extends BaseWidget
{
    protected static ?int $sort = -2; // Show at very top

    public static function canView(): bool
    {
        // Always allow; the Control Panel page explicitly includes this widget in getHeaderWidgets().
        return true;
    }

    protected function getStats(): array
    {
        $lastSync = AcuitySyncLog::latest()->first();
        $failedSyncs = AcuitySyncLog::where('status', 'failed')
            ->whereDate('created_at', today())
            ->count();
        
        $queuedJobs = DB::table('jobs')->where('queue', 'acuity-sync')->count();

        return [
            config('app.demo_mode')
                ? Stat::make('Scheduled Sync', 'SIMULATED')
                    ->description('Two-hourly pulls, sample data')
                    ->descriptionIcon('heroicon-m-bolt')
                    ->color('success')
                : Stat::make('Real-Time Sync', 'ACTIVE')
                    ->description('Webhooks + Background Jobs')
                    ->descriptionIcon('heroicon-m-bolt')
                    ->color('success'),

            Stat::make('Last Sync', $lastSync ? $lastSync->completed_at?->diffForHumans() : 'Never')
                ->description('Latest data update')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($lastSync && $lastSync->status === 'completed' ? 'success' : 'warning'),

            Stat::make('Queued Jobs', $queuedJobs)
                ->description('Background processing')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color($queuedJobs > 0 ? 'info' : 'success'),

            Stat::make('Sync Errors Today', $failedSyncs)
                ->description('Failed synchronizations')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedSyncs > 0 ? 'danger' : 'success'),
        ];
    }
}
