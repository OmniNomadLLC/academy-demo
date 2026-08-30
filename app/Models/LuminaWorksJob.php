<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuminaWorksJob extends Model
{
    protected $table = 'lumina_works_jobs';

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'description',
        'employer_name',
        'location_name',
        'latitude',
        'longitude',
        'region',
        'category',
        'contract_time',
        'contract_type',
        'salary_min',
        'salary_max',
        'apply_url',
        'posted_at',
        'expires_at',
        'english_level_estimate',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'salary_min' => 'float',
        'salary_max' => 'float',
        'posted_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'raw' => 'array',
    ];
}
