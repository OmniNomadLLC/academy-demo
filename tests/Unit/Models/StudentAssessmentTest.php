<?php

namespace Tests\Unit\Models;

use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\AssessmentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_status_is_normalized_to_final(): void
    {
        $assessment = $this->makeAssessment(['status' => 'completed']);

        $this->assertSame(StudentAssessment::STATUS_FINAL, $assessment->status);
    }

    public function test_unknown_status_defaults_to_draft(): void
    {
        $assessment = $this->makeAssessment(['status' => 'unknown']);

        $this->assertSame(StudentAssessment::STATUS_DRAFT, $assessment->status);
    }

    protected function makeAssessment(array $overrides = []): StudentAssessment
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $template = AssessmentTemplate::create([
            'name' => 'Status Template',
            'description' => 'desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        return StudentAssessment::create(array_merge([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now(),
            'average_score' => null,
            'overall_comments' => null,
        ], $overrides));
    }
}
