<?php

namespace Tests\Unit\Services;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Models\StudentAssessmentItem;
use App\Models\User;
use App\Services\StudentSkillCircleService;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSkillCircleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_dataset_returns_all_categories_with_zero_scores(): void
    {
        $student = Student::factory()->create();

        $skills = $this->service()->compute($student);

        $this->assertCount(count(SkillCategory::all()), $skills);
        $this->assertTrue($skills->every(fn ($skill) => $skill['score'] === 0.0));
    }

    public function test_single_assessment_populates_relevant_categories(): void
    {
        [$student, $assessment, $question] = $this->makeFinalAssessment('speaking_listening', 8);

        $skills = $this->service()->compute($student);

        $this->assertSame(8.0, $skills->firstWhere('skill', 'speaking_listening')['score']);
        $this->assertSame(0.0, $skills->firstWhere('skill', 'reading_writing')['score']);
    }

    public function test_multiple_assessments_average_scores(): void
    {
        [$student] = $this->makeFinalAssessment('speaking_listening', 8);
        $this->makeFinalAssessment('speaking_listening', 6, $student);

        $skills = $this->service()->compute($student);

        $this->assertSame(7.0, $skills->firstWhere('skill', 'speaking_listening')['score']);
    }

    public function test_missing_skill_category_maps_from_section(): void
    {
        [$student, $assessment, $question] = $this->makeFinalAssessment(null, 9);

        $skills = $this->service()->compute($student);

        $this->assertSame(9.0, $skills->firstWhere('skill', 'speaking_listening')['score']);
    }

    public function test_partial_coverage_still_returns_all_categories(): void
    {
        [$student] = $this->makeFinalAssessment('work_readiness', 5);

        $skills = $this->service()->compute($student);

        $this->assertSame(5.0, $skills->firstWhere('skill', 'work_readiness')['score']);
        $this->assertTrue($skills->firstWhere('skill', 'to_learn')['is_empty']);
    }

    protected function service(): StudentSkillCircleService
    {
        return app(StudentSkillCircleService::class);
    }

    protected function makeFinalAssessment(?string $skillCategory, int $score, ?Student $student = null): array
    {
        $student = $student ?? Student::factory()->create();
        $user = User::factory()->create();
        $template = AssessmentTemplate::create([
            'name' => 'Template',
            'description' => 'desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $question = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => $skillCategory ? SkillCategory::label($skillCategory) : 'General',
            'skill_category' => $skillCategory,
            'question_text' => 'Question',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $assessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now(),
            'average_score' => $score,
            'overall_comments' => 'Notes',
            'status' => StudentAssessment::STATUS_FINAL,
        ]);

        StudentAssessmentAnswer::create([
            'student_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'score' => $score,
            'notes' => null,
        ]);

        StudentAssessmentItem::create([
            'student_assessment_id' => $assessment->id,
            'template_question_id' => $question->id,
            'section_name' => $skillCategory
                ? SkillCategory::label($skillCategory)
                : 'Speaking and Listening Assessment',
            'skill_category' => $skillCategory,
            'question_text' => 'Question',
            'max_score' => 10,
            'sort_order' => 1,
            'weight' => null,
            'template_version' => 1,
        ]);

        return [$student, $assessment, $question];
    }
}
