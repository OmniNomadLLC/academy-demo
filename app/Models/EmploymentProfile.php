<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmploymentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'is_active',
        'has_work_experience',
        'preferred_hours',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_work_experience' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function employmentInterests(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentInterest::class, 'employment_profile_interest');
    }

    public function employmentAvailabilityOptions(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentAvailabilityOption::class, 'employment_profile_availability');
    }
}
