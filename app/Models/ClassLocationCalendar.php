<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ClassLocationCalendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_location_id',
        'calendar_slug',
        'calendar_name',
        'calendar_norm',
        'region',
    ];

    protected $casts = [
        'class_location_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            $record->calendar_slug = Str::slug((string) $record->calendar_slug ?: (string) $record->calendar_name);
            if (! $record->calendar_name) {
                $record->calendar_name = Str::title(str_replace('-', ' ', $record->calendar_slug));
            }

            $record->calendar_norm = Str::slug((string) ($record->calendar_name ?: $record->calendar_slug));

            if (! $record->region) {
                $record->region = 'UK';
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ClassLocation::class, 'class_location_id');
    }
}
