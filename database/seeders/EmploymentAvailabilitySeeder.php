<?php

namespace Database\Seeders;

use App\Models\EmploymentAvailabilityOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EmploymentAvailabilitySeeder extends Seeder
{
    /**
     * Canonical availability options mirrored between local/staging/prod.
     * These labels intentionally cover the combinations referenced by both
     * student profiles and job listings.
     */
    private array $options = [
        'Weekdays – mornings (08:00–12:00)',
        'Weekdays – afternoons (12:00–17:00)',
        'Weekdays – evenings (17:00–21:00)',
        'Weekends – daytime',
        'Weekends – evenings',
        'Flexible / any time',
        'Part-time (up to 20h)',
        'Full-time (30h+)',
    ];

    public function run(): void
    {
        foreach ($this->options as $label) {
            EmploymentAvailabilityOption::updateOrCreate(
                ['slug' => Str::slug($label)],
                ['name' => $label]
            );
        }
    }
}
