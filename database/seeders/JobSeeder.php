<?php

namespace Database\Seeders;

use App\Models\EmploymentAvailabilityOption;
use App\Models\EmploymentInterest;
use App\Models\Job;
use Illuminate\Database\Seeder;
use function collect;

class JobSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $interestLookup = EmploymentInterest::query()->pluck('id', 'slug');
        $availabilityLookup = EmploymentAvailabilityOption::query()->pluck('id', 'slug');

        $jobs = [
            [
                'title' => 'Retail Associate',
                'preferred_hours' => 'part_time',
                'requires_experience' => false,
                'interests' => ['retail', 'hospitality'],
                'availability' => ['weekend', 'evening'],
            ],
            [
                'title' => 'Warehouse Operative',
                'preferred_hours' => 'full_time',
                'requires_experience' => true,
                'interests' => ['warehouse'],
                'availability' => ['morning', 'flexible'],
            ],
            [
                'title' => 'Customer Support Assistant',
                'preferred_hours' => 'either',
                'requires_experience' => false,
                'interests' => ['office'],
                'availability' => ['morning', 'afternoon'],
            ],
        ];

        foreach ($jobs as $jobData) {
            $job = Job::updateOrCreate(
                ['title' => $jobData['title']],
                [
                    'preferred_hours' => $jobData['preferred_hours'],
                    'requires_experience' => $jobData['requires_experience'],
                ]
            );

            $job->interests()->sync(
                collect($jobData['interests'])
                    ->map(fn ($slug) => $interestLookup[$slug] ?? null)
                    ->filter()
                    ->all()
            );

            $job->availabilities()->sync(
                collect($jobData['availability'])
                    ->map(fn ($slug) => $availabilityLookup[$slug] ?? null)
                    ->filter()
                    ->all()
            );
        }
    }
}
