<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAppointmentTypeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'acuity_calendar_id',
        'acuity_appointment_type_id',
        'appointment_type_name',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
