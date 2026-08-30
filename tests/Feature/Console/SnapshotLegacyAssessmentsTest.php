<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SnapshotLegacyAssessments;
use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentItem;
use App\Models\User;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotLegacyAssessmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshots_copy_skill_category_from_questions(): void
    {
        $student = Student::factory()->create();
        $assessor = User::factory()->create();

        $template = AssessmentTemplate::create([
            'name' => 'Snapshot Template',
            'description' => 'Desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $question = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => 'Speaking and Listening Assessment',
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
            'question_text' => 'How are you?',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $assessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $assessor->id,
            'assessed_at' => now(),
            'average_score' => 8,
            'overall_comments' => 'notes',
            'status' => StudentAssessment::STATUS_FINAL,
        ]);

        $this->artisan(SnapshotLegacyAssessments::class)->assertExitCode(0);

        $this->assertDatabaseHas('student_assessment_items', [
            'student_assessment_id' => $assessment->id,
            'template_question_id' => $question->id,
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
        ]);

        $item = StudentAssessmentItem::first();
        $this->assertSame(SkillCategory::SPEAKING_LISTENING, $item->skill_category);
    }
}
