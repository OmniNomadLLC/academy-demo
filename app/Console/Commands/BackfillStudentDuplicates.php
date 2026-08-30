<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentMergeSuggestion;
use Illuminate\Console\Command;

/**
 * Creates StudentMergeSuggestion rows for existing same-name duplicates.
 *
 * The FlagsStudentDuplicates trait only fires on newly-synced Students, so
 * any duplicates that were imported before the trait was wired into the
 * sync jobs are invisible to the dashboard widget. This command scans the
 * full table once and seeds the suggestion queue with every matching pair.
 *
 * Matching rules mirror FlagsStudentDuplicates exactly:
 *   - case-insensitive first_name + last_name match
 *   - excludes soft-deleted rows
 *   - pair keyed on min/max id so (a, b) is stored consistently
 *
 * For a group of N students sharing a name, C(N, 2) pairs are produced.
 */
class BackfillStudentDuplicates extends Command
{
    protected $signature = 'students:backfill-duplicates
        {--dry-run : Preview what would be created without inserting any rows}';

    protected $description = 'Backfill StudentMergeSuggestion rows for existing duplicate students';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Scanning for duplicate students...');

        $groups = Student::query()
            ->whereNull('deleted_at')
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->where('first_name', '!=', '')
            ->where('last_name', '!=', '')
            ->selectRaw('LOWER(first_name) as fn_key, LOWER(last_name) as ln_key, COUNT(*) as n')
            ->groupBy('fn_key', 'ln_key')
            ->having('n', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate name groups found. Nothing to do.');
            return self::SUCCESS;
        }

        $totalStudentsAffected = (int) $groups->sum('n');
        $this->info("Found {$groups->count()} duplicate name groups affecting {$totalStudentsAffected} students.");
        $this->newLine();
        $this->line('Creating suggestions' . ($dryRun ? ' (DRY RUN)' : '') . ':');

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($groups as $group) {
            $ids = Student::query()
                ->whereRaw('LOWER(first_name) = ?', [$group->fn_key])
                ->whereRaw('LOWER(last_name) = ?', [$group->ln_key])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $createdInGroup = 0;
            $skippedInGroup = 0;

            foreach ($this->pairs($ids) as [$aId, $bId]) {
                $exists = StudentMergeSuggestion::where('student_a_id', $aId)
                    ->where('student_b_id', $bId)
                    ->exists();

                if ($exists) {
                    $skippedInGroup++;
                    continue;
                }

                if (! $dryRun) {
                    StudentMergeSuggestion::create([
                        'student_a_id' => $aId,
                        'student_b_id' => $bId,
                        'reason'       => 'same_name',
                        'status'       => 'pending',
                    ]);
                }
                $createdInGroup++;
            }

            $totalCreated += $createdInGroup;
            $totalSkipped += $skippedInGroup;

            $label = trim("{$group->fn_key} {$group->ln_key}");
            $verb  = $dryRun ? 'would be created' : 'created';
            $summary = "{$createdInGroup} suggestion" . ($createdInGroup === 1 ? '' : 's') . " {$verb}";
            if ($skippedInGroup > 0) {
                $summary .= ", {$skippedInGroup} skipped (already exist)";
            }
            $this->line("  ✓ {$label} ({$group->n} students) → {$summary}");
        }

        $this->newLine();
        $this->line('Summary:');
        $this->line("  Groups processed:        {$groups->count()}");
        $this->line('  Suggestions ' . ($dryRun ? 'to create:    ' : 'created:         ') . $totalCreated);
        $this->line("  Skipped (already exist): {$totalSkipped}");

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run — no rows inserted. Rerun without --dry-run to apply.');
        } elseif ($totalCreated > 0) {
            $this->newLine();
            $this->info('Dashboard widget should now show duplicate alerts.');
        }

        return self::SUCCESS;
    }

    /**
     * Yield every unordered pair (a, b) with a < b from a list of IDs.
     *
     * @param  array<int>  $ids
     * @return iterable<array{0:int,1:int}>
     */
    protected function pairs(array $ids): iterable
    {
        sort($ids);
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                yield [$ids[$i], $ids[$j]];
            }
        }
    }
}
