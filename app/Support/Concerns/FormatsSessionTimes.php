<?php

namespace App\Support\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait FormatsSessionTimes
{
    protected function formatTimeForUser($record, string $field): string
    {
        $userTimezone = $this->userTimezone();

        try {
            $eventDateTime = $this->resolveEventDateTime($record, $field);
            if (! $eventDateTime) {
                return $this->formatRawTime($record->{$field} ?? null);
            }

            return $eventDateTime->copy()->setTimezone($userTimezone)->format('H:i');
        } catch (\Throwable $e) {
            return $this->formatRawTime($record->{$field} ?? null);
        }
    }

    protected function formatRawTime($time): string
    {
        if (empty($time)) {
            return '—';
        }

        try {
            $trimmed = trim((string) $time);

            if (preg_match('/^\d{1,2}:\d{2}$/', $trimmed)) {
                return Carbon::createFromFormat('H:i', $trimmed)->format('H:i');
            }

            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $trimmed)) {
                return Carbon::createFromFormat('H:i:s', $trimmed)->format('H:i');
            }

            return Carbon::parse($trimmed)->format('H:i');
        } catch (\Throwable $e) {
            return (string) $time;
        }
    }

    protected function resolveEventDateTime($record, string $field): ?Carbon
    {
        $eventDateTimeRaw = $this->cleanJsonString($record->event_datetime ?? null);

        if ($eventDateTimeRaw) {
            $start = Carbon::parse($eventDateTimeRaw);

            return $field === 'raw_end_time'
                ? $this->applyDuration($start, $record)
                : $start;
        }

        $time = $record->{$field} ?? null;
        $date = $record->session_date ?? null;

        if (! $time || ! $date) {
            return null;
        }

        $baseTimezone = $this->determineEventTimezone($record);
        $dateString = $this->normalizeDateString($date);

        return Carbon::parse($dateString.' '.$time, $baseTimezone);
    }

    protected function determineEventTimezone($record): string
    {
        $fromPayload = $this->cleanJsonString($record->event_timezone ?? null);

        if ($fromPayload) {
            return $fromPayload;
        }

        return $this->fallbackTimezoneForLocation($record->location ?? null);
    }

    protected function applyDuration(Carbon $start, $record): Carbon
    {
        $durationRaw = $record->event_duration ?? null;
        $durationMinutes = is_numeric($durationRaw) ? (int) $durationRaw : null;

        if ($durationMinutes === null || $durationMinutes <= 0) {
            $approx = $this->approximateDurationFromEnd($start, $record);
            $durationMinutes = $approx > 0 ? $approx : 60;
        }

        return $start->copy()->addMinutes($durationMinutes);
    }

    protected function approximateDurationFromEnd(Carbon $start, $record): int
    {
        $end = $record->raw_end_time ?? null;

        if (! $end) {
            return 0;
        }

        try {
            $dateString = $this->normalizeDateString($record->session_date ?? $start);
            $endCarbon = Carbon::parse($dateString.' '.$end, $start->getTimezone());

            return max(0, $start->diffInMinutes($endCarbon, false));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function normalizeDateString($value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $string = (string) $value;

        if (str_contains($string, ' ')) {
            return explode(' ', $string)[0];
        }

        return $string;
    }

    protected function userTimezone(): string
    {
        $user = Auth::user();
        if ($user && is_string($user->timezone) && trim($user->timezone) !== '') {
            return trim($user->timezone);
        }

        return config('app.timezone');
    }

    protected function fallbackTimezoneForLocation(?string $location): string
    {
        $key = Str::lower((string) $location);

        return match ($key) {
            'uk', 'united kingdom', 'gb', 'great britain' => 'Europe/London',
            'spain', 'es' => 'Europe/Madrid',
            'france', 'fr' => 'Europe/Paris',
            default => config('app.timezone'),
        };
    }

    protected function cleanJsonString($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value, "\"");
    }
}
