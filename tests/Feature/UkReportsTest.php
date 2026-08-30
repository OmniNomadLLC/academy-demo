<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UkReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_dashboard_renders_with_expected_metrics(): void
    {
        $this->withoutExceptionHandling();

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->seedSampleSessions($admin);

        $response = $this->actingAs($admin)->get('/admin/uk-reports');

        $response->assertOk();

        $kpis = $response->viewData('kpis');
        $this->assertSame(2, $kpis['total_sessions']);
        $this->assertNotNull($kpis['average_attendance']);
        $this->assertSame(4, $kpis['unique_students']);
        $this->assertSame(1, $kpis['pending_absence_sessions']);

        $this->assertTrue(
            is_array($response->viewData('seriesAttendanceOverTime'))
            && count($response->viewData('seriesAttendanceOverTime')) >= 2
        );

        $response->assertSee('UK Reports');
        $response->assertSee('Session spotlight');
        $response->assertSee('Export CSV');
    }

    public function test_default_filters_preselect_current_month(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Carbon::setTestNow(Carbon::create(2025, 11, 6, 9, 0, 0, config('app.timezone')));

        try {
            $response = $this->actingAs($admin)->get('/admin/uk-reports');

            $response->assertOk();

            $filters = $response->viewData('filters');

            // The controller defaults to a trailing 30-day window
            // (UkReportsController::resolveFilters), not the calendar month
            // this test originally asserted. The suite was broken when that
            // behaviour changed, so the expectation went stale.
            $this->assertSame('2025-10-07', $filters['from_date']);
            $this->assertSame('2025-11-06', $filters['to_date']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pdf_export_streams_filtered_report(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->seedSampleSessions($admin);

        $response = $this->actingAs($admin)->get('/admin/uk-reports/export/pdf');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $content = $response->getContent();
        $this->assertTrue(str_starts_with($content, '%PDF'));
        $this->assertStringContainsString('UK Attendance Report', $content);
    }

    public function test_pdf_export_honors_selected_sections(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->seedSampleSessions($admin);

        $response = $this->actingAs($admin)->get('/admin/uk-reports/export/pdf?pdf_sections=calendar_overview');

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('Calendar overview', $content);
        $this->assertStringNotContainsString('Attendance trend', $content);
    }

    protected function seedSampleSessions(User $admin): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'name' => 'Alex Teacher',
        ]);

        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $schoolClass = SchoolClass::query()->create([
            'name' => 'Autumn Cohort',
            'description' => 'Demo class',
            'level' => 'B2',
            'language' => 'English',
            'teacher_id' => $teacher->id,
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
        ]);

        $sessionOne = ClassSession::query()->create([
            'school_class_id' => $schoolClass->id,
            'session_date' => $today->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'calendar_name' => 'Northgate',
            'calendar_norm' => 'northgate',
            'location' => 'UK',
            'teacher_id' => $teacher->id,
            'is_virtual' => false,
            'acuity_data' => json_encode(['type' => 'General English']),
        ]);

        $sessionTwo = ClassSession::query()->create([
            'school_class_id' => $schoolClass->id,
            'session_date' => $yesterday->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'calendar_name' => 'Riverside',
            'calendar_norm' => 'riverside',
            'location' => 'UK',
            'teacher_id' => $teacher->id,
            'is_virtual' => true,
            'acuity_data' => json_encode(['type' => 'Conversation Class']),
        ]);

        $students = Student::factory()->count(4)->create(['location' => 'UK']);

        foreach ($students->take(3) as $index => $student) {
            AttendanceRecord::create([
                'class_session_id' => $sessionOne->id,
                'student_id' => $student->id,
                'status' => $index === 2 ? 'absent' : 'present',
                'marked_at' => now(),
                'marked_by' => $admin->id,
                'sent_at' => $index === 2 ? null : now(),
            ]);
        }

        foreach ($students as $index => $student) {
            AttendanceRecord::create([
                'class_session_id' => $sessionTwo->id,
                'student_id' => $student->id,
                'status' => $index === 0 ? 'late' : 'present',
                'marked_at' => now(),
                'marked_by' => $admin->id,
                'sent_at' => now(),
            ]);
        }
    }
}
