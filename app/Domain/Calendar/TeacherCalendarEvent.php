<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;

final class TeacherCalendarEvent
{
    public function __construct(
        public readonly CarbonImmutable $startLocal,
        public readonly CarbonImmutable $endLocal,
        public readonly CarbonImmutable $startUtc,
        public readonly CarbonImmutable $endUtc,
        public readonly int $durationMinutes,
        public readonly array $meta = [],
    ) {
    }
}
