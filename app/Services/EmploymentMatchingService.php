<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Student;
use Illuminate\Support\Collection;
use function collect;

class EmploymentMatchingService
{
    public function score(Student $student, Job $job): int
    {
        $student->loadMissing([
            'activeEmploymentProfile.employmentInterests:id',
            'activeEmploymentProfile.employmentAvailabilityOptions:id',
        ]);
        $job->loadMissing(['interests:id', 'availabilities:id']);

        $profile = $student->activeEmploymentProfile;

        $interestScore = $this->ratioScore(
            $profile?->employmentInterests?->pluck('id') ?? collect(),
            $job->interests->pluck('id')
        );

        $availabilityScore = $this->ratioScore(
            $profile?->employmentAvailabilityOptions?->pluck('id') ?? collect(),
            $job->availabilities->pluck('id')
        );

        $hoursScore = $this->hoursScore(
            $profile?->preferred_hours ?? 'either',
            $job->preferred_hours
        );

        $experienceScore = $this->experienceScore(
            (bool) ($profile?->has_work_experience ?? false),
            (bool) $job->requires_experience
        );

        $score = round(
            ($interestScore * 40) +
            ($availabilityScore * 30) +
            ($hoursScore * 20) +
            ($experienceScore * 10)
        );

        return (int) max(0, min(100, $score));
    }

    public function getTopMatches(Student $student, int $limit = 5): array
    {
        $student->loadMissing([
            'activeEmploymentProfile.employmentInterests:id',
            'activeEmploymentProfile.employmentAvailabilityOptions:id',
        ]);

        $profile = $student->activeEmploymentProfile;

        $studentInterests = $profile?->employmentInterests?->pluck('id') ?? collect();
        $studentAvailability = $profile?->employmentAvailabilityOptions?->pluck('id') ?? collect();
        $studentPreferredHours = $profile?->preferred_hours ?? 'either';
        $studentHasExperience = (bool) ($profile?->has_work_experience ?? false);

        $jobs = Job::query()
            ->with(['interests:id', 'availabilities:id'])
            ->get();

        if ($jobs->isEmpty()) {
            return [];
        }

        return $jobs
            ->map(function (Job $job) use ($student, $studentInterests, $studentAvailability, $studentPreferredHours, $studentHasExperience) {
                return [
                    'job' => $job,
                    'score' => $this->score($student, $job),
                    'reasons' => $this->matchReasons(
                        $job,
                        $studentInterests,
                        $studentAvailability,
                        $studentPreferredHours,
                        $studentHasExperience
                    ),
                ];
            })
            ->sortByDesc('score')
            ->take(max(0, $limit))
            ->values()
            ->all();
    }

    private function matchReasons(
        Job $job,
        Collection $studentInterests,
        Collection $studentAvailability,
        string $studentPreferredHours,
        bool $studentHasExperience
    ): array {
        $reasons = [];

        $jobInterests = $job->interests->pluck('id');
        if ($jobInterests->isNotEmpty() && $studentInterests->intersect($jobInterests)->isNotEmpty()) {
            $reasons[] = 'Matches interests';
        }

        $jobAvailability = $job->availabilities->pluck('id');
        if ($jobAvailability->isNotEmpty() && $studentAvailability->intersect($jobAvailability)->isNotEmpty()) {
            $reasons[] = 'Matches availability';
        }

        if ($job->preferred_hours === 'either' || $job->preferred_hours === $studentPreferredHours) {
            $reasons[] = 'Preferred hours match';
        }

        if (! $job->requires_experience) {
            $reasons[] = 'No experience required';
        }

        return $reasons;
    }

    private function ratioScore(Collection $studentValues, Collection $jobValues): float
    {
        $jobValues = $jobValues->unique()->values();
        if ($jobValues->isEmpty()) {
            return 1.0;
        }

        $studentValues = $studentValues->unique()->values();
        if ($studentValues->isEmpty()) {
            return 0.0;
        }

        $overlap = $studentValues->intersect($jobValues)->count();
        $denominator = max(1, $jobValues->count());

        return $overlap / $denominator;
    }

    private function hoursScore(string $studentPreference, string $jobPreference): float
    {
        if ($jobPreference === 'either') {
            return 1.0;
        }

        return $studentPreference === $jobPreference ? 1.0 : 0.0;
    }

    private function experienceScore(bool $studentHasExperience, bool $jobRequiresExperience): float
    {
        if (! $jobRequiresExperience) {
            return 1.0;
        }

        return $studentHasExperience ? 1.0 : 0.0;
    }
}
