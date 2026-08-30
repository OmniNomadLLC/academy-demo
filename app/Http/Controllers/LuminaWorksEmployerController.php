<?php

namespace App\Http\Controllers;

use App\Models\LuminaWorksEmployerVerification;
use App\Services\LuminaWorks\EvidenceLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One-screen employer confirmation via signed URL — no employer accounts.
 * The employer sees only the job title and the participant's FIRST name;
 * one-time use (a completed verification cannot be re-submitted).
 */
class LuminaWorksEmployerController extends Controller
{
    public function show(Request $request, LuminaWorksEmployerVerification $verification)
    {
        abort_unless(config('luminaworks.enabled'), 404);

        return view('luminaworks.employer-verify', [
            'verification' => $verification,
            'application' => $verification->application->load('job', 'student'),
            'done' => $verification->confirmed_at !== null,
        ]);
    }

    public function store(Request $request, LuminaWorksEmployerVerification $verification, EvidenceLogger $logger)
    {
        abort_unless(config('luminaworks.enabled'), 404);

        if ($verification->confirmed_at !== null) {
            return redirect()->to($request->fullUrl());
        }

        $data = $request->validate([
            'result' => ['required', Rule::in(LuminaWorksEmployerVerification::RESULTS)],
            'contact_name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $verification->update([
            'result' => $data['result'],
            'contact_name' => $data['contact_name'],
            'notes' => $data['notes'] ?? null,
            'confirmed_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        $application = $verification->application->load('job');

        $logger->record(
            $application->student_id,
            'employer_verification',
            "Employer \"{$verification->employer_name}\" confirmed: {$data['result']} for \"{$application->job->title}\"",
            $verification,
            [
                'result' => $data['result'],
                'employer' => $verification->employer_name,
                'contact_name' => $data['contact_name'],
                'job_title' => $application->job->title,
            ]
        );

        return redirect()->to($request->fullUrl());
    }
}
