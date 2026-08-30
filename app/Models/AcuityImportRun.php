<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class AcuityImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'window_start',
        'window_end',
        'slice_days',
        'page_size',
        'max_retries',
        'retry_base_ms',
        'limit',
        'dry_run',
        'link_after_slice',
        'total_slices',
        'processed_slices',
        'fetched_count',
        'created_count',
        'updated_count',
        'unlinked_count',
        'matched_email_count',
        'matched_id_count',
        'error_count',
        'retries',
        'next_cursor',
        'current_slice_start',
        'current_slice_end',
        'current_slice_index',
        'last_error',
        'queued_by',
        'started_at',
        'finished_at',
        'last_activity_at',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'dry_run' => 'bool',
        'link_after_slice' => 'bool',
        'next_cursor' => 'datetime',
        'current_slice_start' => 'datetime',
        'current_slice_end' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    public function queuedBy()
    {
        return $this->belongsTo(User::class, 'queued_by');
    }

    public function markRunning(): void
    {
        if ($this->status !== self::STATUS_RUNNING) {
            $this->forceFill([
                'status' => self::STATUS_RUNNING,
                'started_at' => $this->started_at ?: now(),
            ])->save();
        }
    }

    public function markPaused(): void
    {
        $this->forceFill(['status' => self::STATUS_PAUSED])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill(['status' => self::STATUS_CANCELLED, 'last_activity_at' => now()])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'last_error' => $message,
            'last_activity_at' => now(),
        ])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'finished_at' => now(),
            'last_activity_at' => now(),
            'current_slice_index' => null,
            'current_slice_start' => null,
            'current_slice_end' => null,
            'next_cursor' => null,
        ])->save();
    }

    public function nextCursorDate(): CarbonImmutable
    {
        $cursor = $this->next_cursor ? CarbonImmutable::parse($this->next_cursor) : null;
        $start = CarbonImmutable::parse($this->window_start);
        return $cursor ?: $start->startOfDay();
    }

    public function windowEndDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->window_end)->endOfDay();
    }

    public function remainingSlices(): int
    {
        return max(0, (int) $this->total_slices - (int) $this->processed_slices);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_FAILED], true);
    }
}
