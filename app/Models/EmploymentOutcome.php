<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmploymentOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'job_status',
        'job_start_date',
        'job_end_date',
        'hours_per_week',
        'employer_name',
    ];

    protected $casts = [
        'job_start_date' => 'date',
        'job_end_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
