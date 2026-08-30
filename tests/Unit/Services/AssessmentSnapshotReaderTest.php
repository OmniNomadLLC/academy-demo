<?php

namespace Tests\Unit\Services;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Models\StudentAssessmentItem;
use App\Models\User;
use App\Services\AssessmentSnapshotReader;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentSnapshotReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_snapshot_items_when_present(): void
    {
        [$assessment, $question] = $this->makeAssessmentWithQuestion();

        StudentAssessmentItem::create([
            'student_assessment_id' => $assessment->id,
            'template_question_id' => $question->id,
            'section_name' => 'Snapshot',
            'skill_category' => SkillCategory::SPEAKING_LISTENING,
            'question_text' => 'Snapshot question',
            'max_score' => 5,
            'sort_order' => 2,
            'weight' => 1,
            'template_version' => 3,
        ]);

        $assessment->update(['status' => StudentAssessment::STATUS_FINAL]);

        $items = $this->reader()->getItems($assessment->fresh());

        $this->assertCount(1, $items);
        $this->assertSame('Snapshot question', $items->first()->question_text);
        $this->assertSame('Snapshot', $items->first()->section_name);
        $this->assertSame(5, $items->first()->max_score);
    }

    public function test_it_falls_back_to_template_questions_when_no_snapshot_exists(): void
    {
        [$assessment, $question] = $this->makeAssessmentWithQuestion(section: ' Speaking ');

        $items = $this->reader()->getItems($assessment->fresh());

        $this->assertCount(1, $items);
        $this->assertSame($question->question_text, $items->first()->question_text);
        $this->assertSame(SkillCategory::label(SkillCategory::SPEAKING_LISTENING), $items->first()->section_name);
        $this->assertSame(10, $items->first()->max_score);
    }

    public function test_it_handles_missing_template_gracefully(): void
    {
        [$assessment] = $this->makeAssessmentWithQuestion();

        $orphanedAssessment = $assessment->fresh();
        $orphanedAssessment->assessment_template_id = 999999;
        $orphanedAssessment->unsetRelation('template');

        $items = $this->reader()->getItems($orphanedAssessment);

        $this->assertCount(0, $items);
    }

    public function test_it_preserves_sorting_of_snapshot_items(): void
    {
        [$assessment, $questionA, $questionB] = $this->makeAssessmentWithQuestionPair();

        StudentAssessmentItem::insert([
            [
                'student_assessment_id' => $assessment->id,
                'template_question_id' => $questionA->id,
                'section_name' => 'Alpha',
                'skill_category' => SkillCategory::SPEAKING_LISTENING,
                'question_text' => 'Second',
                'max_score' => 10,
                'sort_order' => 5,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_assessment_id' => $assessment->id,
                'template_question_id' => $questionB->id,
                'section_name' => 'Alpha',
                'skill_category' => SkillCategory::SPEAKING_LISTENING,
                'question_text' => 'First',
                'max_score' => 10,
                'sort_order' => 1,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $assessment->update(['status' => StudentAssessment::STATUS_FINAL]);

        $items = $this->reader()->getItems($assessment->fresh());

        $this->assertSame(['First', 'Second'], $items->pluck('question_text')->all());
    }

    public function test_legacy_fallback_preserves_section_and_sorting(): void
    {
        [$assessment] = $this->makeAssessmentWithQuestionPair();

        $items = $this->reader()->getItems($assessment->fresh());

        $this->assertSame(['1st Question', '2nd Question'], $items->pluck('question_text')->all());
        $this->assertSame([
            SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
            SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
        ], $items->pluck('section_name')->all());
    }

    public function test_draft_assessment_overlays_template_and_snapshot(): void
    {
        [$assessment, $firstQuestion, $secondQuestion] = $this->makeAssessmentWithQuestionPair();

        StudentAssessmentItem::create([
            'student_assessment_id' => $assessment->id,
            'template_question_id' => $secondQuestion->id,
            'section_name' => 'Snapshot Section',
            'skill_category' => SkillCategory::TO_LEARN,
            'question_text' => 'Snapshot override',
            'max_score' => 9,
            'sort_order' => 2,
            'weight' => null,
            'template_version' => 1,
        ]);

        $items = $this->reader()->getItems($assessment->fresh());

        $this->assertSame(['1st Question', 'Snapshot override'], $items->pluck('question_text')->all());
        $this->assertSame([
            SkillCategory::label(SkillCategory::SPEAKING_LISTENING),
            'Snapshot Section',
        ], $items->pluck('section_name')->all());
    }

    protected function reader(): AssessmentSnapshotReader
    {
        return app(AssessmentSnapshotReader::class);
    }

    protected function makeAssessmentWithQuestion(string $section = 'General', string $questionText = 'Legacy question'): array
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $template = AssessmentTemplate::create([
            'name' => 'Template',
            'description' => 'Desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $question = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => trim($section),
            'skill_category' => SkillCategory::fromSection($section),
            'question_text' => $questionText,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $assessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now(),
            'average_score' => 8,
            'overall_comments' => 'Notes',
            'status' => StudentAssessment::STATUS_DRAFT,
        ]);

        StudentAssessmentAnswer::create([
            'student_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'score' => 8,
            'notes' => null,
        ]);

        return [$assessment, $question];
    }

    protected function makeAssessmentWithQuestionPair(): array
    {
        [$assessment, $firstQuestion] = $this->makeAssessmentWithQuestion(section: 'Speaking', questionText: '1st Question');
        $template = $assessment->template;

        $questionB = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => ' ',
            'skill_category' => SkillCategory::fromSection(' '),
            'question_text' => '2nd Question',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        StudentAssessmentAnswer::create([
            'student_assessment_id' => $assessment->id,
            'assessment_question_id' => $questionB->id,
            'score' => 7,
            'notes' => null,
        ]);

        return [$assessment, $firstQuestion, $questionB];
    }

    protected function withoutForeignKeys(callable $callback): void
    {
        $driver = DB::getDriverName();
        $disable = match ($driver) {
            'mysql', 'mariadb' => 'SET FOREIGN_KEY_CHECKS=0',
            'sqlite' => 'PRAGMA foreign_keys = OFF',
            'pgsql' => 'SET CONSTRAINTS ALL DEFERRED',
            default => null,
        };

        $enable = match ($driver) {
            'mysql', 'mariadb' => 'SET FOREIGN_KEY_CHECKS=1',
            'sqlite' => 'PRAGMA foreign_keys = ON',
            'pgsql' => 'SET CONSTRAINTS ALL IMMEDIATE',
            default => null,
        };

        if ($disable) {
            DB::statement($disable);
        }

        try {
            $callback();
        } finally {
            if ($enable) {
                DB::statement($enable);
            }
        }
    }
}
