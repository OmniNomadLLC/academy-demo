<?php

namespace App\Http\Controllers;

use App\Models\LuminaWorksCompanionPack;
use App\Models\LuminaWorksJobMatch;
use App\Models\Student;
use App\Services\LuminaWorks\EvidenceLogger;
use Illuminate\Http\Request;

/**
 * Student-facing job coach page, opened via a signed URL (no login — students
 * have no accounts). The signature scopes access to one student and expires;
 * the page is read-only.
 */
class LuminaWorksCoachController extends Controller
{
    public function show(Request $request, Student $student, EvidenceLogger $logger)
    {
        abort_unless(config('luminaworks.enabled'), 404);

        $matches = LuminaWorksJobMatch::with('job')
            ->where('student_id', $student->id)
            ->where('status', '!=', LuminaWorksJobMatch::STATUS_DISMISSED)
            ->orderByDesc('score')
            ->limit(10)
            ->get();

        $packs = LuminaWorksCompanionPack::where('student_id', $student->id)
            ->get()
            ->keyBy('lumina_works_job_id');

        $logger->record(
            $student->id,
            'coach_page_viewed',
            'Student opened their job coach page',
            null,
            ['matches_shown' => $matches->count()]
        );

        return view('luminaworks.coach', [
            'student' => $student,
            'matches' => $matches,
            'packs' => $packs,
        ]);
    }
}
