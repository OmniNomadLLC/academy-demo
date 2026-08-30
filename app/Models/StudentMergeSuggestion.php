<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class StudentMergeSuggestion extends Model
{
    protected $fillable = [
        'student_a_id',
        'student_b_id',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'status'      => 'string',
        'reason'      => 'string',
        'resolved_at' => 'datetime',
    ];

    public function studentA(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_a_id')->withTrashed();
    }

    public function studentB(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_b_id')->withTrashed();
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereIn('status', ['merged', 'dismissed']);
    }
}
