<?php

namespace App\Console\Commands;

use App\Models\AssessmentQuestion;
use App\Support\Assessments\SkillCategory;
use Illuminate\Console\Command;

class NormalizeTemplateSkillCategories extends Command
{
    protected $signature = 'assessments:normalize-template-skill-categories {--chunk=500 : Number of rows processed per chunk}';

    protected $description = 'Normalize legacy assessment question categories to the canonical skill category slugs.';

    protected array $mapping = [
        'speaking & listening' => SkillCategory::SPEAKING_LISTENING,
        'speaking and listening' => SkillCategory::SPEAKING_LISTENING,
        'to learn' => SkillCategory::TO_LEARN,
        'work readiness' => SkillCategory::WORK_READINESS,
    ];

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $updated = 0;
        $skipped = 0;

        $this->info(sprintf('Normalizing template question categories (chunk size: %d)', $chunkSize));

        AssessmentQuestion::query()
            ->where(function ($query) {
                $query->whereNull('skill_category');

                foreach ($this->mapping as $legacy => $slug) {
                    $query->orWhere('skill_category', $legacy);
                }
            })
            ->chunkById($chunkSize, function ($questions) use (&$updated, &$skipped) {
                foreach ($questions as $question) {
                    $original = $question->skill_category;
                    $normalized = $this->normalizeValue($original);

                    if (! $normalized) {
                        $skipped++;
                        continue;
                    }

                    if ($question->skill_category === $normalized) {
                        continue;
                    }

                    $question->skill_category = $normalized;
                    $question->section = SkillCategory::label($normalized);
                    $question->save();
                    $updated++;
                }

                $this->line(sprintf('Processed chunk → updated: %d, skipped: %d', $updated, $skipped));
            });

    	AssessmentQuestion::whereNull('skill_category')
            ->update(['skill_category' => SkillCategory::SPEAKING_LISTENING]);

        $this->info(sprintf('Normalization complete → updated: %d, skipped: %d.', $updated, $skipped));

        return self::SUCCESS;
    }

    protected function normalizeValue(?string $value): ?string
    {
        if (! $value) {
            return SkillCategory::SPEAKING_LISTENING;
        }

        $key = strtolower(trim($value));

        return $this->mapping[$key] ?? $value;
    }
}
