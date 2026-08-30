<?php

namespace App\Support;

class AttendanceMath
{
    public static function attendancePct(int $present, int $late, int $absent): float
    {
        $total = $present + $late + $absent;

        if ($total === 0) {
            return 0.0;
        }

        return round((($present + $late) / $total) * 100, 1);
    }

    public static function formatPct(float $value): string
    {
        return number_format($value, 1) . '%';
    }
}
