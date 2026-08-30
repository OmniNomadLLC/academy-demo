<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class TeacherCalendarEventFactory
{
    public function __construct(private readonly AcuityTimezoneResolver $timezoneResolver)
    {
    }

    public function make(array $payload, DateTimeZone $teacherTimezone, ?string $fallbackLocation = null): ?TeacherCalendarEvent
    {
        $acuityTimezone = $this->timezoneResolver->resolve($payload, $fallbackLocation);

        $startUtc = $this->resolveStart($payload, $acuityTimezone);
        if (! $startUtc) {
            return null;
        }

        $durationMinutes = $this->resolveDurationMinutes($payload, $acuityTimezone) ?? 60;
        $endUtc = $this->resolveEnd($payload, $acuityTimezone, $startUtc, $durationMinutes);

        if ($endUtc) {
            $diff = (int) $startUtc->diffInMinutes($endUtc, false);
            if ($diff > 0 && $diff >= $durationMinutes) {
                $durationMinutes = $diff;
            } else {
                $endUtc = null;
            }
        }

        if (! $endUtc) {
            $durationMinutes = max(1, $durationMinutes);
            $endUtc = $startUtc->addMinutes($durationMinutes);
        }

        $startLocal = $startUtc->setTimezone($teacherTimezone);
        $endLocal = $startLocal->addMinutes($durationMinutes);

        return new TeacherCalendarEvent(
            $startLocal,
            $endLocal,
            $startUtc,
            $endUtc,
            $durationMinutes,
            ['acuity_timezone' => $acuityTimezone->getName()]
        );
    }

    private function resolveStart(array $payload, DateTimeZone $acuityTimezone): ?CarbonImmutable
    {
        $raw = $this->firstString($payload, ['datetime', 'startDatetime', 'start_datetime']);
        if ($raw) {
            return $this->parseInTimezone($raw, $acuityTimezone);
        }

        $sessionDate = $this->firstString($payload, ['session_date']);
        $startTime = $this->firstString($payload, ['start_time', 'time', 'startTime']);

        if ($sessionDate && $startTime) {
            return $this->parseInTimezone("{$sessionDate} {$startTime}", $acuityTimezone);
        }

        return null;
    }

    private function resolveEnd(array $payload, DateTimeZone $acuityTimezone, CarbonImmutable $startUtc, int $durationMinutes): ?CarbonImmutable
    {
        $raw = $this->firstString($payload, ['endDatetime', 'end_datetime']);
        if ($raw) {
            $endUtc = $this->parseInTimezone($raw, $acuityTimezone);
            if ($endUtc) {
                return $endUtc;
            }
        }

        $sessionDate = $this->firstString($payload, ['session_date']);
        $endTime = $this->firstString($payload, ['end_time', 'endTime']);
        if ($sessionDate && $endTime) {
            $parsed = $this->parseInTimezone("{$sessionDate} {$endTime}", $acuityTimezone);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private function resolveDurationMinutes(array $payload, DateTimeZone $acuityTimezone): ?int
    {
        $candidates = [
            data_get($payload, 'duration_minutes'),
            data_get($payload, 'durationMinutes'),
            data_get($payload, 'duration_min'),
            data_get($payload, 'durationMin'),
            data_get($payload, 'duration'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $sessionDate = $this->firstString($payload, ['session_date']);
        $startTime = $this->firstString($payload, ['start_time']);
        $endTime = $this->firstString($payload, ['end_time']);

        if ($sessionDate && $startTime && $endTime) {
            $start = $this->parseInTimezone("{$sessionDate} {$startTime}", $acuityTimezone);
            $end = $this->parseInTimezone("{$sessionDate} {$endTime}", $acuityTimezone);

            if ($start && $end) {
                $diff = (int) $start->diffInMinutes($end, false);
                if ($diff > 0) {
                    return $diff;
                }
            }
        }

        return null;
    }

    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function parseInTimezone(string $value, DateTimeZone $timezone): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($value), $timezone)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
