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
                $table->string('external_source')->default('acuity')->after('id');
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

        $this->enforceNotNull('external_id');

        $duplicate = DB::table('school_classes')
            ->select('external_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('external_id')
            ->groupBy('external_id')
            ->having('total', '>', 1)
            ->first();

        if ($duplicate) {
            throw new \RuntimeException('Duplicate school_classes.external_id rows remain. Run school-classes:deduplicate --force.');
        }

        try {
            Schema::table('school_classes', function (Blueprint $table) {
                $table->unique('external_id', 'school_classes_external_id_unique');
            });
        } catch (\Throwable $e) {
            // Index exists; nothing else to do.
        }
    }

    protected function enforceNotNull(string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE school_classes MODIFY {$column} VARCHAR(255) NOT NULL");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE school_classes ALTER COLUMN {$column} SET NOT NULL");
        }
        // SQLite fallback handled via data consistency + unique index.
    }
};
