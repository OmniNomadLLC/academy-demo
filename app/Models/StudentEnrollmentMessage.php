<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentEnrollmentMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'initiated_by_user_id',
        'channel',
        'twilio_sid',
        'body',
        'status',
        'status_updated_at',
        'delivered_at',
        'read_at',
        'error_code',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'status_updated_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'meta' => 'array',
    ];

    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_SMS = 'sms';

    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
