<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_sessions')) {
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_session_date_start_idx ON class_sessions (session_date, start_time)');
            } catch (\Throwable $e) {
                try { Schema::table('class_sessions', function (Blueprint $table) { $table->index(['session_date','start_time'], 'class_sessions_session_date_start_idx'); }); } catch (\Throwable $e2) {}
            }
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_session_date_location_start_idx ON class_sessions (session_date, location, start_time)');
            } catch (\Throwable $e) {
                try { Schema::table('class_sessions', function (Blueprint $table) { $table->index(['session_date','location','start_time'], 'class_sessions_session_date_location_start_idx'); }); } catch (\Throwable $e2) {}
            }
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_location_idx ON class_sessions (location)');
            } catch (\Throwable $e) {
                try { Schema::table('class_sessions', function (Blueprint $table) { $table->index('location', 'class_sessions_location_idx'); }); } catch (\Throwable $e2) {}
            }

            // Cover GROUP BY columns for Today's Upcoming Classes
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_today_group_idx ON class_sessions (session_date, location, start_time, end_time, calendar_name)');
            } catch (\Throwable $e) {
                try { Schema::table('class_sessions', function (Blueprint $table) { $table->index(['session_date','location','start_time','end_time','calendar_name'], 'class_sessions_today_group_idx'); }); } catch (\Throwable $e2) {}
            }

            // Speed up teacher-specific widget
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS class_sessions_teacher_day_idx ON class_sessions (teacher_id, session_date, start_time)');
            } catch (\Throwable $e) {
                try { Schema::table('class_sessions', function (Blueprint $table) { $table->index(['teacher_id','session_date','start_time'], 'class_sessions_teacher_day_idx'); }); } catch (\Throwable $e2) {}
            }
        }
    }

    public function down(): void
    {
        // Keep indexes; non-destructive
    }
};
