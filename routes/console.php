<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled commands
|--------------------------------------------------------------------------
|
| Laravel 12 reads the schedule from this file (registered via
| bootstrap/app.php -> commands:). The legacy app/Console/Kernel.php::schedule()
| is NOT invoked and should be left as a no-op reference.
|
| Ordering is deliberate — early-morning Acuity/students/teacher chains are
| spaced to respect Acuity rate limits. Do not move times without checking
| dependencies.
|
*/

// -- Acuity sync pipeline (high frequency) ------------------------------------
Schedule::command('acuity:sync-clients --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:sync-appointments --days=7 --limit=50')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:sync-incremental')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:sync-all --quick')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Hourly queue health actions
Schedule::command('queue:retry all')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:health-check')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// -- Early-morning nightly chain (times are deliberate — keep spacing) --------
Schedule::command('queue:flush')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:purge-no-email --notify')
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('teacher-assignments:sync')
    ->dailyAt('02:20')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:backfill-next-appointment --chunk=1000 --horizon=365')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:archive-inactive')
    ->dailyAt('02:35')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:update-active-flag --past=14 --future=45 --chunk=2000')
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('db:verify')
    ->dailyAt('02:50')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Riverside long-range (365d forward) hydration — keeps employability calendar warm
Schedule::command('acuity:sync-appointments --days=7 --forward=365 --limit=0 --sliceDays=14 --calendarId=8716434')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// All-calendars conservative sweep — picks up students on non-default
// calendars (e.g. the referral partner re-referrals) that the 03:20 filtered run skips.
// --forward=60 / --sliceDays=7 keeps API load contained given the ongoing
// Acuity timeout noise (carryover #38).
Schedule::command('acuity:sync-appointments --days=7 --forward=60 --limit=0 --sliceDays=7')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();

// Second teacher-assignment pass after long-range imports
Schedule::command('teacher-assignments:sync --chunk=200')
    ->dailyAt('04:05')
    ->withoutOverlapping()
    ->runInBackground();

// -- Morning audit & alert jobs -----------------------------------------------
Schedule::command('students:audit-acuity --stale-days=90 --email')
    ->dailyAt('07:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:audit-drift --threshold=1')
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->runInBackground();

// -- Midday recency/refresh passes (capture day-of bookings + cancellations) --
Schedule::command('teacher-appointment-types:refresh')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('teacher-appointment-types:refresh')
    ->dailyAt('13:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('teacher-assignments:sync --chunk=200')
    ->dailyAt('13:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:backfill-next-appointment --chunk=1000 --horizon=365')
    ->dailyAt('13:45')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('students:update-active-flag --past=14 --future=45 --chunk=2000')
    ->dailyAt('13:55')
    ->withoutOverlapping()
    ->runInBackground();

// -- Lumina Works ----------------------------------------------------------------
// Daily vacancy pull + matching; both no-op while LUMINAWORKS_ENABLED=false.
if (config('luminaworks.enabled')) {
    Schedule::command('luminaworks:sync-jobs --where=London --distance=30')
        ->dailyAt('05:30')
        ->withoutOverlapping()
        ->runInBackground();

    Schedule::command('luminaworks:match-students')
        ->dailyAt('06:00')
        ->withoutOverlapping()
        ->runInBackground();
}

// -- Weekly jobs --------------------------------------------------------------
Schedule::command('student-photos:clean')
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('backup:check-health')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('acuity:cleanup-logs')
    ->weekly()
    ->sundays()
    ->at('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Weekly rolling Acuity import — keeps the -14d..+180d window hydrated
Schedule::call(function () {
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
    ->name('acuity:rolling-import-closure')
    ->weeklyOn(6, '05:45')
    ->withoutOverlapping();
    // note: closure events cannot ->runInBackground() — scheduled closures
    // must run in the scheduler process itself.

// -- Scheduler self-monitor (heartbeat — silence == scheduler is dead) --------
// Writes a line to laravel.log every 5 minutes. Absence of the heartbeat for
// more than ~15 minutes is a strong signal the scheduler cron stopped firing
// or Laravel is failing to boot during the schedule pass.
//
// Uses Log::warning (not info) so the line survives strict LOG_LEVEL settings.
// Staging ran LOG_LEVEL=error for months, which silently suppressed Log::info
// heartbeats and made the canary useless precisely when it was needed
// (2026-04-24: discovered the heartbeat had never written to staging logs
// despite the scheduler running fine). Warning level pierces every realistic
// production LOG_LEVEL.
Schedule::call(function () {
    Log::warning('Scheduler heartbeat', ['at' => now()->toIso8601String()]);
})
    ->name('scheduler-heartbeat')
    ->everyFiveMinutes()
    ->withoutOverlapping();
