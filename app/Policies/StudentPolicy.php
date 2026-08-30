<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('super_admin', 'admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasDomainAccess('students');
    }

    public function view(User $user, Student $student): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if (! $user->restrictsByRegion()) {
            return true;
        }

        return $this->studentMatchesAllowedRegions($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->hasDomainAccess('students') && ! $user->hasRole('uk_manager');
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasDomainAccess('students') && ! $user->hasRole('uk_manager');
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasDomainAccess('students') && ! $user->hasRole('uk_manager');
    }

    public function restore(User $user, Student $student): bool
    {
        return $this->delete($user, $student);
    }

    public function forceDelete(User $user, Student $student): bool
    {
        return $this->delete($user, $student);
    }

    protected function studentMatchesAllowedRegions(User $user, Student $student): bool
    {
        $regions = $user->allowedRegions();
        $location = strtolower((string) ($student->location ?? ''));

        foreach ($regions as $region) {
            $normalized = strtolower($region);
            if ($normalized === 'uk' && (($student->in_uk ?? false) || $location === 'uk')) {
                return true;
            }

            if ($normalized === 'spain' && (($student->in_spain ?? false) || $location === 'spain')) {
                return true;
            }

            if ($normalized === 'france' && (($student->in_france ?? false) || $location === 'france')) {
                return true;
            }

            if ($normalized === $location && $location !== '') {
                return true;
            }
        }

        return false;
    }
}
