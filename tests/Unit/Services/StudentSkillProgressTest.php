<?php

namespace Tests\Unit\Services;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Models\StudentAssessmentItem;
use App\Models\User;
use App\Services\StudentSkillProgress;
use App\Support\Assessments\SkillCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSkillProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_chronological_skill_timelines(): void
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $template = AssessmentTemplate::create([
            'name' => 'Timeline Template',
            'description' => 'desc',
            'region' => 'UK',
            'is_active' => true,
        ]);

        $question = AssessmentQuestion::create([
            'assessment_template_id' => $template->id,
            'section' => SkillCategory::label(SkillCategory::TO_LEARN),
            'skill_category' => SkillCategory::TO_LEARN,
            'question_text' => 'How engaged?',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $first = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now()->subDays(10),
            'average_score' => 6,
            'overall_comments' => 'first',
            'status' => StudentAssessment::STATUS_FINAL,
        ]);

        $second = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $user->id,
            'assessed_at' => now()->subDays(2),
            'average_score' => 9,
            'overall_comments' => 'second',
            'status' => StudentAssessment::STATUS_FINAL,
        ]);

        StudentAssessmentAnswer::insert([
            [
                'student_assessment_id' => $first->id,
                'assessment_question_id' => $question->id,
                'score' => 5,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_assessment_id' => $second->id,
                'assessment_question_id' => $question->id,
                'score' => 9,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        StudentAssessmentItem::insert([
            [
                'student_assessment_id' => $first->id,
                'template_question_id' => $question->id,
                'section_name' => SkillCategory::label(SkillCategory::TO_LEARN),
                'skill_category' => SkillCategory::TO_LEARN,
                'question_text' => 'Q',
                'max_score' => 10,
                'sort_order' => 1,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_assessment_id' => $second->id,
                'template_question_id' => $question->id,
                'section_name' => SkillCategory::label(SkillCategory::TO_LEARN),
                'skill_category' => SkillCategory::TO_LEARN,
                'question_text' => 'Q',
                'max_score' => 10,
                'sort_order' => 1,
                'weight' => null,
                'template_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $timeline = app(StudentSkillProgress::class)->build($student);

        $this->assertCount(2, $timeline);
        $this->assertTrue($timeline->pluck('assessment_id')->contains($first->id));
        $lastEntry = $timeline->where('assessment_id', $first->id)->first();
        $firstSkills = collect($lastEntry['skills']);
        $this->assertSame(5.0, $firstSkills->firstWhere('skill', SkillCategory::TO_LEARN)['average']);
    }
}
