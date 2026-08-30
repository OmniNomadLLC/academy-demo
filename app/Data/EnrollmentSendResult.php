<?php

namespace App\Data;

class EnrollmentSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    public static function success(string $message, array $context = []): self
    {
        return new self(true, $message, $context);
    }

    public static function failure(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }
}
