<?php

namespace App\Filament\Pages;

use App\Models\EmploymentProfile;
use App\Models\LuminaWorksActivityLog;
use App\Models\LuminaWorksApplication;
use App\Models\LuminaWorksEmployerVerification;
use App\Models\LuminaWorksJobMatch;
use Filament\Pages\Page;

class LuminaWorksDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationLabel = 'Lumina Works';
    protected static ?string $title = 'Lumina Works — Outcome & Audit Dashboard';
    protected static ?string $navigationGroup = 'System';
    protected static string $view = 'filament.pages.lumina-works-dashboard';

    public static function shouldRegisterNavigation(): bool
    {
        return config('luminaworks.enabled') && static::canAccess();
    }

    public static function canAccess(): bool
    {
        if (!config('luminaworks.enabled')) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && $user->hasRole('super_admin', 'admin');
    }

    public function getFunnel(): array
    {
        $hired = LuminaWorksApplication::where('status', 'hired')->get();

        return [
            'profiles' => EmploymentProfile::where('is_active', true)->count(),
            'matches' => LuminaWorksJobMatch::count(),
            'applications' => LuminaWorksApplication::count(),
            'interviews' => LuminaWorksApplication::whereIn('status', ['interview_invited', 'interviewed', 'offered', 'hired'])->count(),
            'interviews_confirmed' => LuminaWorksEmployerVerification::whereNotNull('confirmed_at')->whereIn('result', ['attended', 'hired'])->count(),
            'hired' => $hired->count(),
            'evidence_events' => LuminaWorksActivityLog::count(),
        ];
    }

    /** Hired applications with progress toward the 16h/wk x 26wk DWP metric. */
    public function getPlacements(): array
    {
        return LuminaWorksApplication::with(['job', 'student'])
            ->where('status', 'hired')
            ->whereNotNull('outcome_at')
            ->orderBy('outcome_at')
            ->get()
            ->map(function (LuminaWorksApplication $application) {
                $weeks = (int) $application->outcome_at->diffInWeeks(now());
                $verified = LuminaWorksEmployerVerification::where('lumina_works_application_id', $application->id)
                    ->where('result', 'hired')
                    ->whereNotNull('confirmed_at')
                    ->exists();

                return [
                    'student' => trim(($application->student->first_name ?? '') . ' ' . mb_substr($application->student->last_name ?? '', 0, 1) . '.'),
                    'job' => $application->job->title,
                    'employer' => $application->job->employer_name,
                    'started' => $application->outcome_at->format('d M Y'),
                    'weeks' => min($weeks, 26),
                    'target_date' => $application->outcome_at->copy()->addWeeks(26)->format('d M Y'),
                    'employer_confirmed' => $verified,
                ];
            })
            ->all();
    }
}
