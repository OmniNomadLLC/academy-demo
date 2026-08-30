<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAcuityData extends Command
{
    protected $signature = 'acuity:reset-import
        {--force : Run without interactive confirmation}
        {--days=180 : Days in the past to include}
        {--forward=365 : Days in the future to include}
        {--limit=0 : Max appointments to fetch (0 = unlimited)}
        {--pageSize=200 : Page size per API request}
    ';

    protected $description = 'DANGER: Flush Acuity-derived tables and perform a fresh import from Acuity (clients + appointments)';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (! $this->confirm('This will DELETE Acuity-derived data (sessions, students, classes, webhook logs). Continue?')) {
                $this->warn('Aborted.');
                return self::SUCCESS;
            }
        }

        $this->info('Stopping foreign key checks...');
        $driver = DB::getDriverName();
        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($driver === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        $tables = [
            'class_sessions',
            'students',
            'school_classes',
            'acuity_webhook_events',
            'acuity_sync_logs',
            'attendance_records',
        ];

        $this->info('Flushing tables: '.implode(', ', $tables));
        foreach ($tables as $t) {
            try {
                if ($driver === 'sqlite') {
                    DB::table($t)->delete();
                } else {
                    DB::table($t)->truncate();
                }
            } catch (\Throwable $e) {
                $this->warn("Skip/failed truncating {$t}: ".$e->getMessage());
            }
        }

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        } catch (\Throwable $e) {}

        $days = (int) $this->option('days');
        $forward = (int) $this->option('forward');
        $limit = (int) $this->option('limit');
        $pageSize = (int) $this->option('pageSize');

        $this->info('Starting fresh import from Acuity...');
        $this->call('acuity:sync-clients', [
            '--limit' => 0,
        ]);

        $this->call('acuity:sync-appointments', [
            '--days' => $days,
            '--forward' => $forward,
            '--limit' => $limit,
            '--pageSize' => $pageSize,
        ]);

        $this->info('Backfilling normalized fields...');
        $this->call('sessions:backfill-norms', [
            '--limit' => 500000,
        ]);

        $this->info('Done. Verify Upcoming pages and calendar counts.');
        return self::SUCCESS;
    }
}
