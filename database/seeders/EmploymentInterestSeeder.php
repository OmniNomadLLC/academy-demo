<?php

namespace Database\Seeders;

use App\Models\EmploymentInterest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EmploymentInterestSeeder extends Seeder
{
    private array $interests = [
        'Cleaning',
        'Retail',
        'Hospitality',
        'Warehouse',
        'Office',
    ];

    public function run(): void
    {
        foreach ($this->interests as $name) {
            EmploymentInterest::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
