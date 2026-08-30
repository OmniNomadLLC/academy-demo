<?php

namespace App\Models;

use App\Services\LocationMappingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelation;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\StudentSkillProgress;
use App\Models\StudentProgressNote;
use App\Models\StudentAssessment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;

class Student extends Model
{
    use HasFactory;
    use SoftDeletes {
        SoftDeletes::bootSoftDeletes as bootSoftDeletesTrait;
    }

    protected $fillable = [
        'acuity_client_id',
        'first_name',
        'last_name',
        'email',
        'email_norm',
        'phone',
        'location',
        'acuity_category',
        'emergency_contact_name',
        'emergency_contact_phone',
        'registration_date',
        'is_active',
        'notes',
        'photo_path',
        'is_online',
        'enrollment_last_sent_at',
        'enrollment_last_channel',
        'enrollment_last_sent_by_user_id',
        'archived_at',
        'archived_reason',
        'previous_student_id',
    ];

    protected $casts = [
        'registration_date' => 'date',
        'is_active' => 'boolean',
        'first_appointment_date' => 'date',
        'last_appointment_date' => 'date',
        'next_appointment_date' => 'date',
        'is_active_recent' => 'boolean',
        'is_online' => 'boolean',
        'enrollment_last_sent_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function getActiveStatusAttribute(): string
    {
        if (isset($this->is_active_recent) && $this->is_active_recent !== null) {
            return $this->is_active_recent ? 'Active' : 'Non active';
        }
        $last = $this->last_appointment_date;
        $next = $this->next_appointment_date;
        $isActive = false;
        if ($last) { $isActive = \Illuminate\Support\Carbon::parse($last)->gte(now()->subDays(14)); }
        if (!$isActive && $next) { $isActive = \Illuminate\Support\Carbon::parse($next)->lte(now()->addDays(45)); }
        return $isActive ? 'Active' : 'Non active';
    }

    // Relationships
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function progressNotes(): HasMany
    {
        return $this->hasMany(StudentProgressNote::class);
    }

    public function classSessions(): BelongsToMany
    {
        // Link via attendance records pivot (student_id <-> class_session_id)
        return $this->belongsToMany(ClassSession::class, 'attendance_records', 'student_id', 'class_session_id');
    }

    public function enrollmentMessages(): HasMany
    {
        return $this->hasMany(StudentEnrollmentMessage::class);
    }

    public function employmentOutcomes(): HasMany
    {
        return $this->hasMany(EmploymentOutcome::class);
    }

    public function employmentProfiles(): HasMany
    {
        return $this->hasMany(EmploymentProfile::class);
    }

    public function activeEmploymentProfile(): HasOne
    {
        return $this->hasOne(EmploymentProfile::class)->where('is_active', true);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(StudentAssessment::class);
    }

    public function latestEnrollmentMessage(): HasOne
    {
        return $this->hasOne(StudentEnrollmentMessage::class)->latestOfMany();
    }

    public function attendances(): HasMany
    {
        return $this->attendanceRecords();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Manager::class);
    }

    public function enrollmentLastSender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrollment_last_sent_by_user_id');
    }

    public function skillProgressLogs(): HasMany
    {
        return $this->hasMany(StudentSkillProgress::class);
    }

    public function latestSkillProgress(): ?StudentSkillProgress
    {
        return $this->skillProgressLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
    }

    /**
     * When true, recomputeAttendanceRate() will not queue the <75% alert email.
     * Set to true by bulk commands (e.g. students:recompute-attendance-rates) to
     * avoid email storms during one-off backfills.
     */
    public static bool $suppressLowRateAlerts = false;

    public function recomputeAttendanceRate(): void
    {
        $beforeRate = (float) ($this->attendance_rate ?? 0);
        $wasFlagged = ! empty($this->flagged_low_attendance_at);

        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->where('ar.student_id', $this->id)
            ->whereIn('ar.status', ['present', 'late', 'absent']);

        \App\Models\ClassSession::applyExcludeAssessmentsToQuery($query, 'cs');

        $counts = $query
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.class_session_id END) as present_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'late' THEN ar.class_session_id END) as late_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'absent' THEN ar.class_session_id END) as absent_count")
            ->selectRaw('COUNT(DISTINCT ar.class_session_id) as session_count')
            ->first();

        $present = (int) ($counts->present_count ?? 0);
        $late = (int) ($counts->late_count ?? 0);
        $absent = (int) ($counts->absent_count ?? 0);
        $sessions = max(1, (int) ($counts->session_count ?? 0));
        $rate = round((($present + $late) / $sessions) * 100, 2);
        $prevFlagged = $this->flagged_low_attendance_at;
        $this->attendance_rate = $rate;
        if ($rate < 75) {
            if (empty($this->flagged_low_attendance_at)) {
                $this->flagged_low_attendance_at = now();
            }
        } else {
            $this->flagged_low_attendance_at = null;
        }
        $this->save();

        if ($rate < 75 && ! $wasFlagged && ! self::$suppressLowRateAlerts) {
            $recipients = array_unique(array_filter(Arr::wrap(config('mail.low_attendance_alert_to'))));

            if (empty($recipients)) {
                $legacyRecipient = env('ATTENDANCE_ALERT_EMAIL', 'ops@example.test');
                if (! empty($legacyRecipient)) {
                    $recipients[] = $legacyRecipient;
                }
            }

            if (empty($recipients)) {
                Log::warning('Low attendance alert skipped: no recipients defined', [
                    'student_id' => $this->id,
                ]);
                return;
            }

            try {
                Mail::to($recipients)->queue(new \App\Mail\UkAdminLowRateAlert($this));
                Log::info('Low attendance alert queued', [
                    'student_id' => $this->id,
                    'student_email' => $this->email,
                    'previous_rate' => $beforeRate,
                    'current_rate' => $rate,
                    'recipients' => $recipients,
                ]);
            } catch (\Throwable $e) {
                Log::error('Low attendance alert failed', [
                    'student_id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // Scopes
    public function scopeForRegion(Builder $query, string $region): Builder
    {
        // Case-insensitive match on location
        return $query->whereRaw('LOWER(location) = ?', [strtolower($region)]);
    }

    public function scopeExcludingArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchivedOnly(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function previousProfile(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'previous_student_id');
    }

    public function nextProfile(): HasOne
    {
        return $this->hasOne(Student::class, 'previous_student_id');
    }

    // Calculated attributes
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // Helper methods
    public function getAttendanceRate(): float
    {
        $totalSessions = DB::table('attendance_records')
            ->where('student_id', $this->id)
            ->whereIn('status', ['present', 'late', 'absent'])
            ->distinct('class_session_id')
            ->count('class_session_id');
        if ($totalSessions === 0) return 0;

        $presentSessions = DB::table('attendance_records')
            ->where('student_id', $this->id)
            ->where('status', 'present')
            ->distinct('class_session_id')
            ->count('class_session_id');

        $lateSessions = DB::table('attendance_records')
            ->where('student_id', $this->id)
            ->where('status', 'late')
            ->distinct('class_session_id')
            ->count('class_session_id');

        return (($presentSessions + $lateSessions) / $totalSessions) * 100;
    }

    // Classification helper
    public function setAcuityCategoryAndLocation(?string $category): void
    {
        $originalLocation = $this->location;
        $this->acuity_category = $category ? trim($category) : null;

        $region = LocationMappingService::regionForCategory($category);
        $onlineCategory = $category !== null ? LocationMappingService::isOnlineCategory($category) : null;
        if ($region === 'Academic') {
            $this->location = 'Academic';
            $this->syncRegionFlags(null);
        } elseif ($region === null) {
            // Leave location as-is if already set; otherwise keep null
            if ($this->location === null) {
                $this->location = null;
            }
            \Log::warning('Student regional classification unknown for category', [
                'student_id' => $this->id,
                'category' => $category,
            ]);
        } else {
            $this->location = $region;
            $this->syncRegionFlags($region);
        }

        if ($originalLocation !== $this->location) {
            \Log::info('Student location changed', [
                'student_id' => $this->id,
                'from' => $originalLocation,
                'to' => $this->location,
                'category' => $category,
            ]);
        }

        try {
            if (Schema::hasColumn('students', 'is_online')) {
                if (is_string($this->location) && strtolower($this->location) === 'uk') {
                    if ($onlineCategory !== null) {
                        $this->is_online = $onlineCategory;
                    }
                } else {
                    $this->is_online = false;
                }
            }
        } catch (\Throwable $e) {
            // ignore schema inspection failures
        }
    }

    protected function syncRegionFlags(?string $activeRegion): void
    {
        try {
            $mapping = [
                'UK' => 'in_uk',
                'Spain' => 'in_spain',
                'France' => 'in_france',
            ];

            foreach ($mapping as $region => $column) {
                if (Schema::hasColumn('students', $column)) {
                    $this->{$column} = ($activeRegion === $region);
                }
            }
        } catch (\Throwable $e) {
            // ignore schema access errors
        }
    }

    // Scope: has class within a time window (±) and not canceled
    public function scopeWhereHasClassInWindow(EloquentBuilder $query, Carbon $from, Carbon $to): EloquentBuilder
    {
        return $query->whereExists(function ($sub) use ($from, $to) {
            /** @var \Illuminate\Database\Query\Builder $sub */
            $sub->selectRaw('1')
                ->from('class_sessions')
                ->whereBetween('class_sessions.session_date', [$from->toDateString(), $to->toDateString()])
                ->where(function ($w) {
                    $w->where('class_sessions.canceled', false)
                      ->orWhereNull('class_sessions.canceled')
                      ->orWhereIn('class_sessions.status', ['scheduled', 'confirmed']);
                })
                ->whereColumn('class_sessions.student_id', 'students.id');
        });
    }

    public function refreshAppointmentBounds(): void
    {
        $min = DB::table('class_sessions')
            ->join('attendance_records as ar', 'ar.class_session_id', '=', 'class_sessions.id')
            ->where('ar.student_id', $this->id)
            ->min('class_sessions.session_date');
        $max = DB::table('class_sessions')
            ->join('attendance_records as ar', 'ar.class_session_id', '=', 'class_sessions.id')
            ->where('ar.student_id', $this->id)
            ->max('class_sessions.session_date');
        $this->first_appointment_date = $min ?: $this->first_appointment_date;
        $this->last_appointment_date = $max ?: $this->last_appointment_date;
        $this->save();
    }

    protected static function booted(): void
    {
        static::saving(function (Student $student) {
            try {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('students', 'email_norm')) {
                    return;
                }

                if (static::emailNormIsGenerated()) {
                    return;
                }

                $email = $student->email ?? null;
                $student->email_norm = $email ? strtolower(trim($email)) : null;
            } catch (\Throwable $e) {
                // ignore schema access errors
            }
        });
    }

    protected static function bootSoftDeletes(): void
    {
        try {
            $instance = new static();
            $table = $instance->getTable();
            $column = $instance->getDeletedAtColumn();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        static::bootSoftDeletesTrait();
    }

    public static function emailNormIsGenerated(): bool
    {
        static $generated;
        if ($generated !== null) {
            return $generated;
        }

        $generated = false;

        try {
            if (! Schema::hasTable('students') || ! Schema::hasColumn('students', 'email_norm')) {
                return $generated;
            }

            $driver = DB::getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'])) {
                try {
                    $column = DB::select("SHOW COLUMNS FROM students WHERE Field = 'email_norm'");
                    if (! empty($column)) {
                        $extra = $column[0]->Extra ?? '';
                        $generated = stripos($extra, 'generated') !== false;
                    }
                } catch (\Throwable $e) {
                    $generated = false;
                }
            }
        } catch (\Throwable $e) {
            $generated = false;
        }

        return $generated;
    }
}
