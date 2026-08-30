<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'client_email')) {
                $table->string('client_email')->nullable()->after('teacher_id');
            }
            if (!Schema::hasColumn('class_sessions', 'calendar_norm')) {
                $table->string('calendar_norm')->nullable();
            }
            if (!Schema::hasColumn('class_sessions', 'category_norm')) {
                $table->string('category_norm')->nullable()->after('acuity_data');
            }
            if (!Schema::hasColumn('class_sessions', 'link_status')) {
                $table->string('link_status')->nullable()->default('linked')->after('notes');
            }
        });

        // Indexes (guarded; use IF NOT EXISTS where possible)
        $driver = DB::getDriverName();

        // Single-column indexes
        $singleIndexes = [
            'class_sessions_client_email_idx' => ['client_email'],
            'class_sessions_calendar_norm_idx' => ['calendar_norm'],
            'class_sessions_category_norm_idx' => ['category_norm'],
        ];

        foreach ($singleIndexes as $name => $cols) {
            $col = $cols[0];
            if (!Schema::hasColumn('class_sessions', $col)) {
                continue;
            }
            if ($driver === 'sqlite') {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON class_sessions ({$col})");
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON class_sessions ({$col})");
            } else {
                // Fallback: attempt normal builder (may fail if index exists already, but typical on first run)
                try {
                    Schema::table('class_sessions', function (Blueprint $table) use ($col, $name) {
                        $table->index($col, $name);
                    });
                } catch (\Throwable $e) {
                    // ignore if already exists
                }
            }
        }

        // Composite index on (session_date, start_time)
        if (Schema::hasColumn('class_sessions', 'session_date') && Schema::hasColumn('class_sessions', 'start_time')) {
            $name = 'class_sessions_session_start_idx';
            if ($driver === 'sqlite') {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON class_sessions (session_date, start_time)");
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON class_sessions (session_date, start_time)");
            } else {
                try {
                    Schema::table('class_sessions', function (Blueprint $table) use ($name) {
                        $table->index(['session_date', 'start_time'], $name);
                    });
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            // Non-destructive: keep columns; only drop indexes if present
        });
    }
};

