<?php

namespace App\Services\Acuity;

class AppointmentSliceResult
{
    public function __construct(
        public int $fetched = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $unlinked = 0,
        public int $matchedByEmail = 0,
        public int $matchedById = 0,
        public int $errors = 0,
        public int $retries = 0,
        public int $durationMs = 0,
    ) {
    }
}
