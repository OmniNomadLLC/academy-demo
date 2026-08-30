<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuminaWorksEmployerVerification extends Model
{
    protected $table = 'lumina_works_employer_verifications';

    public const RESULTS = ['attended', 'no_show', 'hired', 'not_hired'];

    protected $fillable = [
        'lumina_works_application_id',
        'employer_name',
        'contact_name',
        'result',
        'notes',
        'confirmed_at',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(LuminaWorksApplication::class, 'lumina_works_application_id');
    }
}
