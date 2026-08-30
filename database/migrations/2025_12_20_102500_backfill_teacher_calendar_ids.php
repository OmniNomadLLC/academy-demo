<?php

use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\User;
use App\Support\TeacherAppointmentTypeAllocator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'teacher_calendar_ids') || ! Schema::hasTable('teacher_appointment_type_assignments')) {
            return;
        }

        User::query()
            ->whereIn('role', User::TEACHING_ROLES)
            ->where('is_active', true)
            ->each(function (User $teacher): void {
                $assignments = TeacherAppointmentTypeAssignment::query()
                    ->where('user_id', $teacher->id)
                    ->pluck('acuity_calendar_id')
                    ->filter(fn ($value) => is_string($value) || is_numeric($value))
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (empty($assignments)) {
                    return;
                }

                $teacher->forceFill([
                    'teacher_calendar_ids' => $assignments,
                ])->save();

                TeacherAppointmentTypeAllocator::sync($teacher, $assignments);
            });
    }

    public function down(): void
    {
        // No-op: data backfill only.
    }
};
