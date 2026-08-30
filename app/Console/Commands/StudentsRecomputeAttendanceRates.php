<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class StudentsRecomputeAttendanceRates extends Command
{
    protected $signature = 'students:recompute-attendance-rates
        {--suppress-alerts : Do not fire <75% email alerts during recompute}
        {--chunk=100 : Chunk size for the student iteration}';

    protected $description = 'Re-run Student::recomputeAttendanceRate() for every non-deleted student (use after changing the rate formula)';

    public function handle(): int
    {
        $suppress = (bool) $this->option('suppress-alerts');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($suppress) {
            Student::$suppressLowRateAlerts = true;
            $this->warn('Low-attendance email alerts SUPPRESSED for this run.');
        }

        $total = Student::query()->whereNull('deleted_at')->count();
        $this->info("Recomputing attendance rates for {$total} students (chunk={$chunk})...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $errors = 0;

        Student::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById($chunk, function ($students) use ($bar, &$processed, &$errors) {
                foreach ($students as $student) {
                    try {
                        $student->recomputeAttendanceRate();
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->error("Student {$student->id}: ".$e->getMessage());
                    }
                    $processed++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Processed: {$processed}. Errors: {$errors}.");

        Student::$suppressLowRateAlerts = false;

        return self::SUCCESS;
    }
}
