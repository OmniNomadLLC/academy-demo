<?php

namespace App\Domain\Calendar;

use DateTimeZone;

final class AcuityTimezoneResolver
{
    /**
     * Map of common location labels to canonical IANA timezone identifiers.
     *
     * @var array<string, string>
     */
    private const LOCATION_MAP = [
        'uk' => 'Europe/London',
        'united kingdom' => 'Europe/London',
        'england' => 'Europe/London',
        'gb' => 'Europe/London',
        'great britain' => 'Europe/London',
        'london' => 'Europe/London',
        'spain' => 'Europe/Madrid',
        'es' => 'Europe/Madrid',
        'madrid' => 'Europe/Madrid',
        'france' => 'Europe/Paris',
        'fr' => 'Europe/Paris',
        'paris' => 'Europe/Paris',
    ];

    public function __construct(private readonly DateTimeZone $defaultTimezone)
    {
    }

    public function resolve(array $payload, ?string $fallbackLocation = null): DateTimeZone
    {
        $primaryCandidates = [
            $this->stringValue($payload, ['calendarTimezone', 'calendar.timezone', 'calendar.timeZone']),
            $this->stringValue($payload, ['timezone', 'timeZone']),
            $this->stringValue($payload, ['client.timezone', 'client.timeZone', 'Client.timezone', 'Client.timeZone']),
        ];

        foreach ($primaryCandidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $resolved = $this->timezoneFromValue($candidate);
            if ($resolved instanceof DateTimeZone) {
                return $resolved;
            }
        }

        if ($fallbackLocation && ($resolved = $this->timezoneFromValue($fallbackLocation))) {
            return $resolved;
        }

        $location = $this->stringValue($payload, ['location', 'session_location']);
        if ($location && ($resolved = $this->timezoneFromValue($location))) {
            return $resolved;
        }

        return $this->defaultTimezone;
    }

    private function timezoneFromValue(string $value): ?DateTimeZone
    {
        // first try direct IANA timezone string
        try {
            return new DateTimeZone(trim($value));
        } catch (\Throwable $e) {
            // continue to location map
        }

        $normalized = strtolower(trim($value));
        if (isset(self::LOCATION_MAP[$normalized])) {
            try {
                return new DateTimeZone(self::LOCATION_MAP[$normalized]);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function stringValue(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
