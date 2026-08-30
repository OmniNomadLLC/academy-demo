<?php

namespace App\Http\Controllers;

use App\Models\StudentAssessment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StudentAssessmentAttachmentDownloadController extends Controller
{
    public function __invoke(StudentAssessment $assessment)
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $assessment->loadMissing('student');
        $student = $assessment->student;

        abort_if(! $assessment || ! $student, 404);
        abort_unless($user->canViewAssessments(), 403);
        abort_unless($user->can('view', $student), 403);

        $path = $assessment->attachment_path;
        abort_if(empty($path), 404);

        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);

        $filename = sprintf(
            '%s-assessment-attachment-%s%s',
            str($student->full_name ?? $student->id)->slug('-') ?: 'student',
            $assessment->id,
            $this->guessExtensionFromPath($path),
        );

        Log::info('assessment_attachment.preview', [
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'path' => $path,
            'exists' => true,
        ]);

        return $disk->response($path, $filename);
    }

    protected function guessExtensionFromPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return '';
        }

        return '.'.$extension;
    }
}
