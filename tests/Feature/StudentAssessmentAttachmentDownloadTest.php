<?php

namespace Tests\Feature;

use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentAssessmentAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_download_attachment(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'role' => 'teacher',
            'access_regions' => ['UK'],
            'access_domains' => ['students'],
        ]);

        $student = Student::factory()->create(['location' => 'UK']);
        $assessment = $this->makeAssessment($student, $user);

        $path = 'assessment-attachments/artifact.pdf';
        Storage::disk('private')->put($path, 'attachment content');

        $assessment->update(['attachment_path' => $path]);

        $response = $this->actingAs($user)->get(route('assessments.attachment.download', $assessment));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('assessment-attachment', (string) $response->headers->get('content-disposition'));
        $this->assertSame('attachment content', $response->streamedContent());
    }

    public function test_user_without_region_access_is_blocked(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'role' => 'teacher',
            'access_regions' => ['Spain'],
            'access_domains' => ['students'],
        ]);

        $student = Student::factory()->create(['location' => 'UK']);
        $assessment = $this->makeAssessment($student, $user);

        $path = 'assessment-attachments/artifact.pdf';
        Storage::disk('private')->put($path, 'attachment content');

        $assessment->update(['attachment_path' => $path]);

        $response = $this->actingAs($user)->get(route('assessments.attachment.download', $assessment));

        $response->assertForbidden();
    }

    protected function makeAssessment(Student $student, ?User $assessor = null): StudentAssessment
    {
        $assessor ??= User::factory()->create([
            'role' => 'admin',
            'access_regions' => ['all'],
            'access_domains' => ['students'],
        ]);

        $template = AssessmentTemplate::create([
            'name' => 'General Review',
            'region' => 'UK',
            'is_active' => true,
        ]);

        return StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_template_id' => $template->id,
            'assessed_by_user_id' => $assessor->id,
            'assessed_at' => now(),
            'average_score' => 7.5,
            'overall_comments' => 'Great progress',
            'status' => StudentAssessment::STATUS_DRAFT,
        ]);
    }
}
