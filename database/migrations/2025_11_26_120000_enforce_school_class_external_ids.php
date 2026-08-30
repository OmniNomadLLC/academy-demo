<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_classes')) {
            return;
        }

        Schema::table('school_classes', function (Blueprint $table) {
            if (! Schema::hasColumn('school_classes', 'external_source')) {
                $table->string('external_source')->nullable()->after('id');
            }
            if (! Schema::hasColumn('school_classes', 'external_id')) {
                $table->string('external_id')->nullable()->after('external_source');
            }
        });

        DB::table('school_classes')
            ->where(function ($query) {
                $query->whereNull('external_source')->orWhere('external_source', '=','');
            })
            ->update(['external_source' => 'acuity']);

        $driver = Schema::getConnection()->getDriverName();
        $expression = in_array($driver, ['mysql', 'mariadb'])
            ? DB::raw("CONCAT('legacy-', id)")
            : DB::raw("'legacy-' || id");

        DB::table('school_classes')
            ->where(function ($query) {
                $query->whereNull('external_id')->orWhere('external_id', '=','');
            })
            ->update(['external_id' => $expression]);

        $duplicate = DB::table('school_classes')
            ->select('external_source', 'external_id', DB::raw('COUNT(*) as total'))
            ->groupBy('external_source', 'external_id')
            ->having('total', '>', 1)
            ->first();

        if ($duplicate) {
            throw new \RuntimeException('Duplicate school_classes external IDs detected. Run school-classes:deduplicate --force before rerunning this migration.');
        }

        $this->enforceNotNull();

        try {
            Schema::table('school_classes', function (Blueprint $table) {
                $table->unique(['external_source', 'external_id'], 'school_classes_external_unique');
            });
        } catch (\Throwable $e) {
            // Index already exists; ignore.
        }
    }

    protected function enforceNotNull(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE school_classes MODIFY external_source VARCHAR(255) NOT NULL DEFAULT 'acuity'");
            DB::statement("ALTER TABLE school_classes MODIFY external_id VARCHAR(255) NOT NULL");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE school_classes ALTER COLUMN external_source SET DEFAULT 'acuity'");
            DB::statement("ALTER TABLE school_classes ALTER COLUMN external_source SET NOT NULL");
            DB::statement("ALTER TABLE school_classes ALTER COLUMN external_id SET NOT NULL");
            return;
        }

        // SQLite and other drivers do not support altering columns in-place; the dedupe command ensures data integrity instead.
    }
};
