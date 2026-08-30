<?php

namespace App\Filament\Portal\Pages;

use App\Models\Manager;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherRoster;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MyRoster extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'My Roster';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.portal.pages.my-roster';

    public ?string $regionFilter = null;

    protected function getViewData(): array
    {
        [$students, $context] = $this->resolveStudents();

        return [
            'students' => $students,
            'context' => $context,
            'preferredRegionLabel' => $context['preferredRegionLabel'] ?? 'All Regions',
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Student>, 1: array<string, mixed>}
     */
    private function resolveStudents(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [collect(), ['role' => 'guest']];
        }

        $role = Str::of($user->role ?? '')->lower()->value();
        $region = session('preferred_region') ?? ($user->preferred_region ?: null);
        $regionNorm = $this->normalizeRegion($region);
        $allowedRegions = $user->allowedRegions();

        if ($user->restrictsByRegion() && ($regionNorm === null || ! in_array($regionNorm, $allowedRegions, true))) {
            $regionNorm = $allowedRegions[0] ?? null;
            if ($regionNorm) {
                session(['preferred_region' => $regionNorm]);
            }
        }

        $context = [
            'role' => $role,
            'preferredRegionLabel' => $regionNorm ?? 'All Regions',
        ];

        if ($role === 'manager') {
            $manager = Manager::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower((string) $user->email)])
                ->first();

            if (! $manager) {
                $context['missingManager'] = true;
                return [collect(), $context];
            }

            $context['manager'] = $manager;

            $students = Student::query()
                ->when($user->managerScope() !== 'region', fn ($query) => $query->where('manager_id', $manager->id))
                ->when($user->restrictsByRegion(), fn ($query) => $query->whereIn('location', $allowedRegions))
                ->when($regionNorm, function ($query) use ($regionNorm) {
                    $query->whereRaw('LOWER(location) = ?', [strtolower($regionNorm)]);
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            return [$students, $context];
        }

        if (in_array($role, User::TEACHING_ROLES, true)) {
            $students = TeacherRoster::students($user)
                ->when($user->restrictsByRegion() && $regionNorm, function ($collection) use ($regionNorm) {
                    $needle = strtolower($regionNorm);
                    return $collection->filter(fn ($student) => strtolower((string) ($student->location ?? '')) === $needle);
                })
                ->values();

            return [$students, $context];
        }

        return [collect(), $context];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    private function normalizeRegion(?string $region): ?string
    {
        if (! is_string($region) || trim($region) === '') {
            return null;
        }

        return match (Str::lower(trim($region))) {
            'uk' => 'UK',
            'spain' => 'Spain',
            'france' => 'France',
            default => null,
        };
    }
}
