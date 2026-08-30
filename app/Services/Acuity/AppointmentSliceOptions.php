<?php

namespace App\Services\Acuity;

class AppointmentSliceOptions
{
    public function __construct(
        public string $minDate,
        public string $maxDate,
        public int $pageSize = 100,
        public int $maxRetries = 5,
        public int $retryBaseMs = 500,
        public bool $dryRun = false,
        public bool $linkAfterSlice = false,
        public ?int $limit = null,
        public int $alreadyFetched = 0,
        public ?int $calendarId = null,
    ) {
    }
}
