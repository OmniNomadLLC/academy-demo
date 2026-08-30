<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\TeacherAppointmentTypeAssignment;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser
{
    /**
     * Available region flags in the system.
     *
     * @var list<string>
     */
    public const REGIONS = ['UK', 'Spain', 'France'];

    /**
     * Domain-level capabilities that can be toggled per user.
     *
     * @var array<string, string>
     */
    public const DOMAIN_ACCESS_OPTIONS = [
        'dashboard' => 'Dashboards & KPIs',
        'attendance' => 'Attendance operations',
        'students' => 'Student management',
        'calendar' => 'Calendars & scheduling',
        'reporting' => 'Reporting & exports',
        'data_health' => 'Data health & sync tools',
        'automation' => 'Automation & queue controls',
        'system' => 'System administration',
    ];

    /**
     * Roles that should inherit teacher capabilities.
     *
     * @var list<string>
     */
    public const TEACHING_ROLES = ['teacher', 'head_teacher'];
    public const ROLE_UK_MANAGER = 'uk_manager';

    /**
     * Teacher data scoping options.
     */
    public const TEACHER_SCOPE_OPTIONS = [
        'assigned_classes' => 'Only classes they teach (default)',
        'calendar_linked' => 'Classes tied to their Acuity calendar',
        'region' => 'Any teaching data inside allowed regions',
    ];

    /**
     * Manager data scoping options.
     */
    public const MANAGER_SCOPE_OPTIONS = [
        'roster_only' => 'Only students explicitly assigned to them (default)',
        'region' => 'All students inside allowed regions',
    ];

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'acuity_calendar_id',
        'teacher_calendar_ids',
        'panel_preferences',
        'last_login_at',
        'timezone',
        'preferred_region',
        'access_regions',
        'access_domains',
        'teacher_scope',
        'manager_scope',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'access_regions' => 'array',
            'access_domains' => 'array',
            'teacher_calendar_ids' => 'array',
            'panel_preferences' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! (bool) ($this->is_active ?? true)) {
            return false;
        }

        $role = strtolower((string) ($this->role ?? ''));
        $adminRoles = ['super_admin', 'admin', self::ROLE_UK_MANAGER];
        $portalRoles = array_merge($adminRoles, ['manager', ...self::TEACHING_ROLES]);

        return match ($panel->getId()) {
            'admin' => in_array($role, $adminRoles, true),
            'portal' => in_array($role, $portalRoles, true),
            default => false,
        };
    }

    /**
     * Return a normalized list of regions this user can view.
     * Empty or "all" selections imply global access.
     *
     * @return list<string>
     */
    public function allowedRegions(): array
    {
        $regions = $this->access_regions ?? [];
        $normalized = [];

        foreach ($regions as $value) {
            $match = $this->normalizeRegionName($value);
            if ($match) {
                $normalized[] = $match;
            }
        }

        $normalized = array_values(array_unique($normalized));

        if (empty($normalized)) {
            return self::REGIONS;
        }

        $lower = array_map('strtolower', $normalized);
        if (in_array('all', $lower, true) || in_array('global', $lower, true)) {
            return self::REGIONS;
        }

        return array_values(array_intersect(self::REGIONS, $normalized) ?: self::REGIONS);
    }

    public function restrictsByRegion(): bool
    {
        $regions = $this->access_regions ?? [];
        if (empty($regions)) {
            return false;
        }

        $lower = array_map('strtolower', (array) $regions);
        return ! in_array('all', $lower, true) && ! in_array('global', $lower, true);
    }

    public function hasRegionAccess(string $region): bool
    {
        $region = $this->normalizeRegionName($region);
        if (! $region) {
            return false;
        }

        if (! $this->restrictsByRegion()) {
            return true;
        }

        return in_array($region, $this->allowedRegions(), true);
    }

    public function hasRole(string ...$roles): bool
    {
        $current = strtolower((string) ($this->role ?? ''));

        foreach ($roles as $role) {
            if ($current === strtolower($role)) {
                return true;
            }
        }

        return false;
    }

    public function isTeachingRole(): bool
    {
        return in_array(strtolower((string) ($this->role ?? '')), self::TEACHING_ROLES, true);
    }

    public function isUkManager(): bool
    {
        return $this->hasRole(self::ROLE_UK_MANAGER);
    }

    public function canFillAssessments(): bool
    {
        return $this->hasRole('super_admin', 'admin', ...self::TEACHING_ROLES);
    }

    public function canViewAssessments(): bool
    {
        if ($this->canFillAssessments()) {
            return true;
        }

        return $this->isUkManager();
    }

    public function canManageAssessmentTemplates(): bool
    {
        if (! $this->hasRegionAccess('UK')) {
            return false;
        }

        return $this->hasRole('super_admin', 'admin', 'head_teacher');
    }

    public function canManageAssessmentQuestionnaires(): bool
    {
        return $this->canManageAssessmentTemplates();
    }

    public function allowedDomains(): array
    {
        $domains = $this->access_domains ?? [];
        $domains = array_filter(array_map(static function ($value) {
            return is_string($value) ? strtolower(trim($value)) : null;
        }, $domains));
        $domains = array_values(array_unique($domains));

        if (empty($domains)) {
            return $this->defaultDomainsForRole();
        }

        if (in_array('all', $domains, true)) {
            return array_merge(['all'], array_keys(self::DOMAIN_ACCESS_OPTIONS));
        }

        return $domains;
    }

    public function hasDomainAccess(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $domains = $this->allowedDomains();

        if (in_array('all', $domains, true)) {
            return true;
        }

        return in_array($domain, $domains, true);
    }

    public function teacherScope(): string
    {
        $scope = $this->teacher_scope ?? null;
        $scope = is_string($scope) ? strtolower(trim($scope)) : null;

        if (! $scope || ! array_key_exists($scope, self::TEACHER_SCOPE_OPTIONS)) {
            return 'assigned_classes';
        }

        return $scope;
    }

    /**
     * @return list<string>
     */
    public function teacherCalendarIds(): array
    {
        $raw = $this->teacher_calendar_ids ?? [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized === '') {
                continue;
            }

            $ids[] = $normalized;
        }

        if (empty($ids) && filled($this->acuity_calendar_id)) {
            $ids[] = trim((string) $this->acuity_calendar_id);
        }

        if (empty($ids)) {
            $assignments = $this->relationLoaded('teacherAppointmentTypeAssignments')
                ? $this->teacherAppointmentTypeAssignments
                : $this->teacherAppointmentTypeAssignments()->get();

            $assignmentCalendars = $assignments
                ->pluck('acuity_calendar_id')
                ->filter(fn ($value) => is_string($value) || is_numeric($value))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($assignmentCalendars)) {
                $ids = array_merge($ids, $assignmentCalendars);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<TeacherAppointmentTypeAssignment>
     */
    public function teacherAppointmentTypeAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeacherAppointmentTypeAssignment::class, 'user_id');
    }

    /**
     * @return list<string>
     */
    public function teacherAppointmentTypeIds(): array
    {
        return $this->teacherAppointmentTypeAssignments
            ->pluck('acuity_appointment_type_id')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();
    }

    public function managerScope(): string
    {
        $scope = $this->manager_scope ?? null;
        $scope = is_string($scope) ? strtolower(trim($scope)) : null;

        if (! $scope || ! array_key_exists($scope, self::MANAGER_SCOPE_OPTIONS)) {
            return 'roster_only';
        }

        return $scope;
    }

    private function defaultDomainsForRole(): array
    {
        $role = strtolower((string) ($this->role ?? ''));

        return match ($role) {
            'super_admin' => ['all'],
            'admin' => ['all'],
            'manager' => ['dashboard', 'attendance', 'students', 'calendar', 'reporting', 'data_health'],
            'teacher' => ['dashboard', 'attendance', 'students', 'calendar'],
            'head_teacher' => ['dashboard', 'attendance', 'students', 'calendar'],
            'report_recipient' => ['reporting'],
            self::ROLE_UK_MANAGER => ['students', 'reporting'],
            default => [],
        };
    }

    private function normalizeRegionName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));

        return match ($key) {
            'uk', 'united kingdom', 'gb', 'great britain' => 'UK',
            'spain', 'es' => 'Spain',
            'france', 'fr' => 'France',
            'all', 'global' => 'all',
            default => null,
        };
    }
}
