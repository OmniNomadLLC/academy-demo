<?php

namespace App\Services\Acuity;

class AppointmentExtractor
{
    public static function strOrNull($value): ?string
    {
        if ($value === null) return null;
        $s = is_string($value) ? $value : (string) $value;
        $s = trim($s);
        return $s === '' ? null : $s;
    }

    public static function lowerTrim(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        return $s === '' ? null : strtolower($s);
    }

    /**
     * Extract tolerant fields from an Acuity appointment payload.
     * Returns: [clientId, clientEmail, calendar, calendar_norm, category, category_norm]
     */
    public static function extract(array $data): array
    {
        $clientId = self::strOrNull(
            $data['client']['id'] ?? $data['clientId'] ?? $data['client']['ID'] ?? $data['Client']['id'] ?? null
        );

        $clientEmail = self::strOrNull(
            $data['client']['email'] ?? $data['email'] ?? null
        );
        $clientEmailNorm = self::lowerTrim($clientEmail);

        $calendar = self::strOrNull(
            $data['calendar'] ?? $data['calendarName'] ?? ($data['calendar']['name'] ?? null) ?? $data['Calendar'] ?? $data['CalendarName'] ?? null
        );
        $calendarNorm = self::lowerTrim($calendar);

        $category = self::strOrNull(
            $data['category'] ?? $data['Category'] ?? null
        );
        $categoryNorm = self::lowerTrim($category);

        return [
            'clientId' => $clientId,
            'clientEmail' => $clientEmailNorm, // normalized lowercased email
            'calendar' => $calendar,
            'calendar_norm' => $calendarNorm,
            'category' => $category,
            'category_norm' => $categoryNorm,
        ];
    }
}

