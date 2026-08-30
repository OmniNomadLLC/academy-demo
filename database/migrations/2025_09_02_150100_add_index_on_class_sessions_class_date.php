<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_sessions') && Schema::hasColumn('class_sessions', 'school_class_id') && Schema::hasColumn('class_sessions', 'session_date')) {
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_class_date_idx ON class_sessions (school_class_id, session_date)');
            } catch (\Throwable $e) {
                try {
                    Schema::table('class_sessions', function (Blueprint $table) {
                        $table->index(['school_class_id', 'session_date'], 'class_sessions_class_date_idx');
                    });
                } catch (\Throwable $e2) {
                    // ignore
                }
            }
        }
    }

    public function down(): void
    {
        // keep index; non-destructive
    }
};

