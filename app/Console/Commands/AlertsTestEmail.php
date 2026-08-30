<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmailNotifier;

class AlertsTestEmail extends Command
{
    protected $signature = 'alerts:test-email {--to= : Recipient email (overrides ALERT_EMAIL_TO)} {--subject=Test Alert} {--message=This is a test alert from LET}';
    protected $description = 'Send a one-off test alert email to verify mail configuration.';

    public function handle(): int
    {
        $to = (string) ($this->option('to') ?: '');
        $recipients = $to !== '' ? [$to] : null;
        $subject = (string) $this->option('subject');
        $message = (string) $this->option('message');
        (new EmailNotifier($recipients))->send($subject, $message);
        $this->info('Test email dispatched to '.($to ?: env('ALERT_EMAIL_TO', 'configured recipients')).'.');
        return self::SUCCESS;
    }
}

