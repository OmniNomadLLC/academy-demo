<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_template_id',
        'name',
        'sort_order',
        'weight',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'weight' => 'decimal:2',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(AssessmentTemplate::class, 'assessment_template_id');
    }
}
