<?php

namespace Database\Seeders;

use App\Models\ClassLocation;
use Illuminate\Database\Seeder;

class ClassLocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locations = [
            ['slug' => 'riverside', 'name' => 'Riverside'],
            ['slug' => 'southbank', 'name' => 'Southbank'],
            ['slug' => 'parkside', 'name' => 'Parkside'],
            ['slug' => 'northgate', 'name' => 'Northgate'],
        ];

        foreach ($locations as $data) {
            ClassLocation::query()->updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
