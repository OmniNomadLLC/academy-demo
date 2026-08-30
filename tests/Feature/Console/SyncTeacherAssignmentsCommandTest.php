<?php

namespace Tests\Feature\Console;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTeacherAssignmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_assigns_matching_sessions_to_teacher(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
            'teacher_scope' => 'assigned_classes',
        ]);

        $schoolClass = SchoolClass::create([
            'name' => 'Employability',
            'language' => 'English',
            'location' => 'UK',
            'max_students' => 15,
        ]);

        $matching = $this->createSession($schoolClass, 'CAL-123', 'TYPE-A');
        $other = $this->createSession($schoolClass, 'CAL-123', 'TYPE-B');

        TeacherAppointmentTypeAssignment::create([
            'user_id' => $teacher->id,
            'acuity_calendar_id' => 'CAL-123',
            'acuity_appointment_type_id' => 'TYPE-A',
            'appointment_type_name' => 'Test block',
        ]);

        $this->artisan('teacher-assignments:sync', ['teacher' => $teacher->id])
            ->assertExitCode(0);

        $this->assertSame($teacher->id, $matching->fresh()->teacher_id);
        $this->assertNull($other->fresh()->teacher_id);
    }

    private function createSession(SchoolClass $class, string $calendarId, string $typeId): ClassSession
    {
        $payload = [
            'calendarID' => $calendarId,
            'appointmentTypeID' => $typeId,
        ];

        return ClassSession::create([
            'school_class_id' => $class->id,
            'session_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'max_students' => 10,
            'location' => 'UK',
            'calendar_name' => $calendarId,
            'calendar_norm' => strtolower($calendarId),
            'acuity_appointment_id' => Str::uuid()->toString(),
            'acuity_data' => $payload,
        ]);
    }
}
