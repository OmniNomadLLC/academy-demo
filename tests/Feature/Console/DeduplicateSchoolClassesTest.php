<?php

namespace Tests\Feature\Console;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeduplicateSchoolClassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_merges_duplicates_and_relinks_sessions(): void
    {
        $this->disableUniqueIndexes();

        $keeper = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'signature-1',
            'name' => 'UK English Intermediate',
            'description' => 'Test',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
            'acuity_appointment_type_id' => 'apt-123',
        ]);

        $duplicate = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'signature-1',
            'name' => 'UK English Intermediate',
            'description' => 'Duplicate',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
            'acuity_appointment_type_id' => 'apt-123',
        ]);

        $anotherDuplicate = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'signature-1',
            'name' => 'Copy',
            'description' => 'Duplicate 2',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
            'acuity_appointment_type_id' => 'apt-123',
        ]);

        $unique = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'signature-unique',
            'name' => 'Unique',
            'language' => 'English',
            'level' => 'Advanced',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
        ]);

        ClassSession::create([
            'school_class_id' => $duplicate->id,
            'session_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'canceled' => false,
        ]);

        $this->artisan('school-classes:deduplicate', ['--chunk' => 2])
            ->assertExitCode(0);

        $this->assertDatabaseCount('school_classes', 4);
        $this->assertDatabaseCount('class_sessions', 1);

        $this->artisan('school-classes:deduplicate', ['--chunk' => 2, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('school_classes', 2);
        $this->assertDatabaseHas('school_classes', ['id' => $keeper->id]);
        $this->assertDatabaseHas('school_classes', ['id' => $unique->id]);
        $this->assertDatabaseHas('class_sessions', [
            'school_class_id' => $keeper->id,
        ]);
    }

    public function test_signature_bucket_excludes_row_identifier(): void
    {
        $this->disableUniqueIndexes();

        $keeper = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'hash:abc',
            'name' => 'Evening English',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
        ]);

        SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'hash:abc',
            'name' => 'Evening English',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
        ]);

        SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'hash:abc',
            'name' => 'Evening English',
            'language' => 'English',
            'level' => 'Intermediate',
            'location' => 'UK',
            'duration_minutes' => 60,
            'max_students' => 12,
            'is_active' => true,
        ]);

        $unique = SchoolClass::create([
            'external_source' => 'acuity',
            'external_id' => 'hash:xyz',
            'name' => 'Unique class',
            'language' => 'Spanish',
            'level' => 'Advanced',
            'location' => 'ES',
            'duration_minutes' => 45,
            'max_students' => 8,
            'is_active' => true,
        ]);

        $this->artisan('school-classes:deduplicate', ['--chunk' => 1, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('school_classes', 2);
        $this->assertDatabaseHas('school_classes', ['id' => $keeper->id]);
        $this->assertDatabaseHas('school_classes', ['id' => $unique->id]);
    }

    protected function disableUniqueIndexes(): void
    {
        if (! Schema::hasTable('school_classes')) {
            return;
        }

        $statements = [
            'DROP INDEX IF EXISTS school_classes_external_unique',
            'DROP INDEX IF EXISTS school_classes_external_id_unique',
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                // ignore for drivers that require alternative syntax
            }
        }
    }
}
