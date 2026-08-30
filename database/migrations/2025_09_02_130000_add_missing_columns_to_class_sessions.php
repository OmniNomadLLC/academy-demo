<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'school_class_id')) {
                // Add as nullable to be safe across environments; keep it simple (FK optional)
                $table->unsignedBigInteger('school_class_id')->nullable()->after('id');
                $table->index('school_class_id', 'class_sessions_school_class_id_idx');
            }

            if (!Schema::hasColumn('class_sessions', 'calendar_name')) {
                $table->string('calendar_name')->nullable()->after('location');
                $table->index('calendar_name', 'class_sessions_calendar_name_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'calendar_name')) {
                $table->dropIndex('class_sessions_calendar_name_idx');
                $table->dropColumn('calendar_name');
            }
            // We will not drop school_class_id in down to avoid breaking existing FKs
        });
    }
};

