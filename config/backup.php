<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

// Required in production/staging — refuse to boot instead of silently falling back
// to MAIL_FROM_ADDRESS. Prior behaviour sent backup success/failure mails FROM
// alert@example.test TO alert@example.test on prod (self-loop, mail.axc.nl filtered the
// delivery), so operators never saw notifications for ~2 weeks. CI runners and
// local dev have no .env at composer install time, so the check only fires in real
// environments. See docs/daily/2026-04-23.md + CLAUDE.md for context.
$recipientsRaw = env('BACKUP_ALERT_RECIPIENTS');
if (empty($recipientsRaw) && in_array(env('APP_ENV'), ['production', 'staging'], true)) {
    throw new \RuntimeException(
        'BACKUP_ALERT_RECIPIENTS must be set in .env — '
        . 'cannot silently fallback to MAIL_FROM_ADDRESS (self-loop risk).'
    );
}
$alertRecipients = array_values(array_filter(array_unique(
    array_map('trim', explode(',', (string) ($recipientsRaw ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'))))
)));

return [
    'backup' => [
        'name' => 'lumina-backups',
        'source' => [
            'files' => [
                'include' => [],
                'exclude' => [],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],
        'database_dump_compressor' => GzipCompressor::class,
        'database_dump_file_timestamp_format' => 'Y-m-d-H-i-s',
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => 'sql.gz',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => 'lumina-',
            'disks' => [
                'local',
                's3',
            ],
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => env('BACKUP_ARCHIVE_ENCRYPTION', 'default'),
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            'to' => $alertRecipients[0], // guaranteed non-empty by the check at the top of the file
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
            ],
        ],
        'slack' => [
            'webhook_url' => (string) env('BACKUP_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],
        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    'monitor_backups' => [
        [
            'name' => 'lumina-backups',
            'disks' => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class => 2,
                MaximumStorageInMegabytes::class => 20480,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 7,
            'keep_weekly_backups_for_weeks' => 4,
            'keep_monthly_backups_for_months' => 3,
            'keep_yearly_backups_for_years' => 1,
            'delete_oldest_backups_when_using_more_megabytes_than' => 4096,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'alert_recipients' => $alertRecipients,
];
