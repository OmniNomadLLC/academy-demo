<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'command',
        'params',
        'status',
        'output',
        'ran_by',
    ];

    protected $casts = [
        'params' => 'array',
    ];
}

