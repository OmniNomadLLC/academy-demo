<?php

namespace App\Console\Commands;

use App\Mail\DatabaseDumpFailed;
use App\Support\Backup\MySqlDumperFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class VerifyDatabaseDump extends Command
{
    protected $signature = 'db:verify {--connection= : Database connection name} {--keep : Keep the generated dump file for inspection}';

    protected $description = 'Smoke-test mysqldump credentials and alert if the export fails.';

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $targetDir = storage_path('app/backup-temp');
        File::ensureDirectoryExists($targetDir, 0755, true);
        $dumpPath = $targetDir . DIRECTORY_SEPARATOR . sprintf('verify-%s-%s.sql', $connection, Str::uuid());

        $this->info(sprintf('Verifying %s database credentials via mysqldump…', $connection));

        try {
            $dumper = MySqlDumperFactory::make($connection, $targetDir);
            $dumper->dumpToFile($dumpPath);
            $this->info('Database dump completed successfully.');

            if (! $this->option('keep')) {
                @unlink($dumpPath);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Database dump failed: '.$exception->getMessage());

            $recipients = config('backup.alert_recipients', []);
            if (! empty($recipients)) {
                Mail::to($recipients)->send(new DatabaseDumpFailed($exception));
            }

            if (File::exists($dumpPath)) {
                @unlink($dumpPath);
            }

            return self::FAILURE;
        }
    }
}
