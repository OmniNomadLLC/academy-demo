<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmploymentInterest extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function employmentProfiles(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentProfile::class, 'employment_profile_interest');
    }
}
