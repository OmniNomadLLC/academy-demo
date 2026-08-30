<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentAssessment extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    protected $fillable = [
        'student_id',
        'assessment_template_id',
        'assessed_by_user_id',
        'assessed_at',
        'average_score',
        'overall_comments',
        'attachment_path',
        'attachment_original_name',
        'status',
        'locked_at',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'average_score' => 'decimal:2',
        'locked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AssessmentTemplate::class, 'assessment_template_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(StudentAssessmentAnswer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudentAssessmentItem::class, 'student_assessment_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function markFinal(): void
    {
        $this->status = self::STATUS_FINAL;
        $this->locked_at = now();
    }

    public function setStatusAttribute(?string $value): void
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === 'completed') {
            $normalized = self::STATUS_FINAL;
        }

        if (! in_array($normalized, [self::STATUS_DRAFT, self::STATUS_FINAL], true)) {
            $normalized = self::STATUS_DRAFT;
        }

        $this->attributes['status'] = $normalized;
    }
}
