<?php

namespace App\Services;

use App\Models\StudentAssessment;
use App\Support\Assessments\SkillCategory;
use App\Support\Assessments\SkillCategoryResolver;
use Illuminate\Support\Collection;

class AssessmentSkillAggregator
{
    public function __construct(
        protected AssessmentSnapshotReader $snapshotReader,
        protected SkillCategoryResolver $categoryResolver,
    ) {
    }

    public function aggregate(StudentAssessment $assessment): Collection
    {
        $assessment->loadMissing(['answers', 'template.questions']);

        $items = $this->snapshotReader->getItems($assessment);
        $answerMap = $assessment->answers->keyBy('assessment_question_id');
        $questionCategoryMap = $assessment->template?->questions
            ?->mapWithKeys(fn ($question) => [$question->id => $question->skill_category])
            ?? collect();

        $buckets = collect();

        foreach ($items as $item) {
            $questionId = $item->template_question_id;
            $answer = $questionId ? $answerMap->get($questionId) : null;
            $score = $answer?->score;

            if ($score === null) {
                continue;
            }

            $skill = $item->skill_category ?? null;

            if (! $skill && $questionId) {
                $skill = $questionCategoryMap[$questionId] ?? null;
            }

            if (! $skill) {
                $skill = $this->categoryResolver->resolveFromSection($item->section_name);
            }

            $bucket = $buckets->get($skill, ['scores' => [], 'max_scores' => []]);
            $bucket['scores'][] = $score;
            $bucket['max_scores'][] = $item->max_score ?: 10;
            $buckets[$skill] = $bucket;
        }

        return collect(SkillCategory::all())->map(function (string $skill) use ($buckets) {
            $bucket = $buckets[$skill] ?? ['scores' => []];
            $scores = $bucket['scores'];
            $average = null;

            if (count($scores)) {
                $average = round(array_sum($scores) / count($scores), 2);
            }

            return [
                'skill' => $skill,
                'label' => SkillCategory::label($skill),
                'score' => $average ?? 0,
                'count' => count($scores),
                'is_empty' => $average === null,
            ];
        });
    }
}
