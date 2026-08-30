<?php

namespace App\Console\Commands;

use App\Models\StudentAssessmentItem;
use Illuminate\Console\Command;

class BackfillSkillCategories extends Command
{
    protected $signature = 'assessments:backfill-skill-categories {--chunk=500 : Number of rows processed per chunk}';

    protected $description = 'Copy skill categories from assessment questions onto snapshot rows that are missing them.';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $backfilled = 0;
        $skipped = 0;

        $this->info(sprintf('Starting skill category backfill (chunk size: %d)', $chunkSize));

        StudentAssessmentItem::query()
            ->whereNull('skill_category')
            ->with('templateQuestion')
            ->chunkById($chunkSize, function ($items) use (&$backfilled, &$skipped) {
                foreach ($items as $item) {
                    $question = $item->templateQuestion;

                    if (! $question || ! $question->skill_category) {
                        $skipped++;
                        continue;
                    }

                    $item->skill_category = $question->skill_category;
                    $item->save();
                    $backfilled++;
                }

                $this->line(sprintf('Processed chunk → backfilled: %d, skipped: %d, total processed: %d', $backfilled, $skipped, $backfilled + $skipped));
            });

        $this->info(sprintf('Backfill complete → backfilled: %d, skipped: %d.', $backfilled, $skipped));

        return self::SUCCESS;
    }
}
