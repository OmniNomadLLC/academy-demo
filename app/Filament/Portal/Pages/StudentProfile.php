<?php

namespace App\Filament\Portal\Pages;

use App\Livewire\Concerns\HasStudentProfileTabs;
use App\Livewire\Concerns\HandlesStudentPhotos;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherRoster;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class StudentProfile extends Page
{
    use HandlesStudentPhotos;
    use HasStudentProfileTabs;

    protected static ?string $navigationIcon = null;
    protected static ?string $title = null;
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.portal.pages.student-profile';
    protected static string $routePath = 'students/{record}';

    public ?Student $student = null;
    public bool $canMarkAttendance = false;

    public function mount(int|string|null $record = null): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $record = $record ?? request()->query('record');

        if ($record === null) {
            abort(404);
        }

        $student = Student::findOrFail($record);

        $keys = TeacherRoster::studentLookupKeys($user);

        $emailNorm = strtolower(trim((string) ($student->email ?? $student->email_norm ?? '')));
        $clientId = (string) ($student->acuity_client_id ?? '');

        $allowed = in_array($student->id, $keys['ids'] ?? [], true)
            || ($emailNorm !== '' && in_array($emailNorm, $keys['emails'] ?? [], true))
            || ($clientId !== '' && in_array($clientId, $keys['client_ids'] ?? [], true));

        if (! $allowed) {
            $sessionId = (int) request()->query('session');

            if ($sessionId > 0) {
                $sessionAccess = TeacherRoster::sessions($user)
                    ->cloneWithout(['orders', 'columns'])
                    ->where('class_sessions.id', $sessionId)
                    ->exists();

                $allowed = $sessionAccess;
            }
        }

        abort_unless($allowed || $user->hasRole('admin', 'super_admin'), 403);

        $this->student = $student;
        $this->canMarkAttendance = in_array(strtolower((string) $user->role), User::TEACHING_ROLES, true);
    }

    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function studentIsUk(): bool
    {
        if (! $this->student) {
            return false;
        }

        return strtolower((string) ($this->student->location ?? '')) === 'uk';
    }

    protected function getViewData(): array
    {
        return [
            'student' => $this->student,
            'isUkStudent' => $this->studentIsUk(),
            'canMarkAttendance' => $this->canMarkAttendance,
        ];
    }

    protected function getStudentModel(): ?Student
    {
        return $this->student;
    }
}
