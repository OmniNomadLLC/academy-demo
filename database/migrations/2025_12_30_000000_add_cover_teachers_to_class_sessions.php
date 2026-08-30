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
            if (! Schema::hasColumn('class_sessions', 'assigned_teacher_id')) {
                $table->unsignedBigInteger('assigned_teacher_id')->nullable()->after('teacher_id');
                $table->index('assigned_teacher_id', 'class_sessions_assigned_teacher_idx');
            }

            if (! Schema::hasColumn('class_sessions', 'cover_teacher_id')) {
                $table->unsignedBigInteger('cover_teacher_id')->nullable()->after('assigned_teacher_id');
                $table->index('cover_teacher_id', 'class_sessions_cover_teacher_idx');
            }
        });

        DB::table('class_sessions')
            ->whereNull('assigned_teacher_id')
            ->update(['assigned_teacher_id' => DB::raw('teacher_id')]);
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'cover_teacher_id')) {
                $table->dropIndex('class_sessions_cover_teacher_idx');
                $table->dropColumn('cover_teacher_id');
            }

            if (Schema::hasColumn('class_sessions', 'assigned_teacher_id')) {
                $table->dropIndex('class_sessions_assigned_teacher_idx');
                $table->dropColumn('assigned_teacher_id');
            }
        });
    }
};
