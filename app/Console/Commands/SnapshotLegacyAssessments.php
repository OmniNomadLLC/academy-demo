<?php

namespace App\Console\Commands;

use App\Models\StudentAssessment;
use App\Models\StudentAssessmentItem;
use App\Support\Assessments\SkillCategory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SnapshotLegacyAssessments extends Command
{
    protected $signature = 'assessments:snapshot-legacy {--dry-run : Preview counts without writing snapshot records}';

    protected $description = 'Backfill immutable student assessment snapshot items for legacy assessments.';

    protected bool $dryRun = false;

    protected int $processed = 0;

    protected int $skipped = 0;

    protected int $errors = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf('Starting legacy assessment snapshot (%s).', $this->dryRun ? 'dry run' : 'live run'));

        StudentAssessment::query()
            ->with([
                'template.questions' => fn ($query) => $query->orderBy('sort_order'),
                'template.sections',
            ])
            ->orderBy('id')
            ->chunkById(100, function (Collection $assessments) {
                $assessments->each(function (StudentAssessment $assessment) {
                    $this->processAssessment($assessment);
                });

                $this->line(sprintf('Progress → processed: %d, skipped: %d, errors: %d', $this->processed, $this->skipped, $this->errors));
            });

        $this->info(sprintf('Snapshot complete. processed=%d skipped=%d errors=%d', $this->processed, $this->skipped, $this->errors));

        return $this->errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function processAssessment(StudentAssessment $assessment): void
    {
        if ($assessment->items()->exists()) {
            $this->skipped++;
            return;
        }

        $template = $assessment->template;
        if (! $template) {
            $this->warn(sprintf('Assessment %d missing template, skipping.', $assessment->id));
            $this->skipped++;
            return;
        }

        $questions = $template->questions;
        if ($questions->isEmpty()) {
            $this->warn(sprintf('Assessment %d template has no questions, skipping.', $assessment->id));
            $this->skipped++;
            return;
        }

        if ($this->dryRun) {
            $this->processed++;
            return;
        }

        try {
            DB::transaction(function () use ($assessment, $template, $questions) {
                if ($assessment->items()->exists()) {
                    return;
                }

                $sectionLookup = $template->sections
                    ->keyBy(fn ($section) => strtolower(trim($section->name ?? '')));

                $now = now();
                $templateVersion = (int) ($template->current_version ?? 1);

                $records = $questions->map(function ($question, $index) use ($assessment, $sectionLookup, $now, $templateVersion) {
                    $sectionName = SkillCategory::label($question->skill_category ?? SkillCategory::default());
                    $sectionKey = strtolower($sectionName);
                    $sectionId = optional($sectionLookup->get($sectionKey))->id;

                    return [
                        'student_assessment_id' => $assessment->id,
                        'template_section_id' => $sectionId,
                        'template_question_id' => $question->id,
                        'section_name' => $sectionName,
                        'skill_category' => $question->skill_category,
                        'question_text' => $question->question_text,
                        'max_score' => 10,
                        'weight' => null,
                        'sort_order' => $question->sort_order ?? ($index + 1),
                        'template_version' => $templateVersion,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                StudentAssessmentItem::insert($records);
            });

            $this->processed++;
        } catch (\Throwable $exception) {
            $this->errors++;
            $this->error(sprintf('Assessment %d failed: %s', $assessment->id, $exception->getMessage()));
            Log::error('Snapshot legacy assessment failed', [
                'assessment_id' => $assessment->id,
                'exception' => $exception,
            ]);
        }
    }
}
