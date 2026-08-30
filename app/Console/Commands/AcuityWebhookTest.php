<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAcuityWebhook;
use Illuminate\Console\Command;

class AcuityWebhookTest extends Command
{
    protected $signature = 'acuity:webhook:test {--action=scheduled} {--id=123} {--calendar=456} {--email=demo@example.com}';

    protected $description = 'Simulate an Acuity webhook by dispatching the processor job directly';

    public function handle(): int
    {
        $action = (string) $this->option('action');
        $id = (string) $this->option('id');
        $calendar = (string) $this->option('calendar');
        $email = (string) $this->option('email');

        $payload = [
            'action' => $action,
            'appointmentId' => $id,
            'calendarId' => $calendar,
            'client' => ['email' => $email],
            'eventTime' => now()->toIso8601String(),
        ];
        $raw = json_encode($payload);

        ProcessAcuityWebhook::dispatch($payload, $raw, [
            'user-agent' => 'artisan-test',
        ], 'application/json')->onQueue('acuity');

        $this->info('Dispatched ProcessAcuityWebhook with:');
        $this->line('  action: ' . $action);
        $this->line('  appointmentId: ' . $id);
        $this->line('  calendarId: ' . $calendar);
        $this->line('  email: ' . $email);
        $this->line('Check queue worker and acuity log for processing details.');

        // Quick docs
        $this->line('Examples:');
        $this->line('  php artisan queue:work --queue=acuity,default -v');
        $this->line('  php artisan route:list | grep webhooks/acuity');
        $this->line('  curl -i http://127.0.0.1:8000/webhooks/acuity/health');

        return self::SUCCESS;
    }
}

