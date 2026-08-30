<?php

namespace App\Support;

use Illuminate\Support\Str;

class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone);

        if (! $digits) {
            return null;
        }

        if (! Str::startsWith($digits, '+')) {
            $digits = '+' . ltrim($digits, '+');
        }

        // Basic length sanity check (E.164 max 15 digits plus +).
        if (strlen($digits) < 8 || strlen($digits) > 16) {
            return null;
        }

        return $digits;
    }
}
