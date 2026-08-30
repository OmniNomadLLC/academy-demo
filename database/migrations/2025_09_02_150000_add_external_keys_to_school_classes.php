<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('school_classes', 'external_source')) {
                $table->string('external_source')->default('acuity')->after('id');
            }
            if (!Schema::hasColumn('school_classes', 'external_id')) {
                $table->string('external_id')->nullable()->after('external_source');
            }
        });

        // Unique index on (external_source, external_id)
        try {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS school_classes_external_unique ON school_classes (external_source, external_id)');
        } catch (\Throwable $e) {
            // Fallback for drivers without IF NOT EXISTS support
            try {
                Schema::table('school_classes', function (Blueprint $table) {
                    $table->unique(['external_source', 'external_id'], 'school_classes_external_unique');
                });
            } catch (\Throwable $e2) {
                // ignore if exists
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: keep columns/index
    }
};

