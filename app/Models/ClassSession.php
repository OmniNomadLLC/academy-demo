<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ClassSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_class_id',
        'class_location_id',
        'teacher_id',
        'assigned_teacher_id',
        'cover_teacher_id',
        'student_id',
        'acuity_appointment_id',
        'session_date',
        'start_time',
        'end_time',
        'status',
        'canceled',
        'max_students',
        'notes',
        'link_status',
        'acuity_data',
        'category_norm',
        'location', 
        'calendar_name',
        'calendar_norm',
        'client_email',
        'student_email',
        'venue_name',
        'venue_address',
        'is_virtual',
        'virtual_meeting_url',
        'virtual_meeting_room',
        'email_sent_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        // Store time as plain strings to align with TIME columns
        'start_time' => 'string',
        'end_time' => 'string',
        'acuity_data' => 'array',
        'is_virtual' => 'boolean',
        'email_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ClassSession $session) {
            // Normalize category from acuity_data if present
            $data = $session->acuity_data;
            if (is_array($data)) {
                $category = $data['category'] ?? null;
                if (is_string($category)) {
                    $session->category_norm = strtolower(trim($category));
                }
            }
        });
    }

    // Relationships
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function classLocation(): BelongsTo
    {
        return $this->belongsTo(ClassLocation::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function assignedTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_teacher_id');
    }

    public function coverTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cover_teacher_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function isAssessment(): bool
    {
        $candidates = [$this->calendar_name];
        $data = $this->acuity_data;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            $candidates[] = $data['type'] ?? null;
            $candidates[] = $data['appointmentType']['name'] ?? null;
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== ''
                && str_contains(strtolower($candidate), 'assessment')) {
                return true;
            }
        }
        return false;
    }

    public function scopeExcludingAssessments(Builder $query): Builder
    {
        return $query->whereRaw('NOT '.static::assessmentRawExpression($query->getModel()->getTable()));
    }

    public function scopeAssessmentsOnly(Builder $query): Builder
    {
        return $query->whereRaw(static::assessmentRawExpression($query->getModel()->getTable()));
    }

    /**
     * Apply an "exclude assessments" filter to a raw query builder (DB::table()).
     *
     * $alias is the SQL alias used for the class_sessions table in the caller's join.
     */
    public static function applyExcludeAssessmentsToQuery(QueryBuilder $query, string $alias = 'class_sessions'): QueryBuilder
    {
        return $query->whereRaw('NOT '.static::assessmentRawExpression($alias));
    }

    public static function applyAssessmentsOnlyToQuery(QueryBuilder $query, string $alias = 'class_sessions'): QueryBuilder
    {
        return $query->whereRaw(static::assessmentRawExpression($alias));
    }

    /**
     * Raw SQL fragment that evaluates true when the class_sessions row is an assessment.
     *
     * Checks calendar_name and the acuity_data JSON payload (type + appointmentType.name).
     * Assumes MySQL/MariaDB (JSON_UNQUOTE + JSON_EXTRACT). Only one production/staging
     * driver is supported; tests that use SQLite should not rely on this scope.
     */
    protected static function assessmentRawExpression(string $alias): string
    {
        $a = $alias;
        return "(LOWER(COALESCE({$a}.calendar_name, '')) LIKE '%assessment%' "
             . "OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$a}.acuity_data, '$.type')), '')) LIKE '%assessment%' "
             . "OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$a}.acuity_data, '$.appointmentType.name')), '')) LIKE '%assessment%')";
    }
}
