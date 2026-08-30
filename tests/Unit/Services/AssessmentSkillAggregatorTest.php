<?php

namespace Tests\Unit\Services;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Models\StudentAssessmentItem;
use App\Models\User;
use App\Services\AssessmentSkillAggregator;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentSkillAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_averages_scores_per_skill_for_final_assessment(): void
    {
        [$assessment, $questionA, $questionB] = $this->makeAssessmentWithQuestions();

        $assessment->update(['status' => StudentAssessment::STATUS_FINAL]);

        StudentAssessmentItem::insert([
            [
                'student_assessment_id' => $assessment->id,
                'template_question_id' => $questionA->id,
                'section_name' => SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
                'skill_category' => SkillCategory::SPEAKING_LISTENING,
                'question_text' => 'Q1',
                'max_score' => 10,
                'sort_order' => 1,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_assessment_id' => $assessment->id,
                'template_question_id' => $questionB->id,
                'section_name' => SkillCategory::label(SkillCategory::READING_WRITING),
                'skill_category' => SkillCategory::READING_WRITING,
                'question_text' => 'Q2',
                'max_score' => 10,
                'sort_order' => 2,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $aggregates = $this->aggregator()->aggregate($assessment->fresh());

        $this->assertSame(8.0, $aggregates->firstWhere('skill', 'speaking_listening')['score']);
        $this->assertSame(6.0, $aggregates->firstWhere('skill', 'reading_writing')['score']);
    }

    public function test_draft_assessment_merges_template_and_snapshots(): void
    {
        [$assessment, $questionA, $questionB] = $this->makeAssessmentWithQuestions();

        StudentAssessmentItem::create([
            'student_assessment_id' => $assessment->id,
            'template_question_id' => $questionA->id,
            'section_name' => SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
            'question_text' => 'Q1 snapshot',
            'max_score' => 10,
            'sort_order' => 1,
            'weight' => null,
            'template_version' => 1,
        ]);

        $aggregates = $this->aggregator()->aggregate($assessment->fresh());

        $this->assertSame(8.0, $aggregates->firstWhere('skill', 'speaking_listening')['score']);
        $this->assertSame(6.0, $aggregates->firstWhere('skill', 'reading_writing')['score']);
    }

    protected function aggregator(): AssessmentSkillAggregator
    {
        return app(AssessmentSkillAggregator::class);
    }

    protected function makeAssessmentWithQuestions(): array
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();

        $template = AssessmentTemplate::create([
            'name' => 'Skill Template',
            'description' => 'Assessments',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $questionA = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
            'question_text' => 'Q1',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $questionB = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => SkillCategory::label(SkillCategory::READING_WRITING),
            'skill_category' => SkillCategory::READING_WRITING,
            'question_text' => 'Q2',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $assessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now(),
            'average_score' => 7,
            'overall_comments' => 'notes',
            'status' => StudentAssessment::STATUS_DRAFT,
        ]);

        StudentAssessmentAnswer::insert([
            [
                'student_assessment_id' => $assessment->id,
                'assessment_question_id' => $questionA->id,
                'score' => 8,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_assessment_id' => $assessment->id,
                'assessment_question_id' => $questionB->id,
                'score' => 6,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [$assessment, $questionA, $questionB];
    }
}
