<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_session_id',
        'user_id',
        'action',
    ];
}
