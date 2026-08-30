<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Job extends Model
{
    use HasFactory;

    protected $table = 'job_listings';

    protected $fillable = [
        'title',
        'preferred_hours',
        'requires_experience',
    ];

    protected $casts = [
        'requires_experience' => 'boolean',
    ];

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentInterest::class, 'job_interest');
    }

    public function availabilities(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentAvailabilityOption::class, 'job_availability');
    }
}
