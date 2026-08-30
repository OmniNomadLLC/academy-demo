<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        DB::table('attendance_records')
            ->select('class_session_id', 'student_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('class_session_id', 'student_id')
            ->having('aggregate', '>', 1)
            ->orderBy('class_session_id')
            ->orderBy('student_id')
            ->chunk(500, function ($groups) {
                foreach ($groups as $group) {
                    $rows = DB::table('attendance_records')
                        ->where('class_session_id', $group->class_session_id)
                        ->where('student_id', $group->student_id)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->get();

                    $keep = $rows->shift();
                    if (! $keep) {
                        continue;
                    }

                    $idsToDelete = $rows->pluck('id')->all();
                    if (! empty($idsToDelete)) {
                        DB::table('attendance_records')->whereIn('id', $idsToDelete)->delete();
                    }
                }
            });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unique(['class_session_id', 'student_id'], 'uniq_attendance_session_student');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('uniq_attendance_session_student');
        });
    }
};
