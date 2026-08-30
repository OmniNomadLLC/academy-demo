<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'region',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('sort_order');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AssessmentSection::class, 'assessment_template_id')->orderBy('sort_order');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(StudentAssessment::class);
    }
}
