<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentEnrollmentMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentEnrollmentMessage>
 */
class StudentEnrollmentMessageFactory extends Factory
{
    protected $model = StudentEnrollmentMessage::class;

    public function definition(): array
    {
        $channel = $this->faker->randomElement([
            StudentEnrollmentMessage::CHANNEL_WHATSAPP,
            StudentEnrollmentMessage::CHANNEL_SMS,
        ]);

        return [
            'student_id' => Student::factory(),
            'initiated_by_user_id' => User::factory(),
            'channel' => $channel,
            'twilio_sid' => $this->faker->unique()->regexify('SM[a-zA-Z0-9]{32}'),
            'body' => $this->faker->sentence(12),
            'status' => StudentEnrollmentMessage::STATUS_SENT,
            'status_updated_at' => now(),
            'delivered_at' => null,
            'read_at' => null,
            'error_code' => null,
            'error_message' => null,
            'meta' => [],
        ];
    }
}
