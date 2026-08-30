<?php

namespace App\Services;

use App\Models\StudentAssessment;
use App\Support\Assessments\SkillCategory;
use Illuminate\Support\Collection;

/**
 * Provide a unified read layer for student assessments.
 *
 * Phase 4 prefers immutable snapshot rows when they exist (Phase 2/3 output).
 * Final assessments stay fully snapshot-backed, but drafts overlay the latest
 * template questions so unanswered items remain visible. Legacy templates are
 * still available as a fallback until Phase 5 removes the dependency entirely.
 */
class AssessmentSnapshotReader
{
    public function getItems(StudentAssessment $assessment): Collection
    {
        $assessment->loadMissing([
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'template.questions' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        $items = $assessment->items ?? collect();
        $template = $assessment->template;
        $questions = $template?->questions ?? collect();

        if ($items->isNotEmpty()) {
            if (! $questions->isNotEmpty() || $assessment->isFinal()) {
                return $this->mapSnapshotCollection($items);
            }

            return $this->mergeTemplateWithSnapshots($questions, $items);
        }

        if (! $template) {
            return collect();
        }

        if (! $questions->isNotEmpty()) {
            return collect();
        }

        return $questions->map(fn ($question) => $this->normalizeTemplateQuestion($question));
    }

    protected function mergeTemplateWithSnapshots(Collection $questions, Collection $items): Collection
    {
        $snapshotByQuestion = $items
            ->filter(fn ($item) => ! empty($item->template_question_id))
            ->keyBy(fn ($item) => $item->template_question_id);

        $result = collect();
        $matchedIds = collect();

        foreach ($questions as $question) {
            $snapshot = $snapshotByQuestion->get($question->id);

            if ($snapshot) {
                $result->push($this->normalizeSnapshotItem($snapshot));
                $matchedIds->push($snapshot->getKey());
                continue;
            }

            $result->push($this->normalizeTemplateQuestion($question));
        }

        $unmatchedSnapshots = $items
            ->reject(fn ($item) => $matchedIds->contains($item->getKey()))
            ->sortBy('sort_order');

        foreach ($unmatchedSnapshots as $item) {
            $result->push($this->normalizeSnapshotItem($item));
        }

        return $result;
    }

    protected function mapSnapshotCollection(Collection $items): Collection
    {
        return $items->map(fn ($item) => $this->normalizeSnapshotItem($item));
    }

    protected function normalizeSnapshotItem($item): object
    {
        return (object) [
            'template_question_id' => $item->template_question_id,
            'question_text' => $item->question_text,
            'section_name' => $item->section_name,
            'skill_category' => $item->skill_category,
            'max_score' => $item->max_score,
            'sort_order' => $item->sort_order,
            'weight' => $item->weight,
        ];
    }

    protected function normalizeTemplateQuestion($question): object
    {
        $category = $question->skill_category ?? SkillCategory::default();
        $sectionName = SkillCategory::label($category);

        return (object) [
            'template_question_id' => $question->id,
            'question_text' => $question->question_text,
            'section_name' => $sectionName,
            'skill_category' => $category,
            'max_score' => 10,
            'sort_order' => $question->sort_order,
            'weight' => null,
        ];
    }
}
