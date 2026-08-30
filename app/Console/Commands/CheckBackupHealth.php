<?php

namespace App\Console\Commands;

use App\Mail\BackupHealthReportMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Backup\Config\MonitoredBackupsConfig;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;

class CheckBackupHealth extends Command
{
    protected $signature = 'backup:check-health';

    protected $description = 'List recent backups, evaluate health checks, and email the digest to ops.';

    public function handle(): int
    {
        $this->callSilent('backup:list');

        $monitorConfig = config('backup.monitor_backups', []);
        if (! $monitorConfig instanceof MonitoredBackupsConfig) {
            $monitorConfig = MonitoredBackupsConfig::fromArray((array) $monitorConfig);
        }

        $statuses = collect(BackupDestinationStatusFactory::createForMonitorConfig($monitorConfig));

        if ($statuses->isEmpty()) {
            $this->warn('No backup destinations configured for monitoring.');

            return self::SUCCESS;
        }

        $report = $statuses->map(function ($status) {
            $destination = $status->backupDestination();
            $newest = $destination->newestBackup();

            return [
                'name' => $destination->backupName(),
                'disk' => $destination->diskName(),
                'latest' => $newest ? $newest->date()->toDateTimeString() : 'n/a',
                'age_days' => $newest ? now()->diffInDays($newest->date()) : null,
                'size_mb' => $newest ? round($newest->sizeInBytes() / 1024 / 1024, 2) : 0,
                'status' => $status->isHealthy() ? 'healthy' : 'unhealthy',
                'failure' => $status->isHealthy() ? null : optional($status->getHealthCheckFailure())->exception()->getMessage(),
            ];
        })->all();

        $this->table([
            'Backup',
            'Disk',
            'Latest',
            'Age (days)',
            'Size (MB)',
            'Status',
            'Notes',
        ], array_map(function ($row) {
            return [
                $row['name'],
                $row['disk'],
                $row['latest'],
                $row['age_days'] ?? 'n/a',
                $row['size_mb'],
                Str::title($row['status']),
                $row['failure'] ?? '',
            ];
        }, $report));

        $recipients = config('backup.alert_recipients', []);
        if (! empty($recipients)) {
            Mail::to($recipients)->send(new BackupHealthReportMail($report));
            $this->info('Backup health digest emailed to configured recipients.');
        } else {
            $this->warn('No backup.alert_recipients configured; skipping email.');
        }

        $hasFailures = collect($report)->contains(fn ($row) => $row['status'] !== 'healthy');

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
