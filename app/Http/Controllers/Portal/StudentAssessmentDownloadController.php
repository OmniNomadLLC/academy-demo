<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\User;
use App\Support\Assessments\StudentAssessmentReportBuilder;
use App\Support\TeacherRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StudentAssessmentDownloadController extends Controller
{
    public function __invoke(Request $request, Student $student, StudentAssessment $assessment)
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        abort_if($assessment->student_id !== $student->id, 404);
        abort_unless($this->canAccessStudent($user, $student), 403);

        $builder = new StudentAssessmentReportBuilder();
        $pdf = $builder->build($assessment);

        $filename = sprintf(
            '%s-assessment-%s.pdf',
            Str::slug($student->full_name ?? 'student'),
            $assessment->assessed_at?->timezone(config('app.timezone'))?->format('Ymd_His') ?? $assessment->id,
        );

        return response()->streamDownload(fn () => print($pdf), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    protected function canAccessStudent(User $user, Student $student): bool
    {
        if ($user->hasRole('admin', 'super_admin')) {
            return true;
        }

        $keys = TeacherRoster::studentLookupKeys($user);
        $emailNorm = strtolower(trim((string) ($student->email ?? $student->email_norm ?? '')));
        $clientId = (string) ($student->acuity_client_id ?? '');

        if (in_array($student->id, $keys['ids'] ?? [], true)) {
            return true;
        }

        if ($emailNorm !== '' && in_array($emailNorm, $keys['emails'] ?? [], true)) {
            return true;
        }

        if ($clientId !== '' && in_array($clientId, $keys['client_ids'] ?? [], true)) {
            return true;
        }

        return false;
    }
}

