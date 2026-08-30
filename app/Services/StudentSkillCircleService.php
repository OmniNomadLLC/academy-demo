<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentAssessment;
use App\Support\Assessments\SkillCategory;
use App\Support\Assessments\SkillCategoryResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StudentSkillCircleService
{
    public function __construct(protected SkillCategoryResolver $categoryResolver)
    {
    }

    public function compute(Student $student): Collection
    {
        $assessments = $student->assessments()
            ->where('status', StudentAssessment::STATUS_FINAL)
            ->with(['items', 'answers'])
            ->get();

        if (config('app.debug')) {
            Log::debug('skill_circles.assessment_summary', [
                'student_id' => $student->id,
                'final_assessments' => $assessments->count(),
                'snapshot_items_per_assessment' => $assessments->map(fn ($assessment) => [
                    'assessment_id' => $assessment->id,
                    'items' => $assessment->items->count(),
                ])->all(),
            ]);
        }

        $buckets = collect(SkillCategory::all())
            ->mapWithKeys(fn ($skill) => [$skill => ['sum' => 0, 'count' => 0]]);

        foreach ($assessments as $assessment) {
            $answers = $assessment->answers->keyBy('assessment_question_id');

            foreach ($assessment->items as $item) {
                $skill = $item->skill_category;
                if (! $skill && $item->templateQuestion?->skill_category) {
                    $skill = $item->templateQuestion->skill_category;
                }
                if (! $skill && $item->section_name) {
                    $skill = $this->categoryResolver->resolveFromSection($item->section_name);
                }
                if (! $skill || ! $buckets->has($skill)) {
                    continue;
                }

                $questionId = $item->template_question_id;
                if (! $questionId) {
                    continue;
                }

                $answer = $answers->get($questionId);
                $score = $answer?->score;

                if ($score === null) {
                    continue;
                }

                $bucket = $buckets[$skill];
                $bucket['sum'] += (float) $score;
                $bucket['count']++;
                $buckets[$skill] = $bucket;
            }
        }

        $results = $buckets->map(function (array $bucket, string $skill) {
            $average = $bucket['count'] > 0
                ? round($bucket['sum'] / $bucket['count'], 2)
                : 0.0;

            return [
                'skill' => $skill,
                'label' => SkillCategory::label($skill),
                'score' => $average,
                'count' => $bucket['count'],
                'is_empty' => $bucket['count'] === 0,
            ];
        })->values();

        if (config('app.debug')) {
            Log::debug('skill_circles.results', [
                'student_id' => $student->id,
                'results' => $results->all(),
            ]);
        }

        return $results;
    }
}
