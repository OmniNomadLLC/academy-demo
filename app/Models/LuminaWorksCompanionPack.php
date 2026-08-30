<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuminaWorksCompanionPack extends Model
{
    protected $table = 'lumina_works_companion_packs';

    protected $fillable = [
        'student_id',
        'lumina_works_job_id',
        'english_band',
        'source',
        'content',
    ];

    protected $casts = [
        'content' => 'array',
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
