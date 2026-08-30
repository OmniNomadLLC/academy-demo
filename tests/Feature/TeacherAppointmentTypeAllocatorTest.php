<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\User;
use App\Support\TeacherAppointmentTypeAllocator;
use App\Support\TeacherRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherAppointmentTypeAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocator_assigns_matching_calendar_and_type(): void
    {
        $teacher = $this->createTeacher();
        $schoolClass = $this->createSchoolClass();

        $matching = $this->createSession($schoolClass, 'CAL-1', 'TYPE-A');
        $otherType = $this->createSession($schoolClass, 'CAL-1', 'TYPE-B');
        $otherCalendar = $this->createSession($schoolClass, 'CAL-2', 'TYPE-A');

        TeacherAppointmentTypeAssignment::create([
            'user_id' => $teacher->id,
            'acuity_calendar_id' => 'CAL-1',
            'acuity_appointment_type_id' => 'TYPE-A',
            'appointment_type_name' => 'Friday Riverside',
        ]);

        TeacherAppointmentTypeAllocator::sync($teacher);

        $this->assertSame($teacher->id, $matching->fresh()->teacher_id);
        $this->assertNull($otherType->fresh()->teacher_id);
        $this->assertNull($otherCalendar->fresh()->teacher_id);

        TeacherAppointmentTypeAssignment::query()->delete();
        $teacher->unsetRelation('teacherAppointmentTypeAssignments');
        TeacherAppointmentTypeAllocator::sync($teacher);

        $this->assertNull($matching->fresh()->teacher_id);
    }

    public function test_teacher_with_calendar_and_no_type_restrictions_claims_entire_calendar(): void
    {
        $teacher = $this->createTeacher([
            'acuity_calendar_id' => 'CAL-FULL',
            'teacher_calendar_ids' => ['CAL-FULL'],
        ]);

        $schoolClass = $this->createSchoolClass();
        $sessionA = $this->createSession($schoolClass, 'CAL-FULL', 'TYPE-A');
        $sessionB = $this->createSession($schoolClass, 'CAL-FULL', 'TYPE-B');
        $outside = $this->createSession($schoolClass, 'CAL-OTHER', 'TYPE-A');

        TeacherAppointmentTypeAllocator::sync($teacher);

        $this->assertSame($teacher->id, $sessionA->fresh()->teacher_id);
        $this->assertSame($teacher->id, $sessionB->fresh()->teacher_id);
        $this->assertNull($outside->fresh()->teacher_id);

        $teacher->teacher_calendar_ids = [];
        $teacher->acuity_calendar_id = null;
        $teacher->save();

        TeacherAppointmentTypeAllocator::sync($teacher, ['CAL-FULL']);

        $this->assertNull($sessionA->fresh()->teacher_id);
        $this->assertNull($sessionB->fresh()->teacher_id);
    }

    public function test_teacher_without_assignments_does_not_override_calendar_with_other_assignments(): void
    {
        $primary = $this->createTeacher([
            'acuity_calendar_id' => 'CAL-SHARED',
        ]);
        $secondary = $this->createTeacher([
            'acuity_calendar_id' => 'CAL-SHARED',
        ]);

        $schoolClass = $this->createSchoolClass();
        $session = $this->createSession($schoolClass, 'CAL-SHARED', 'TYPE-A');

        TeacherAppointmentTypeAssignment::create([
            'user_id' => $primary->id,
            'acuity_calendar_id' => 'CAL-SHARED',
            'acuity_appointment_type_id' => 'TYPE-A',
            'appointment_type_name' => 'Shared slot',
        ]);

        TeacherAppointmentTypeAllocator::sync($primary);
        TeacherAppointmentTypeAllocator::sync($secondary);

        $this->assertSame($primary->id, $session->fresh()->teacher_id);
    }

    public function test_calendar_linked_scope_uses_teacher_calendar_ids(): void
    {
        $teacher = $this->createTeacher([
            'teacher_scope' => 'calendar_linked',
            'acuity_calendar_id' => 'CAL-PRIMARY',
            'teacher_calendar_ids' => ['CAL-PRIMARY', 'CAL-EXTRA'],
        ]);

        $class = $this->createSchoolClass();
        $primary = $this->createSession($class, 'CAL-PRIMARY', 'TYPE-A');
        $extra = $this->createSession($class, 'CAL-EXTRA', 'TYPE-B');
        $outside = $this->createSession($class, 'CAL-THIRD', 'TYPE-C');

        $sessions = TeacherRoster::sessions($teacher)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([
            $primary->id,
            $extra->id,
        ], $sessions);
        $this->assertNotContains($outside->id, $sessions);
    }

    public function test_teacher_calendar_ids_falls_back_to_assignments(): void
    {
        $teacher = $this->createTeacher([
            'acuity_calendar_id' => null,
            'teacher_calendar_ids' => null,
        ]);

        TeacherAppointmentTypeAssignment::create([
            'user_id' => $teacher->id,
            'acuity_calendar_id' => 'CAL-FALLBACK',
            'acuity_appointment_type_id' => 'TYPE-Z',
            'appointment_type_name' => 'Fallback Type',
        ]);

        $this->assertSame(['CAL-FALLBACK'], $teacher->fresh()->teacherCalendarIds());
    }

    private function createTeacher(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'teacher',
            'is_active' => true,
            'teacher_scope' => 'assigned_classes',
        ], $overrides));
    }

    private function createSchoolClass(): SchoolClass
    {
        return SchoolClass::create([
            'name' => 'Employability',
            'language' => 'English',
            'location' => 'UK',
            'max_students' => 15,
        ]);
    }

    private function createSession(SchoolClass $class, string $calendarId, string $typeId): ClassSession
    {
        $payload = [
            'calendarID' => $calendarId,
            'appointmentTypeID' => $typeId,
            'appointmentType' => $typeId,
        ];

        return ClassSession::create([
            'school_class_id' => $class->id,
            'session_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'max_students' => 12,
            'location' => 'UK',
            'calendar_name' => $calendarId,
            'calendar_norm' => strtolower($calendarId),
            'acuity_appointment_id' => Str::uuid()->toString(),
            'acuity_data' => $payload,
        ]);
    }
}
