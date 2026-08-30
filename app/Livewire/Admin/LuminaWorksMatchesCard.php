<?php

namespace App\Livewire\Admin;

use App\Models\EmploymentProfile;
use App\Models\LuminaWorksApplication;
use App\Models\LuminaWorksJobMatch;
use App\Models\Student;
use App\Models\LuminaWorksJob;
use App\Services\LuminaWorks\CompanionService;
use App\Services\LuminaWorks\EvidenceLogger;
use App\Services\LuminaWorks\JobMatcher;
use App\Services\LuminaWorks\PostcodeGeocoder;
use Illuminate\Support\Facades\URL;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LuminaWorksMatchesCard extends Component
{
    #[Locked]
    public int $studentId;

    public bool $readOnly = false;

    public function mount(int $studentId, bool $readOnly = false): void
    {
        $this->studentId = $studentId;
        $this->readOnly = $readOnly;

        if (Gate::denies('update', Student::findOrFail($studentId))) {
            $this->readOnly = true;
        }
    }

    public function refreshMatches(): void
    {
        if ($this->readOnly) {
            return;
        }

        $profile = $this->activeProfile();

        if (!$profile) {
            Notification::make()->warning()->title('No active employment profile')->send();

            return;
        }

        if ($profile->latitude === null && $profile->postcode) {
            if ($coords = app(PostcodeGeocoder::class)->geocode($profile->postcode)) {
                $profile->forceFill($coords)->save();
            }
        }

        if ($profile->latitude === null) {
            Notification::make()->warning()->title('Add a postcode first')->body('Job matching needs a postcode to find commutable roles.')->send();

            return;
        }

        $written = app(JobMatcher::class)->matchProfile($profile->fresh());

        app(EvidenceLogger::class)->record(
            $this->studentId,
            'matches_surfaced',
            "{$written} job matches surfaced for the student",
            null,
            ['count' => $written]
        );

        Notification::make()->success()->title("Found {$written} matches")->send();
    }

    public function markApplied(int $matchId): void
    {
        if ($this->readOnly) {
            return;
        }

        $match = LuminaWorksJobMatch::with('job')
            ->where('student_id', $this->studentId)
            ->findOrFail($matchId);

        $match->update(['status' => LuminaWorksJobMatch::STATUS_APPLIED]);

        $application = LuminaWorksApplication::firstOrCreate(
            ['student_id' => $this->studentId, 'lumina_works_job_id' => $match->lumina_works_job_id],
            [
                'lumina_works_job_match_id' => $match->id,
                'status' => 'applied',
                'applied_at' => now(),
            ]
        );

        if ($application->wasRecentlyCreated) {
            app(EvidenceLogger::class)->record(
                $this->studentId,
                'application_submitted',
                "Applied to \"{$match->job->title}\" at " . ($match->job->employer_name ?? 'unknown employer'),
                $application,
                ['job_title' => $match->job->title, 'employer' => $match->job->employer_name, 'apply_url' => $match->job->apply_url]
            );
        }
    }

    public function dismiss(int $matchId): void
    {
        if ($this->readOnly) {
            return;
        }

        $match = LuminaWorksJobMatch::with('job')
            ->where('student_id', $this->studentId)
            ->findOrFail($matchId);

        $match->update(['status' => LuminaWorksJobMatch::STATUS_DISMISSED]);

        app(EvidenceLogger::class)->record(
            $this->studentId,
            'match_dismissed',
            "Match dismissed: \"{$match->job->title}\"",
            $match,
            ['job_title' => $match->job->title]
        );
    }

    public function generateCoachPack(int $jobId): void
    {
        if ($this->readOnly) {
            return;
        }

        $student = Student::findOrFail($this->studentId);
        $job = LuminaWorksJob::findOrFail($jobId);

        $pack = app(CompanionService::class)->getOrCreatePack($student, $job);

        app(EvidenceLogger::class)->record(
            $this->studentId,
            'coach_pack_generated',
            "Job coach pack generated for \"{$job->title}\" ({$pack->source})",
            $pack,
            ['job_title' => $job->title, 'source' => $pack->source, 'band' => $pack->english_band]
        );

        Notification::make()->success()->title('Coach pack ready')->body($pack->source === 'llm' ? 'Generated with AI.' : 'Generated from template (no AI key configured).')->send();
    }

    public ?string $employerLink = null;

    public function makeEmployerLink(int $applicationId): void
    {
        if ($this->readOnly) {
            return;
        }

        $application = LuminaWorksApplication::with('job')
            ->where('student_id', $this->studentId)
            ->findOrFail($applicationId);

        $verification = \App\Models\LuminaWorksEmployerVerification::firstOrCreate(
            ['lumina_works_application_id' => $application->id, 'confirmed_at' => null],
            ['employer_name' => $application->job->employer_name ?? 'Employer']
        );

        $this->employerLink = URL::temporarySignedRoute(
            'luminaworks.employer-verify',
            now()->addDays(14),
            ['verification' => $verification->id]
        );

        app(EvidenceLogger::class)->record(
            $this->studentId,
            'employer_link_issued',
            "Employer verification link issued for \"{$application->job->title}\"",
            $verification,
            ['job_title' => $application->job->title, 'employer' => $verification->employer_name]
        );
    }

    public function getStudentCoachLinkProperty(): string
    {
        return URL::temporarySignedRoute('luminaworks.coach', now()->addDays(7), ['student' => $this->studentId]);
    }

    public function advanceApplication(int $applicationId, string $status): void
    {
        if ($this->readOnly || !in_array($status, LuminaWorksApplication::STATUSES, true)) {
            return;
        }

        $application = LuminaWorksApplication::with('job')
            ->where('student_id', $this->studentId)
            ->findOrFail($applicationId);

        $old = $application->status;

        if ($old === $status) {
            return;
        }

        $application->update([
            'status' => $status,
            'interview_at' => in_array($status, ['interview_invited', 'interviewed'], true) && !$application->interview_at ? now() : $application->interview_at,
            'outcome_at' => in_array($status, ['hired', 'not_progressed'], true) ? now() : $application->outcome_at,
        ]);

        app(EvidenceLogger::class)->record(
            $this->studentId,
            'application_status_changed',
            "Application for \"{$application->job->title}\": {$old} → {$status}",
            $application,
            ['old_status' => $old, 'new_status' => $status]
        );
    }

    private function activeProfile(): ?EmploymentProfile
    {
        return EmploymentProfile::where('student_id', $this->studentId)
            ->where('is_active', true)
            ->first();
    }

    public function render()
    {
        $matches = LuminaWorksJobMatch::with('job')
            ->where('student_id', $this->studentId)
            ->where('status', '!=', LuminaWorksJobMatch::STATUS_DISMISSED)
            ->orderByDesc('score')
            ->limit(10)
            ->get();

        $applications = LuminaWorksApplication::with('job')
            ->where('student_id', $this->studentId)
            ->orderByDesc('applied_at')
            ->get();

        $packs = \App\Models\LuminaWorksCompanionPack::where('student_id', $this->studentId)
            ->get()
            ->keyBy('lumina_works_job_id');

        return view('livewire.admin.lumina-works-matches-card', [
            'matches' => $matches,
            'applications' => $applications,
            'packs' => $packs,
        ]);
    }
}
