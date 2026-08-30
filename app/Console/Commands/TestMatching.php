<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Student;
use App\Services\EmploymentMatchingService;
use Illuminate\Console\Command;

class TestMatching extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:matching {studentId?} {jobId?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute a simple match score between a student and a job';

    /**
     * Execute the console command.
     */
    public function handle(EmploymentMatchingService $matchingService): int
    {
        $student = $this->resolveStudent();
        $job = $this->resolveJob();

        if (! $student || ! $job) {
            return self::FAILURE;
        }

        $score = $matchingService->score($student, $job);

        $this->info(sprintf(
            'Student #%d vs Job #%d → Match: %d%%',
            $student->id,
            $job->id,
            $score
        ));

        return self::SUCCESS;
    }

    private function resolveStudent(): ?Student
    {
        $studentId = $this->argument('studentId');
        $student = $studentId ? Student::find($studentId) : Student::query()->first();

        if (! $student) {
            $this->error('Unable to find a student to test against.');
        }

        return $student;
    }

    private function resolveJob(): ?Job
    {
        $jobId = $this->argument('jobId');
        $job = $jobId ? Job::find($jobId) : Job::query()->first();

        if (! $job) {
            $this->error('Unable to find a job to test against.');
        }

        return $job;
    }
}
