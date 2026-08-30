<?php

namespace App\Support\Backup;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\DbDumper;

class MySqlDumperFactory
{
    public static function make(?string $connection = null, ?string $temporaryDirectory = null): DbDumper
    {
        $connection = $connection ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! $config || ($config['driver'] ?? null) !== 'mysql') {
            throw new InvalidArgumentException("Connection [{$connection}] is not a MySQL connection.");
        }

        $temporaryDirectory = $temporaryDirectory ?: env('DB_DUMP_TMPDIR', storage_path('app/backup-temp'));
        File::ensureDirectoryExists($temporaryDirectory, 0755, true);

        putenv("TMPDIR={$temporaryDirectory}");
        putenv("MYSQL_TMPDIR={$temporaryDirectory}");

        $dumper = MySql::create()
            ->setDbName($config['database'] ?? '')
            ->setUserName($config['username'] ?? '')
            ->setPassword($config['password'] ?? '')
            ->setHost($config['host'] ?? '127.0.0.1')
            ->setPort((int) ($config['port'] ?? 3306));

        if (! empty($config['unix_socket'])) {
            $dumper->setSocket($config['unix_socket']);
        }

        if ($binaryPath = env('MYSQLDUMP_PATH')) {
            // Spatie's setDumpBinaryPath() expects a DIRECTORY and appends "/mysqldump"
            // itself. Historically prod and staging .env disagreed: one held the dir,
            // the other the full file path. The full-path variant produced
            // "/usr/bin/mysqldump/mysqldump" → exit 127 every night at 02:50.
            // Normalise here so either form works regardless of env drift.
            $dumper->setDumpBinaryPath(is_file($binaryPath) ? dirname($binaryPath) : $binaryPath);
        }

        $timeout = (int) env('DB_DUMP_TIMEOUT', 300);
        if ($timeout > 0) {
            $dumper->setTimeout($timeout);
        }

        if (data_get($config, 'dump.useSingleTransaction', true)) {
            $dumper->useSingleTransaction();
        }

        $dumper->addExtraOption('--column-statistics=0');
        $dumper->addExtraOption('--routines');
        $dumper->addExtraOption('--events');
        $dumper->addExtraOption('--triggers');
        $dumper->dontSkipComments();

        $protocol = data_get($config, 'options.protocol');
        if ($protocol) {
            $dumper->addExtraOption('--protocol='.strtoupper($protocol));
        }

        $dumpOptions = Arr::get($config, 'dump', []);
        foreach ((array) Arr::get($dumpOptions, 'exclude_tables', []) as $table) {
            $dumper->excludeTables($table);
        }

        return $dumper;
    }
}
