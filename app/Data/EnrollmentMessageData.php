<?php

namespace App\Data;

use App\Models\ClassLocation;
use App\Models\ClassSession;
use App\Models\Student;

class EnrollmentMessageData
{
    public function __construct(
        public readonly Student $student,
        public readonly ClassSession $firstSession,
        public readonly ClassLocation $location,
        public readonly string $whatsappBody,
        public readonly string $smsBody,
        public readonly string $emailText,
        public readonly string $emailHtml,
        public readonly array $context = [],
    ) {
    }
}
