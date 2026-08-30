<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailNotifier
{
    private array $recipients;

    public function __construct(?array $recipients = null)
    {
        $envList = trim((string) env('ALERT_EMAIL_TO', ''));
        if ($recipients === null) {
            if ($envList !== '') {
                $recipients = preg_split('/\s*,\s*/', $envList, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            } else {
                // Default to requested recipient
                $recipients = ['ops@example.test'];
            }
        }
        $this->recipients = array_values(array_unique(array_filter($recipients)));
    }

    public function enabled(): bool
    {
        return !empty($this->recipients);
    }

    public function send(string $subject, string $text): void
    {
        if (!$this->enabled()) {
            Log::warning('EmailNotifier: no recipients configured; skipping');
            return;
        }
        foreach ($this->recipients as $to) {
            try {
                Mail::raw($text, function ($m) use ($to, $subject) {
                    $m->to($to)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::error('EmailNotifier send failed: '.$e->getMessage(), ['to' => $to]);
            }
        }
    }
}

