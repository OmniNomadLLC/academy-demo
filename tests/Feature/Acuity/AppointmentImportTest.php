<?php

namespace Tests\Feature\Acuity;

use App\Jobs\ProcessAcuityImportRun;
use App\Models\AcuityImportRun;
use App\Models\ClassLocation;
use App\Models\ClassLocationCalendar;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Services\Acuity\AppointmentSliceImporter;
use App\Services\Acuity\AppointmentSliceOptions;
use App\Services\AcuityService;
use App\Support\AcuitySchoolClassSynchronizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppointmentImportTest extends TestCase
{

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        $testingDb = database_path('testing.sqlite');
        if (file_exists($testingDb)) {
            unlink($testingDb);
        }

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $testingDb);

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            // Columns the importer's teacher-resolution query reads; the real
            // schema gained these in 2025_12 but this hand-built test schema
            // was never updated (the suite was broken at the time).
            $table->string('acuity_calendar_id')->nullable();
            $table->json('teacher_calendar_ids')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_norm')->nullable();
            $table->string('acuity_client_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_reason')->nullable();
            $table->foreignId('previous_student_id')->nullable();
            $table->timestamps();
        });

        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('language')->nullable();
            $table->string('level')->nullable();
            $table->string('location')->default('UK');
            $table->integer('duration_minutes')->default(60);
            $table->integer('max_students')->default(10);
            $table->boolean('is_active')->default(true);
            $table->string('external_source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('acuity_appointment_type_id')->nullable();
            $table->timestamps();
            $table->unique(['external_source', 'external_id']);
        });

        Schema::create('class_locations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable();
            $table->string('region')->default('UK');
            $table->boolean('is_virtual')->default(false);
            $table->string('virtual_meeting_url')->nullable();
            $table->string('virtual_meeting_room')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('class_location_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_location_id')->constrained('class_locations')->cascadeOnDelete();
            $table->string('calendar_slug');
            $table->string('calendar_norm')->nullable();
            $table->string('calendar_name')->nullable();
            $table->string('region')->nullable()->default('UK');
            $table->timestamps();
            $table->unique('calendar_slug');
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('acuity_appointment_id')->unique();
            $table->foreignId('student_id')->nullable();
            $table->foreignId('school_class_id')->nullable();
            $table->foreignId('class_location_id')->nullable();
            $table->foreignId('teacher_id')->nullable();
            // Added to the real schema by the 2026-06 per-type teacher
            // resolution fix; the importer writes it on every insert.
            $table->foreignId('assigned_teacher_id')->nullable();
            $table->foreignId('cover_teacher_id')->nullable();
            $table->date('session_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('status')->nullable();
            $table->boolean('canceled')->default(false);
            $table->integer('max_students')->default(10);
            $table->text('notes')->nullable();
            $table->string('location')->nullable();
            $table->string('calendar_name')->nullable();
            $table->string('calendar_norm')->nullable();
            $table->string('category_norm')->nullable();
            $table->string('client_email')->nullable();
            $table->string('student_email')->nullable();
            $table->json('acuity_data')->nullable();
            $table->string('link_status')->nullable();
            $table->string('venue_name')->nullable();
            $table->text('venue_address')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->string('virtual_meeting_url')->nullable();
            $table->string('virtual_meeting_room')->nullable();
            $table->timestamps();
        });

        Schema::create('acuity_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedTinyInteger('slice_days')->default(7);
            $table->unsignedSmallInteger('page_size')->default(100);
            $table->unsignedTinyInteger('max_retries')->default(5);
            $table->unsignedSmallInteger('retry_base_ms')->default(500);
            $table->unsignedInteger('limit')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->boolean('link_after_slice')->default(false);
            $table->unsignedInteger('total_slices')->default(0);
            $table->unsignedInteger('processed_slices')->default(0);
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unlinked_count')->default(0);
            $table->unsignedInteger('matched_email_count')->default(0);
            $table->unsignedInteger('matched_id_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('retries')->default(0);
            $table->timestamp('next_cursor')->nullable();
            $table->timestamp('current_slice_start')->nullable();
            $table->timestamp('current_slice_end')->nullable();
            $table->unsignedInteger('current_slice_index')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('queued_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);

        User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email' => 'admin@example.com',
        ]);

        Student::create([
            'first_name' => 'Test',
            'last_name' => 'Student',
            'email' => 'student@example.com',
            'email_norm' => 'student@example.com',
            'acuity_client_id' => '777',
            'is_active' => true,
        ]);

        $location = ClassLocation::create([
            'name' => 'Riverside Campus',
            'slug' => 'uk-english',
            'address_line_1' => '123 High Street',
            'city' => 'London',
            'postcode' => 'E15 1AA',
            'region' => 'UK',
            'is_virtual' => false,
        ]);

        ClassLocationCalendar::create([
            'class_location_id' => $location->id,
            'calendar_slug' => 'uk-english',
            'calendar_name' => 'UK English',
        ]);

        $this->app->instance(AcuityService::class, new FakeAcuityService([
            [
                [
                    'id' => 123,
                    'datetime' => '2025-01-01T10:00:00Z',
                    'timezone' => 'Europe/London',
                    'duration' => 60,
                    'notes' => 'Lesson',
                    'calendar' => 'UK English',
                    'calendarID' => 42,
                    'type' => 'English Intermediate',
                    'category' => 'UK',
                    'client' => [
                        'id' => 777,
                        'email' => 'student@example.com',
                    ],
                    'email' => 'student@example.com',
                    'canceled' => false,
                ],
            ],
        ]));

        $this->app->bind(AppointmentSliceImporter::class, function ($app) {
            return new AppointmentSliceImporter(
                $app->make(AcuityService::class),
                $app->make(AcuitySchoolClassSynchronizer::class)
            );
        });
    }

    public function test_slice_importer_creates_sessions_and_links_student(): void
    {
        $importer = $this->app->make(AppointmentSliceImporter::class);

        $result = $importer->import(new AppointmentSliceOptions(
            minDate: '2025-01-01',
            maxDate: '2025-01-01',
            pageSize: 100,
            maxRetries: 3,
            retryBaseMs: 200,
            dryRun: false,
            linkAfterSlice: false,
        ));

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->unlinked);
        $this->assertSame(1, $result->matchedByEmail);

        $this->assertSame(0, $result->errors, 'Importer logged unexpected errors');
        $session = ClassSession::first();
        $this->assertNotNull($session);
        $this->assertEquals('student@example.com', $session->student_email);
        $this->assertEquals('linked_by_email', $session->link_status);
        $this->assertEquals('Riverside Campus', $session->venue_name);
        $this->assertEquals('UK', $session->location);
        $this->assertFalse((bool) $session->is_virtual);
    }

    public function test_process_import_run_job_completes_run(): void
    {
        Bus::fake();

        $run = AcuityImportRun::create([
            'status' => AcuityImportRun::STATUS_PENDING,
            'window_start' => '2025-01-01',
            'window_end' => '2025-01-01',
            'slice_days' => 1,
            'page_size' => 100,
            'max_retries' => 3,
            'retry_base_ms' => 200,
            'total_slices' => 1,
            'queued_by' => null,
        ]);

        $job = new ProcessAcuityImportRun($run->id);
        $job->handle($this->app->make(AppointmentSliceImporter::class));

        $run->refresh();
        $this->assertEquals(AcuityImportRun::STATUS_COMPLETED, $run->status);
        $this->assertEquals(1, $run->processed_slices);
        $this->assertEquals(1, $run->fetched_count);
        $this->assertEquals(1, $run->created_count);
        Bus::assertNothingDispatched();
    }
}

class FakeAcuityService extends AcuityService
{
    public function __construct(private array $pages, private int $retries = 0)
    {
    }

    public function setRetryConfig(int $maxRetries, int $retryBaseMs): void
    {
        // no-op
    }

    public function fetchAppointmentsPage(array $params, int $page, int $perPage = 100): array
    {
        return $this->pages[$page - 1] ?? [];
    }

    public function getAndResetRetryCount(): int
    {
        $value = $this->retries;
        $this->retries = 0;
        return $value;
    }
}
