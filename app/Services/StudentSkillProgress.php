<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Collection;

class StudentSkillProgress
{
    public function __construct(
        protected AssessmentSkillAggregator $aggregator,
    ) {
    }

    public function build(Student $student): Collection
    {
        $assessments = $student->assessments()
            ->with(['template.questions', 'answers'])
            ->orderByDesc('assessed_at')
            ->get();

        return $assessments->map(function ($assessment) {
            $skills = $this->aggregator->aggregate($assessment);

            return [
                'assessment_id' => $assessment->id,
                'status' => $assessment->status,
                'assessed_at' => optional($assessment->assessed_at)?->toDateTimeString(),
                'skills' => $skills->map(fn ($row) => [
                    'skill' => $row['skill'],
                    'label' => $row['label'],
                    'average' => $row['score'],
                    'is_empty' => $row['is_empty'] ?? false,
                ])->all(),
            ];
        })->filter(fn ($entry) => collect($entry['skills'])->contains(fn ($skill) => $skill['average'] !== null));
    }
}
