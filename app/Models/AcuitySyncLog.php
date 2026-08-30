<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcuitySyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'sync_type',
        'started_at',
        'completed_at',
        'status',
        'records_processed',
        'records_created',
        'records_updated',
        'error_message',
        'sync_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'sync_data' => 'array',
    ];
}