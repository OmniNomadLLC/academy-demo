<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * NOTE (2026-04-23): Laravel 12 loads the schedule from routes/console.php,
     * registered via bootstrap/app.php. This method is NO LONGER INVOKED and
     * is kept only as a historical reference — do not edit here, edit
     * routes/console.php instead. Any changes below are ignored at runtime.
     */
    protected function schedule(Schedule $schedule): void
    {
        return; // schedule lives in routes/console.php — see note above

        // Sync students every 5 minutes
        $schedule->command('acuity:sync-clients --limit=100')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Sync appointments every 2 minutes
        $schedule->command('acuity:sync-appointments --days=7 --limit=50')
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Keep Riverside employability calendar hydrated a year out
        $schedule->command('acuity:sync-appointments --days=7 --forward=365 --limit=0 --sliceDays=14 --calendarId=8716434')
            ->dailyAt('03:20')
            ->withoutOverlapping()
            ->runInBackground();

        // Full sync once per hour
        $schedule->command('acuity:sync-all --quick')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // Audit drift every morning and alert if any missing
        $schedule->command('acuity:audit-drift --threshold=1')
            ->dailyAt('07:15')
            ->withoutOverlapping()
            ->runInBackground();

        // Students audit summary (missing/duplicates/stale)
        $schedule->command('students:audit-acuity --stale-days=90 --email')
            ->dailyAt('07:05')
            ->withoutOverlapping()
            ->runInBackground();

        // Nightly purge of placeholder students (no email and no client ID)
        $schedule->command('students:purge-no-email --notify')
            ->dailyAt('02:10')
            ->withoutOverlapping()
            ->runInBackground();

        // Relink teacher assignments nightly so portal/report data stays in sync
        $schedule->command('teacher-assignments:sync')
            ->dailyAt('02:20')
            ->withoutOverlapping()
            ->runInBackground();

        // Second pass after long-range imports to catch late teacher/type edits
        $schedule->command('teacher-assignments:sync --chunk=200')
            ->dailyAt('04:05')
            ->withoutOverlapping()
            ->runInBackground();

        // Maintain derived student activity flags and next appointment
        $schedule->command('students:backfill-next-appointment --chunk=1000 --horizon=365')
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('students:update-active-flag --past=14 --future=45 --chunk=2000')
            ->dailyAt('02:40')
            ->withoutOverlapping()
            ->runInBackground();

        // Refresh catalog + caches twice daily so dropdowns/filters stay warm
        $schedule->command('teacher-appointment-types:refresh')
            ->twiceDaily(1, 13)
            ->withoutOverlapping()
            ->runInBackground();

        // Midday teacher reassignment to capture staffing edits after morning syncs
        $schedule->command('teacher-assignments:sync --chunk=200')
            ->dailyAt('13:30')
            ->withoutOverlapping()
            ->runInBackground();

        // Midday recency refresh so KPIs catch day-of bookings/cancellations
        $schedule->command('students:backfill-next-appointment --chunk=1000 --horizon=365')
            ->dailyAt('13:45')
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('students:update-active-flag --past=14 --future=45 --chunk=2000')
            ->dailyAt('13:55')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('db:verify')
            ->dailyAt('02:50')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('backup:run')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('backup:clean')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('backup:check-health')
            ->weeklyOn(1, '09:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Remove orphaned student photos weekly so storage stays lean
        $schedule->command('student-photos:clean')
            ->weeklyOn(1, '04:30')
            ->withoutOverlapping()
            ->runInBackground();

        // Weekly rolling import slices keep long-range calendar data hydrated without manual commands
        $schedule->call(function () {
            $start = now()->copy()->subDays(14)->startOfDay();
            $end = now()->copy()->addDays(180)->endOfDay();

            $payload = [
                '--from' => $start->toDateString(),
                '--to' => $end->toDateString(),
                '--sliceDays' => 7,
                '--pageSize' => 100,
            ];

            Artisan::call('acuity:import-appointments', $payload);
            Log::info('Queued rolling Acuity import window.', $payload);
        })
            ->weeklyOn(6, '05:45')
            ->withoutOverlapping()
            ->runInBackground();

        // Hourly Acuity/queue health check to alert on stalled webhooks or workers
        $schedule->command('acuity:health-check')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
