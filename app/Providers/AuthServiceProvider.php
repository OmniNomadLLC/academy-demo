<?php

namespace App\Providers;

use App\Models\Student;
use App\Policies\StudentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Student::class => StudentPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('viewDashboard', function ($user) {
            return $user->hasDomainAccess('dashboard');
        });

        Gate::define('viewControlPanel', function ($user) {
            return $user->hasDomainAccess('data_health');
        });

        Gate::define('manageUsers', function ($user) {
            return $user->hasDomainAccess('system');
        });

        Gate::define('runAudits', function ($user) {
            return $user->hasDomainAccess('data_health') || $user->hasDomainAccess('reporting');
        });

        Gate::define('exportData', function ($user) {
            return $user->hasDomainAccess('reporting');
        });

        Gate::define('takeAttendanceAny', function ($user) {
            return $user->hasDomainAccess('attendance');
        });

        Gate::define('takeAttendanceOwn', function ($user) {
            return $user->hasDomainAccess('attendance');
        });

        Gate::define('viewHorizon', function ($user) {
            return $user->hasDomainAccess('automation') || $user->hasDomainAccess('system');
        });

        Gate::define('manageAssessmentQuestionnaires', function ($user) {
            return method_exists($user, 'canManageAssessmentTemplates')
                ? $user->canManageAssessmentTemplates()
                : false;
        });
    }
}
