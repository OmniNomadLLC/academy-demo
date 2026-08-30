<?php

namespace App\Services\LuminaWorks;

use App\Models\EmploymentProfile;
use App\Models\LuminaWorksJob;
use App\Models\LuminaWorksJobMatch;
use Illuminate\Support\Collection;

class JobMatcher
{
    // Signals in the vacancy text that the role leans on strong (often
    // written) English — used as a coarse gate for low-band students.
    private const HIGH_ENGLISH_SIGNALS = [
        'fluent english', 'excellent communication', 'strong communication',
        'excellent written', 'strong written', 'customer service', 'call handling',
        'telephone manner', 'answering calls', 'reception', 'sales',
    ];

    // Signals the role is realistically doable with little English.
    private const LOW_ENGLISH_FRIENDLY = [
        'warehouse', 'picker', 'packer', 'cleaning', 'cleaner', 'kitchen porter',
        'housekeeping', 'labourer', 'no experience', 'training given', 'training provided',
        'production operative', 'assembly',
    ];

    public function __construct(private EnglishBandResolver $bands)
    {
    }

    /**
     * Stage-1 matching (no LLM): distance + hours + English gate, with an
     * explainable keyword score. Returns number of matches written.
     */
    public function matchProfile(EmploymentProfile $profile, int $keepTop = 10): int
    {
        $student = $profile->student;

        if (!$student || $profile->latitude === null || $profile->longitude === null) {
            return 0;
        }

        $band = $this->bands->resolve($student);
        $maxKm = max(1, (int) $profile->max_travel_km);

        $jobs = $this->jobsNear((float) $profile->latitude, (float) $profile->longitude, $maxKm);

        $written = 0;
        $scored = [];

        foreach ($jobs as $job) {
            $result = $this->score($job, $profile, $band);
            if ($result !== null) {
                $scored[] = $result;
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach (array_slice($scored, 0, $keepTop) as $row) {
            LuminaWorksJobMatch::updateOrCreate(
                ['student_id' => $student->id, 'lumina_works_job_id' => $row['job']->id],
                [
                    'score' => $row['score'],
                    'reason' => $row['reason'],
                    'score_source' => 'keyword',
                    'distance_km' => $row['distance_km'],
                    'english_suitable' => true,
                    'surfaced_at' => now(),
                ]
            );
            $written++;
        }

        return $written;
    }

    /** @return Collection<int, LuminaWorksJob> */
    private function jobsNear(float $lat, float $lng, int $maxKm): Collection
    {
        // Haversine in SQL keeps this a single indexable-ish query; job volume
        // at pilot scale (hundreds of rows) makes this cheap.
        $haversine = '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';

        return LuminaWorksJob::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("lumina_works_jobs.*, {$haversine} as distance_km", [$lat, $lng, $lat])
            ->havingRaw('distance_km <= ?', [$maxKm])
            ->orderBy('distance_km')
            ->limit(200)
            ->get();
    }

    /** @return array{job: LuminaWorksJob, score: int, reason: string, distance_km: float}|null */
    private function score(LuminaWorksJob $job, EmploymentProfile $profile, string $band): ?array
    {
        $text = strtolower($job->title . ' ' . $job->description);
        $demandsHighEnglish = $this->containsAny($text, self::HIGH_ENGLISH_SIGNALS);
        $lowEnglishFriendly = $this->containsAny($text, self::LOW_ENGLISH_FRIENDLY);

        // English gate: low-band students are never matched to roles that
        // clearly lean on strong English.
        if ($band === EnglishBandResolver::BAND_LOW && $demandsHighEnglish) {
            return null;
        }

        $distance = round((float) $job->distance_km, 1);
        $reasons = [];
        $score = 50;

        // Closer is better: 0km => +25, at the edge => +0.
        $proximity = (int) round(25 * max(0, 1 - $distance / max(1, (int) $profile->max_travel_km)));
        $score += $proximity;
        $reasons[] = "{$distance} km away";

        if ($lowEnglishFriendly) {
            $score += 15;
            $reasons[] = 'suitable for limited English';
        } elseif ($demandsHighEnglish) {
            $score -= 10;
            $reasons[] = 'needs stronger English';
        }

        $hours = $profile->preferred_hours;
        if ($hours && $job->contract_time) {
            if ($hours === 'either' || $hours === $job->contract_time) {
                $score += 10;
                $reasons[] = str_replace('_', '-', $job->contract_time) . ' matches preference';
            } else {
                $score -= 5;
            }
        }

        if (!$profile->has_work_experience && str_contains($text, 'experience required')) {
            $score -= 10;
        }

        return [
            'job' => $job,
            'score' => max(1, min(100, $score)),
            'reason' => ucfirst(implode('; ', $reasons)),
            'distance_km' => $distance,
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
