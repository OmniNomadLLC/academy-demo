<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherAppointmentTypeCatalog extends Model
{
    use HasFactory;

    protected $table = 'teacher_appointment_type_catalog';

    protected $fillable = [
        'acuity_calendar_id',
        'calendar_norm',
        'calendar_label',
        'acuity_appointment_type_id',
        'appointment_type_name',
        'last_seen_session_id',
        'last_seen_session_date',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_session_date' => 'date',
        'last_seen_at' => 'datetime',
    ];
}
