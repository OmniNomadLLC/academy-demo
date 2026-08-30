<?php

namespace Tests\Unit;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentUpsertTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function upsert_by_acuity_id_is_idempotent(): void
    {
        // First insert with ID and mixed-case email
        $s1 = Student::updateOrCreate(
            ['acuity_client_id' => '12345'],
            ['first_name' => 'Ana', 'last_name' => 'Doe', 'email' => 'Ana.Doe@Example.COM']
        );

        // Second upsert with same ID, different email case should update not duplicate
        $s2 = Student::updateOrCreate(
            ['acuity_client_id' => '12345'],
            ['first_name' => 'Ana', 'last_name' => 'Doe', 'email' => 'ana.doe@example.com']
        );

        $this->assertEquals($s1->id, $s2->id);
        $this->assertEquals(1, Student::count());
    }

    /** @test */
    public function upsert_by_email_fallback_is_idempotent(): void
    {
        $email = 'User+Alias@Example.com';
        $emailNorm = strtolower(trim($email));

        // First record without acuity id
        $a = Student::updateOrCreate(
            ['email_norm' => $emailNorm],
            ['first_name' => 'User', 'last_name' => 'One', 'email' => $email]
        );

        // Try again with different case and no acuity id => should match same row
        $b = Student::updateOrCreate(
            ['email_norm' => strtolower('USER+ALIAS@example.COM')],
            ['first_name' => 'User', 'last_name' => 'One', 'email' => 'USER+ALIAS@example.COM']
        );

        $this->assertEquals($a->id, $b->id);
        $this->assertEquals(1, Student::count());
    }
}

