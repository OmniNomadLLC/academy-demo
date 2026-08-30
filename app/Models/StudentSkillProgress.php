<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSkillProgress extends Model
{
    use HasFactory;

    protected $table = 'student_skill_progress_logs';

    protected $fillable = [
        'student_id',
        'writing',
        'reading',
        'speaking',
        'recorded_at',
        'created_by',
    ];

    protected $casts = [
        'writing' => 'integer',
        'reading' => 'integer',
        'speaking' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}
