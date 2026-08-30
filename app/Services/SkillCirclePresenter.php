<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SkillCirclePresenter
{
    public function present(Collection $skills): array
    {
        return $skills->map(function (array $skill) {
            $score = $skill['score'] ?? 0;
            $percent = (int) round(($score / 10) * 100);

            return [
                'skill' => $skill['skill'],
                'label' => $skill['label'],
                'score' => $score,
                'percentage' => $percent,
                'is_empty' => (bool) ($skill['is_empty'] ?? false),
            ];
        })->all();
    }
}
