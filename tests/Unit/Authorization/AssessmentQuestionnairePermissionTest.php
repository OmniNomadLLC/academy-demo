<?php

namespace Tests\Unit\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AssessmentQuestionnairePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_cannot_manage_questionnaires(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'access_regions' => ['UK'],
        ]);

        $this->assertFalse(Gate::forUser($teacher)->allows('manageAssessmentQuestionnaires'));
    }

    public function test_admin_in_uk_can_manage_questionnaires(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'access_regions' => ['UK'],
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('manageAssessmentQuestionnaires'));
    }

    public function test_admin_requires_uk_access(): void
    {
        $adminWithoutUk = User::factory()->create([
            'role' => 'admin',
            'access_regions' => ['Spain'],
        ]);

        $this->assertFalse(Gate::forUser($adminWithoutUk)->allows('manageAssessmentQuestionnaires'));

        $adminWithUk = User::factory()->create([
            'role' => 'admin',
            'access_regions' => ['UK'],
        ]);

        $this->assertTrue(Gate::forUser($adminWithUk)->allows('manageAssessmentQuestionnaires'));
    }
}
