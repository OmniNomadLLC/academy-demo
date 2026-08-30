<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('jobs', 'created_at')) {
            return;
        }

        // Schema::getColumnType() is driver-agnostic; SHOW COLUMNS is MySQL-only.
        $type = Schema::getColumnType('jobs', 'created_at');

        if (str_contains(strtolower($type), 'int')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->timestamp('created_at_tmp')->nullable()->after('created_at');
            });

            $epochToDatetime = DB::getDriverName() === 'sqlite'
                ? "datetime(`created_at`, 'unixepoch')"
                : 'FROM_UNIXTIME(`created_at`)';

            DB::statement("UPDATE `jobs` SET `created_at_tmp` = {$epochToDatetime} WHERE `created_at` IS NOT NULL");

            Schema::table('jobs', function (Blueprint $table) {
                $table->dropColumn('created_at');
            });

            Schema::table('jobs', function (Blueprint $table) {
                $table->renameColumn('created_at_tmp', 'created_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('jobs', 'created_at')) {
            return;
        }

        $type = Schema::getColumnType('jobs', 'created_at');

        if (! str_contains(strtolower($type), 'timestamp') && ! str_contains(strtolower($type), 'datetime')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('created_at_tmp')->nullable()->after('created_at');
        });

        $datetimeToEpoch = DB::getDriverName() === 'sqlite'
            ? "strftime('%s', `created_at`)"
            : 'UNIX_TIMESTAMP(`created_at`)';

        DB::statement("UPDATE `jobs` SET `created_at_tmp` = {$datetimeToEpoch} WHERE `created_at` IS NOT NULL");

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->renameColumn('created_at_tmp', 'created_at');
        });
    }
};
