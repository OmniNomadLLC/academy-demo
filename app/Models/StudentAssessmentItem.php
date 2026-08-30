<?php

namespace App\Models;

use App\Support\Assessments\SkillCategoryResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAssessmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_assessment_id',
        'template_section_id',
        'template_question_id',
        'section_name',
        'skill_category',
        'question_text',
        'max_score',
        'weight',
        'sort_order',
        'template_version',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function getSkillCategoryAttribute($value): string
    {
        $resolver = app(SkillCategoryResolver::class);
        $normalized = $resolver->normalize($value);

        if ($normalized) {
            return $normalized;
        }

        return $resolver->resolveFromSection($this->section_name);
    }

    public function setSkillCategoryAttribute(?string $value): void
    {
        $resolver = app(SkillCategoryResolver::class);
        $this->attributes['skill_category'] = $resolver->normalize($value);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(StudentAssessment::class, 'student_assessment_id');
    }

    public function templateSection(): BelongsTo
    {
        return $this->belongsTo(AssessmentSection::class, 'template_section_id');
    }

    public function templateQuestion(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class, 'template_question_id');
    }
}
