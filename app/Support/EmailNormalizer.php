<?php

namespace App\Support;

class EmailNormalizer
{
    public static function normalize(?string $email): ?string
    {
        if ($email === null) return null;
        // Replace common delimiters with spaces and look for the first valid email pattern
        $candidate = str_replace([";", "\n", "\r"], ' ', $email);
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'\u{00A0}");

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $candidate, $matches)) {
            return strtolower($matches[0]);
        }

        $candidate = strtolower($candidate);
        $candidate = preg_replace('/\s+/', '', $candidate);

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : null;
    }

    /**
     * Extract email from Acuity payload using tolerant paths and normalize it.
     */
    public static function fromAcuity(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'client.email'),
            data_get($payload, 'clientEmail'),
            data_get($payload, 'client.emailAddress'),
            data_get($payload, 'ClientEmail'),
            data_get($payload, 'client.email_address'),
            data_get($payload, 'email'),
        ];
        foreach ($candidates as $c) {
            $n = self::normalize(is_string($c) ? $c : (is_null($c) ? null : (string) $c));
            if ($n !== null) return $n;
        }
        return null;
    }
}
