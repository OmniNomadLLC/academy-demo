<?php

namespace Tests\Feature;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Models\User;
use App\Services\AssessmentSnapshotWriter;
use App\Services\StudentSkillCircleService;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSkillPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_assessment_surfaces_in_skill_circles(): void
    {
        $student = Student::factory()->create();
        $assessor = User::factory()->create();

        $template = AssessmentTemplate::create([
            'name' => 'Pipeline Template',
            'description' => 'desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $question = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => 'Speaking and Listening Assessment',
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
            'question_text' => 'Introduce yourself',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $assessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $assessor->id,
            'assessed_at' => now(),
            'average_score' => 7.5,
            'overall_comments' => null,
            'status' => StudentAssessment::STATUS_FINAL,
        ]);

        StudentAssessmentAnswer::create([
            'student_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'score' => 8,
            'notes' => null,
        ]);

        app(AssessmentSnapshotWriter::class)->snapshot($assessment);

        $service = app(StudentSkillCircleService::class);
        $skills = $service->compute($student->fresh());

        $this->assertSame(8.0, $skills->firstWhere('skill', SkillCategory::SPEAKING_LISTENING)['score']);
        $this->assertTrue($skills->firstWhere('skill', SkillCategory::READING_WRITING)['is_empty']);
    }
}
