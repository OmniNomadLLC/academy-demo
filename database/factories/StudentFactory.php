<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        $email = $this->faker->unique()->safeEmail();

        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $email,
            'email_norm' => Str::lower($email),
            'phone' => '+44' . $this->faker->numerify('7#########'),
            'location' => $this->faker->randomElement(['UK', 'Spain', 'France']),
            'acuity_client_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'is_active' => true,
            'notes' => $this->faker->sentence(),
        ];
    }
}
