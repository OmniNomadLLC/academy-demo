<?php

namespace App\Services;

use App\Models\StudentAssessment;
use App\Models\StudentAssessmentItem;
use App\Support\Assessments\SkillCategory;
use Illuminate\Support\Facades\DB;

class AssessmentSnapshotWriter
{
    public function snapshot(StudentAssessment $assessment): void
    {
        if (! $assessment->isFinal()) {
            return;
        }

        $assessment->loadMissing([
            'items',
            'template.questions' => fn ($query) => $query->orderBy('sort_order'),
            'template.sections',
        ]);

        if ($assessment->items()->exists()) {
            return;
        }

        $template = $assessment->template;
        $questions = $template?->questions ?? collect();

        if (! $template || $questions->isEmpty()) {
            return;
        }

        $sectionLookup = $template->sections
            ->keyBy(fn ($section) => strtolower(trim($section->name ?? '')));

        $now = now();
        $templateVersion = (int) ($template->current_version ?? 1);

        $records = $questions->values()->map(function ($question, int $index) use ($assessment, $sectionLookup, $templateVersion, $now) {
            $category = $question->skill_category ?? SkillCategory::default();
            $sectionName = trim((string) ($question->section ?: SkillCategory::label($category))) ?: SkillCategory::label($category);
            $sectionKey = strtolower($sectionName);
            $sectionId = optional($sectionLookup->get($sectionKey))->id;

            return [
                'student_assessment_id' => $assessment->id,
                'template_section_id' => $sectionId,
                'template_question_id' => $question->id,
                'section_name' => $sectionName,
                'skill_category' => $category,
                'question_text' => $question->question_text,
                'max_score' => 10,
                'weight' => null,
                'sort_order' => $question->sort_order ?? ($index + 1),
                'template_version' => $templateVersion,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        DB::transaction(function () use ($records) {
            StudentAssessmentItem::insert($records);
        });
    }
}
