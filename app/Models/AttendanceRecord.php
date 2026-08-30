<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'class_session_id',
        'student_id',
        'status',
        'marked_at',
        'marked_by',
        'sent_at',
        'notes',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            $record->recomputeStudentAttendance();
        });

        static::deleted(function (self $record): void {
            $record->recomputeStudentAttendance();
        });
    }

    /**
     * Create or update an attendance record for a specific student/session pair.
     */
    public static function recordStatus(int $classSessionId, int $studentId, string $status, array $extra = []): self
    {
        $status = strtolower($status);
        $allowed = ['present', 'late', 'absent', 'cancelled'];
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid attendance status: {$status}");
        }

        $payload = array_merge([
            'status' => $status,
        ], $extra);

        if (! array_key_exists('marked_by', $payload)) {
            $payload['marked_by'] = Auth::id();
        }

        if (! array_key_exists('marked_at', $payload)) {
            $payload['marked_at'] = now();
        }

        if ($status !== 'absent' && ! array_key_exists('sent_at', $payload)) {
            $payload['sent_at'] = null;
        }

        // Normalise sent_at – only persist when explicitly provided.
        if (array_key_exists('sent_at', $payload) && $payload['sent_at'] === false) {
            unset($payload['sent_at']);
        }

        return static::query()->updateOrCreate(
            [
                'class_session_id' => $classSessionId,
                'student_id' => $studentId,
            ],
            $payload
        );
    }

    protected function recomputeStudentAttendance(): void
    {
        $student = $this->student()->first();
        if (! $student) {
            return;
        }

        $student->refresh();
        $student->recomputeAttendanceRate();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }
}
