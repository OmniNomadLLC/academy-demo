<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuminaWorksJobMatch extends Model
{
    protected $table = 'lumina_works_job_matches';

    public const STATUS_SURFACED = 'surfaced';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_APPLIED = 'applied';

    protected $fillable = [
        'student_id',
        'lumina_works_job_id',
        'score',
        'reason',
        'score_source',
        'distance_km',
        'english_suitable',
        'is_mandated',
        'status',
        'surfaced_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'distance_km' => 'float',
        'english_suitable' => 'boolean',
        'is_mandated' => 'boolean',
        'surfaced_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(LuminaWorksJob::class, 'lumina_works_job_id');
    }
}
