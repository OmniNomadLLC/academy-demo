<?php

namespace Database\Seeders;

use App\Models\AcuitySyncLog;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentSkillProgress;
use App\Models\SyncLog;
use App\Models\User;
use Carbon\Carbon;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the public demo with entirely generated data. Every name, email and
 * phone number comes from Faker — nothing in here derives from any real
 * student record. Deterministic (fixed seed) so the daily demo reset always
 * produces the same believable dataset.
 */
class DemoSeeder extends Seeder
{
    protected \Faker\Generator $faker;

    /** Students deliberately kept below the 75% attendance threshold. */
    protected array $atRiskStudentIds = [];

    public function run(): void
    {
        $this->faker = FakerFactory::create('en_GB');
        $this->faker->seed(20260829);

        $this->call([
            ClassLocationSeeder::class,
            AssessmentTemplateSeeder::class,
            EmploymentInterestSeeder::class,
            EmploymentAvailabilitySeeder::class,
            TutorialsSeeder::class,
        ]);

        $admin = $this->seedUsers();
        $teachers = User::query()->where('role', 'teacher')->get();

        $students = $this->seedStudents();
        $classes = $this->seedClasses($teachers);
        $this->seedSessionsAndAttendance($classes, $students, $admin);
        $this->seedSkillProgress($students, $teachers);
        $this->seedSyncHealth($admin);
        $this->backfillAppointmentDates();

        foreach ($students as $student) {
            $student->refresh()->recomputeAttendanceRate();
        }
    }

    protected function seedUsers(): User
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'demo@lumina.academy'],
            [
                'name' => 'Demo Administrator',
                'password' => bcrypt('lumina-demo'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );

        // Public teacher login for the portal showcase; picks up classes via the
        // same round-robin assignment as the other teachers.
        User::query()->updateOrCreate(
            ['email' => 'teacher@lumina.academy'],
            [
                'name' => 'Demo Teacher',
                'password' => bcrypt('lumina-demo'),
                'role' => 'teacher',
                'is_active' => true,
                'acuity_calendar_id' => '9099',
            ]
        );

        $teacherNames = ['Amelia Clarke', 'Daniel Osei', 'Sofia Marchetti', 'Tomás Herrera'];
        foreach ($teacherNames as $i => $name) {
            User::query()->updateOrCreate(
                ['email' => Str::slug($name, '.').'@lumina.academy'],
                [
                    'name' => $name,
                    'password' => bcrypt(Str::random(32)),
                    'role' => 'teacher',
                    'is_active' => true,
                    'acuity_calendar_id' => (string) (9100 + $i),
                ]
            );
        }

        User::query()->updateOrCreate(
            ['email' => 'head@lumina.academy'],
            [
                'name' => 'Priya Nair',
                'password' => bcrypt(Str::random(32)),
                'role' => 'head_teacher',
                'is_active' => true,
            ]
        );

        return $admin;
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    protected function seedStudents()
    {
        $mix = [
            ['location' => 'UK', 'category' => '1. Riverside', 'count' => 34],
            ['location' => 'UK', 'category' => '2. Northgate', 'count' => 26],
            ['location' => 'UK', 'category' => '3. Northgate Online', 'count' => 14],
            ['location' => 'Spain', 'category' => 'English', 'count' => 16],
            ['location' => 'France', 'category' => 'CPF', 'count' => 10],
        ];

        $students = collect();

        foreach ($mix as $group) {
            for ($i = 0; $i < $group['count']; $i++) {
                $first = $this->faker->firstName();
                $last = $this->faker->lastName();
                $email = Str::lower($first.'.'.$last.$this->faker->numberBetween(1, 99)).'@example.test';

                $students->push(Student::query()->create([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'email_norm' => $email,
                    'phone' => '+44'.$this->faker->numerify('7#########'),
                    'location' => $group['location'],
                    'acuity_category' => $group['category'],
                    'acuity_client_id' => (string) $this->faker->unique()->numberBetween(500000, 999999),
                    'registration_date' => $this->faker->dateTimeBetween('-14 months', '-2 months'),
                    'is_active' => true,
                    'notes' => $this->faker->boolean(30)
                        ? 'Prefers '.$this->faker->randomElement(['morning', 'afternoon', 'evening']).' sessions.'
                        : null,
                ]));
            }
        }

        // A handful of deliberately at-risk students so the <75% attendance
        // meter has something real to show.
        $this->atRiskStudentIds = $students->where('location', 'UK')
            ->shuffle()->take(7)->pluck('id')->all();

        return $students;
    }

    /** @return array<int, array{class: SchoolClass, calendar: string, location: string, weekday: int, start: string}> */
    protected function seedClasses($teachers): array
    {
        $defs = [
            ['General English A1', 'A1', 'Riverside', 'UK', 1, '09:30'],
            ['General English A2', 'A2', 'Riverside', 'UK', 2, '11:00'],
            ['General English B1', 'B1', 'Riverside', 'UK', 4, '09:30'],
            ['Conversation & Fluency B1', 'B1', 'Northgate', 'UK', 1, '14:00'],
            ['Workplace English A2', 'A2', 'Northgate', 'UK', 3, '10:00'],
            ['Evening English A1', 'A1', 'Northgate Online', 'UK', 2, '18:30'],
            ['IELTS Preparation B2', 'B2', 'Northgate Online', 'UK', 5, '17:00'],
            ['Spanish for Beginners', 'A1', 'English', 'Spain', 3, '12:00'],
            ['Business English B2', 'B2', 'English', 'Spain', 4, '16:00'],
            ['French CPF English A2', 'A2', 'CPF', 'France', 5, '10:30'],
        ];

        $classes = [];
        foreach ($defs as $i => [$name, $level, $calendar, $location, $weekday, $start]) {
            $teacher = $teachers[$i % $teachers->count()];

            $class = SchoolClass::query()->create([
                'external_source' => 'acuity',
                'external_id' => (string) (77000 + $i),
                'acuity_appointment_type_id' => (string) (77000 + $i),
                'name' => $name,
                'description' => 'Weekly '.strtolower($name).' group class.',
                'level' => $level,
                'language' => str_contains($name, 'Spanish') ? 'Spanish' : 'English',
                'teacher_id' => $teacher->id,
                'max_students' => 12,
                'location' => $location,
                'duration_minutes' => 90,
                'is_active' => true,
            ]);

            $classes[] = [
                'class' => $class,
                'calendar' => $calendar,
                'location' => $location,
                'weekday' => $weekday,
                'start' => $start,
            ];
        }

        return $classes;
    }

    protected function seedSessionsAndAttendance(array $classes, $students, User $admin): void
    {
        $today = Carbon::today();
        $appointmentId = 5_000_000;

        foreach ($classes as $def) {
            $class = $def['class'];

            $cohort = $students
                ->where('location', $def['location'])
                ->shuffle()
                ->take($this->faker->numberBetween(6, 10))
                ->values();

            if ($cohort->isEmpty()) {
                continue;
            }

            // 9 weeks back, 3 weeks forward, one session per week.
            for ($week = -9; $week <= 3; $week++) {
                $date = $today->copy()->startOfWeek()->addWeeks($week)->addDays($def['weekday'] - 1);
                if ($date->isWeekend()) {
                    continue;
                }

                $isPast = $date->lt($today);
                [$h, $m] = explode(':', $def['start']);
                $end = sprintf('%02d:%02d:00', (int) $h + 1, (int) $m + 30 >= 60 ? ((int) $m + 30) % 60 : (int) $m + 30);

                foreach ($cohort as $student) {
                    $session = ClassSession::query()->create([
                        'school_class_id' => $class->id,
                        'teacher_id' => $class->teacher_id,
                        'assigned_teacher_id' => $class->teacher_id,
                        'student_id' => $student->id,
                        'acuity_appointment_id' => (string) $appointmentId++,
                        'session_date' => $date->toDateString(),
                        'start_time' => $def['start'].':00',
                        'end_time' => $end,
                        'status' => $isPast ? 'completed' : 'scheduled',
                        'canceled' => false,
                        'max_students' => 12,
                        'location' => $def['location'],
                        'calendar_name' => $def['calendar'],
                        'calendar_norm' => Str::lower($def['calendar']),
                        'category_norm' => Str::lower($def['location']),
                        'student_email' => $student->email,
                        'client_email' => $student->email,
                        'link_status' => 'linked_by_email',
                        'is_virtual' => str_contains($def['calendar'], 'Online'),
                    ]);

                    if ($isPast) {
                        $atRisk = in_array($student->id, $this->atRiskStudentIds, true);
                        $roll = $this->faker->numberBetween(1, 100);

                        $status = $atRisk
                            ? ($roll <= 55 ? 'present' : ($roll <= 65 ? 'late' : 'absent'))
                            : ($roll <= 86 ? 'present' : ($roll <= 93 ? 'late' : 'absent'));

                        AttendanceRecord::query()->create([
                            'class_session_id' => $session->id,
                            'student_id' => $student->id,
                            'status' => $status,
                            'marked_at' => $date->copy()->setTimeFromTimeString($def['start'])->addMinutes(15),
                            'marked_by' => $admin->id,
                            'sent_at' => $status === 'absent' && $this->faker->boolean(70) ? now() : null,
                        ]);
                    }
                }
            }
        }
    }

    protected function seedSkillProgress($students, $teachers): void
    {
        foreach ($students as $student) {
            $write = $this->faker->numberBetween(0, 2);
            $read = $this->faker->numberBetween(0, 2);
            $speak = $this->faker->numberBetween(0, 2);

            $entries = $this->faker->numberBetween(2, 4);
            for ($i = $entries; $i >= 1; $i--) {
                StudentSkillProgress::query()->create([
                    'student_id' => $student->id,
                    'writing' => min(5, $write + ($entries - $i)),
                    'reading' => min(5, $read + ($entries - $i)),
                    'speaking' => min(5, $speak + ($entries - $i)),
                    'recorded_at' => Carbon::today()->subWeeks($i * 4)->subDays($this->faker->numberBetween(0, 6)),
                    'created_by' => $teachers->random()->id,
                ]);
            }
        }
    }

    /**
     * Mirror what students:backfill-first-last / -next-appointment produce so
     * the Data Health widget shows a populated dataset instead of 0%.
     */
    protected function backfillAppointmentDates(): void
    {
        $today = Carbon::today()->toDateString();

        $rows = ClassSession::query()
            ->selectRaw(
                'student_id,'
                .' MIN(session_date) as first_date,'
                .' MAX(CASE WHEN session_date < ? THEN session_date END) as last_date,'
                .' MIN(CASE WHEN session_date >= ? THEN session_date END) as next_date',
                [$today, $today]
            )
            ->whereNotNull('student_id')
            ->groupBy('student_id')
            ->get();

        $activeCutoff = Carbon::today()->subDays(90)->toDateString();

        foreach ($rows as $row) {
            Student::query()->whereKey($row->student_id)->update([
                'first_appointment_date' => $row->first_date,
                'last_appointment_date' => $row->last_date,
                'next_appointment_date' => $row->next_date,
                'is_active_recent' => $row->last_date !== null && $row->last_date >= $activeCutoff,
            ]);
        }
    }

    protected function seedSyncHealth(User $admin): void
    {
        // A believable trail for the Control Panel: a scheduled pull every two
        // hours over the past week, with one transient failure mid-week.
        $cursor = Carbon::now()->subDays(7)->startOfHour();

        while ($cursor->lt(Carbon::now())) {
            $failed = $cursor->diffInHours(Carbon::now()) === 80;
            $processed = $this->faker->numberBetween(40, 160);

            $log = AcuitySyncLog::query()->create([
                'sync_type' => $this->faker->randomElement(['appointments', 'appointments', 'clients']),
                'started_at' => $cursor,
                'completed_at' => $cursor->copy()->addSeconds($this->faker->numberBetween(20, 140)),
                'status' => $failed ? 'failed' : 'completed',
                'records_processed' => $failed ? 0 : $processed,
                'records_created' => $failed ? 0 : (int) floor($processed / 20),
                'records_updated' => $failed ? 0 : (int) floor($processed / 3),
                'error_message' => $failed ? 'Upstream API timeout after 3 retries (simulated).' : null,
            ]);

            // Backdate the row itself so "latest" reflects the trail, not seed time.
            $log->forceFill(['created_at' => $cursor, 'updated_at' => $cursor])->saveQuietly();

            $cursor->addHours(2);
        }

        // Command runs for the Recent Sync Logs table (a different model than
        // the scheduled trail above): a few successes plus one surfaced error.
        $commandRuns = [
            [3, 'acuity:sync-clients', ['--limit' => 0, '--page' => 100], 'success', "Fetched 100 clients (3 created, 31 updated).\nDone in 41s."],
            [3, 'acuity:sync-appointments', ['--from' => Carbon::now()->subDays(10)->toDateString(), '--to' => Carbon::now()->subDays(3)->toDateString(), '--slice' => 7], 'success', "Slices queued: 1. Sessions created: 62, updated: 118.\nDone in 74s."],
            [2, 'acuity:sync-appointments', ['--from' => Carbon::now()->subDays(3)->toDateString(), '--to' => Carbon::now()->toDateString(), '--slice' => 7], 'error', "Upstream API timeout after 3 retries (simulated).\nRe-run this window once the API recovers."],
            [2, 'students:backfill-next-appointment', ['--chunk' => 500, '--horizon' => 365], 'success', "Students scanned: 100. next_appointment set on 74.\nDone in 6s."],
            [1, 'students:update-active-flag', ['--days' => 90], 'success', "Students scanned: 100. Active: 91, inactive: 9.\nDone in 2s."],
        ];

        foreach ($commandRuns as [$daysAgo, $command, $params, $status, $output]) {
            $when = Carbon::now()->subDays($daysAgo)->setTime(9, $this->faker->numberBetween(0, 55));

            $log = SyncLog::query()->create([
                'command' => $command,
                'params' => $params,
                'status' => $status,
                'output' => $output,
                'ran_by' => $admin->id,
            ]);

            $log->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
        }
    }
}
