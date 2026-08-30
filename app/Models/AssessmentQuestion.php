<?php

namespace App\Models;

use App\Support\Assessments\SkillCategory;
use App\Support\Assessments\SkillCategoryResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_template_id',
        'section',
        'skill_category',
        'question_text',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'skill_category' => 'string',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sort_order', function (Builder $query) {
            $query->orderBy('sort_order');
        });

        static::saving(function (self $question) {
            $resolver = app(SkillCategoryResolver::class);

            $rawCategory = $question->attributes['skill_category'] ?? null;
            if (! $rawCategory) {
                $question->attributes['skill_category'] = $resolver->resolveFromSection($question->section);
            }

            $category = $question->attributes['skill_category'] ?? null;

            if (blank($question->section) && $category) {
                $question->section = SkillCategory::label($category);
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AssessmentTemplate::class, 'assessment_template_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(StudentAssessmentAnswer::class, 'assessment_question_id');
    }

    public function getSkillCategoryAttribute($value): string
    {
        $resolver = app(SkillCategoryResolver::class);

        $normalized = $resolver->normalize($value);
        if ($normalized) {
            return $normalized;
        }

        return $resolver->resolveFromSection($this->section);
    }

    public function setSkillCategoryAttribute(?string $value): void
    {
        $resolver = app(SkillCategoryResolver::class);

        $this->attributes['skill_category'] = $resolver->normalize($value);
    }
}
