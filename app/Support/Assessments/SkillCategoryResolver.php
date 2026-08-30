<?php

namespace App\Support\Assessments;

use App\Models\AssessmentQuestion;

class SkillCategoryResolver
{
    public function resolveFromQuestion(?AssessmentQuestion $question): string
    {
        if (! $question) {
            return SkillCategory::default();
        }

        $raw = $question->getAttribute('skill_category');
        $normalized = $this->normalize($raw);
        if ($normalized) {
            return $normalized;
        }

        return SkillCategory::fromSection($question->section);
    }

    public function resolveFromSection(?string $section): string
    {
        return SkillCategory::fromSection($section);
    }

    public function normalize(?string $category): ?string
    {
        $normalized = strtolower(trim((string) $category));

        foreach (SkillCategory::all() as $option) {
            if ($normalized === $option) {
                return $option;
            }
        }

        return null;
    }
}
