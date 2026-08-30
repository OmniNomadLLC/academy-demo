<?php

namespace App\Jobs;

use App\Support\EmailNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAcuityWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30; // keep small; heavy work happens in downstream jobs

    protected array $envelope;
    protected string $raw;
    protected array $headers;
    protected ?string $contentType;

    public function __construct(array $envelope, string $raw, array $headers = [], ?string $contentType = null)
    {
        $this->onQueue('acuity');
        $this->envelope = $envelope;
        $this->raw = $raw;
        $this->headers = $headers;
        $this->contentType = $contentType;
    }

    public function handle(): void
    {
        try {
            $action = $this->envelope['action'] ?? null;
            $appointmentId = $this->envelope['appointmentId'] ?? null;
            $calendarId = $this->envelope['calendarId'] ?? null;
            $eventTime = $this->envelope['eventTime'] ?? ($this->envelope['created_at'] ?? null);

            $fingerprint = $this->fingerprint($action, $appointmentId, $eventTime, $this->raw);

            // Insert idempotently; if duplicate, skip processing
            $payloadToStore = null;
            if (strlen($this->raw) <= 1024 * 1024) { // <= 1MB
                // Try to decode JSON for storage when possible
                $decoded = json_decode($this->raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $payloadToStore = $decoded;
                } else {
                    // Handle form-encoded payloads so they can still be persisted as JSON
                    parse_str($this->raw, $formDecoded);
                    if (!empty($formDecoded)) {
                        $payloadToStore = $formDecoded;
                    }
                }
            }

            $payloadJson = null;
            if ($payloadToStore !== null) {
                $payloadJson = json_encode($payloadToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payloadJson === false) {
                    $payloadJson = null;
                }
            }

            try {
                DB::table('acuity_webhook_events')->insert([
                    'fingerprint' => $fingerprint,
                    'action' => (string) ($action ?? ''),
                    'appointment_id' => $appointmentId !== null ? (string) $appointmentId : null,
                    'calendar_id' => $calendarId !== null ? (string) $calendarId : null,
                    'received_at' => now(),
                    'payload' => $payloadJson,
                ]);
            } catch (\Throwable $e) {
                // Duplicate or other DB issue
                $message = $e->getMessage();
                if (str_contains(strtolower($message), 'unique') || str_contains(strtolower($message), 'constraint')) {
                    $this->logAcuity('info', 'Duplicate webhook ignored', [
                        'fingerprint' => $fingerprint,
                        'action' => $action,
                        'appointmentId' => $appointmentId,
                    ]);
                    return; // idempotent: already processed
                }
                throw $e;
            }

            // Normalize email for downstream linking if present
            $email = EmailNormalizer::fromAcuity($this->envelope);

            // Route to existing ingestion flows
            $lowerAction = is_string($action) ? strtolower($action) : '';
            // If any appointmentId is present, always sync the appointment (covers 'changed', 'canceled', etc.)
            if ($appointmentId) {
                SyncAcuityAppointment::dispatch($appointmentId, $action)->onQueue('acuity');
            }

            // If a clientId is present (or action mentions client), sync the client as well
            $clientId = $this->envelope['clientId'] ?? $this->envelope['clientID'] ?? data_get($this->envelope, 'client.id');
            if ($clientId) {
                SyncAcuityClient::dispatch($clientId)->onQueue('acuity');
            }

            $this->logAcuity('info', 'Webhook processed and downstream jobs queued', [
                'fingerprint' => $fingerprint,
                'action' => $action,
                'appointmentId' => $appointmentId,
                'calendarId' => $calendarId,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->logAcuity('error', 'Failed processing Acuity webhook', [
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);
            // Never rethrow to avoid poisoning the webhook pipeline
        }
    }

    protected function fingerprint($action, $appointmentId, $eventTime, string $raw): string
    {
        $timePart = (string) ($eventTime ?? '');
        $base = (string) ($action ?? '') . '|' . (string) ($appointmentId ?? '') . '|' . $timePart;
        if ($timePart === '') {
            $base .= '|' . hash('sha256', $raw);
        }
        return hash('sha256', $base);
    }

    /**
     * Write to the dedicated Acuity log channel, falling back to the default channel
     * if the rotating log file cannot be written (for example, due to permissions).
     */
    protected function logAcuity(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('acuity')->{$level}($message, $context);
        } catch (\Throwable $loggerException) {
            $fallback = config('logging.default', 'stack');
            if ($fallback === 'acuity') {
                $fallback = 'single';
            }

            try {
                Log::channel($fallback)->{$level}(
                    $message,
                    $context + [
                        'acuity_logger_fallback' => true,
                        'acuity_logger_error' => $loggerException->getMessage(),
                    ]
                );
            } catch (\Throwable $fallbackException) {
                // Final fallback: swallow logging issues so the job can continue.
                // surface minimal information via PHP's error log.
                error_log('[AcuityWebhook] '.$message.' (logging suppressed: '.$fallbackException->getMessage().')');
            }
        }
    }
}
