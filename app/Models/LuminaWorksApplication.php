<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuminaWorksApplication extends Model
{
    protected $table = 'lumina_works_applications';

    public const STATUSES = [
        'applied',
        'interview_invited',
        'interviewed',
        'offered',
        'hired',
        'not_progressed',
    ];

    protected $fillable = [
        'student_id',
        'lumina_works_job_id',
        'lumina_works_job_match_id',
        'status',
        'applied_at',
        'interview_at',
        'outcome_at',
        'notes',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'interview_at' => 'datetime',
        'outcome_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(LuminaWorksJob::class, 'lumina_works_job_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(LuminaWorksJobMatch::class, 'lumina_works_job_match_id');
    }
}
