<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LuminaWorksActivityLog extends Model
{
    protected $table = 'lumina_works_activity_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'student_id',
        'event_type',
        'related_type',
        'related_id',
        'description',
        'payload',
        'actor_user_id',
        'actor_role',
        'occurred_at',
        'prev_hash',
        'hash',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
