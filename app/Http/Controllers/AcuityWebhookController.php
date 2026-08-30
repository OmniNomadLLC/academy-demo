<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAcuityWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AcuityWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $logger = Log::channel('acuity');

        // Read raw content and headers
        $raw = (string) $request->getContent();
        $contentType = $request->header('Content-Type');
        $headers = collect($request->headers->all())
            ->map(fn($v) => is_array($v) && count($v) === 1 ? $v[0] : $v)
            ->toArray();
        // Redact sensitive headers
        foreach ([
            'authorization','x-authorization','x-api-key','x-acuity-signature','x-acuity-hmac-sha256'
        ] as $h) {
            if (isset($headers[$h])) {
                $headers[$h] = 'REDACTED';
            }
        }

        // Parse payload supporting JSON and form-urlencoded
        $payload = [];
        if (is_string($contentType) && str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!$payload) {
            // Fall back to Laravel's parsed input (form-encoded)
            $payload = $request->all();
        }

        // Extract minimal envelope
        $envelope = [
            'action' => $payload['action'] ?? ($payload['event'] ?? null),
            'appointmentId' => $payload['appointmentId'] ?? $payload['id'] ?? data_get($payload, 'appointment.id'),
            'calendarId' => $payload['calendarId'] ?? data_get($payload, 'calendar.id') ?? data_get($payload, 'calendarID'),
            'eventTime' => $payload['eventTime'] ?? $payload['timestamp'] ?? $payload['created_at'] ?? null,
            'clientId' => data_get($payload, 'client.id') ?? data_get($payload, 'clientID') ?? data_get($payload, 'clientId'),
            'client' => [
                'email' => data_get($payload, 'client.email') ?? data_get($payload, 'clientEmail') ?? $payload['email'] ?? null,
            ],
        ];

        // Log concise request info
        $logger->info('Acuity webhook received', [
            'route' => 'webhooks.acuity',
            'headers' => $headers,
            'body_preview' => substr($raw, 0, 2048),
            'content_type' => $contentType,
            'parsed' => [
                'action' => $envelope['action'] ?? null,
                'appointmentId' => $envelope['appointmentId'] ?? null,
                'calendarId' => $envelope['calendarId'] ?? null,
            ],
        ]);

        // Optional HMAC verification (best-effort). If invalid, 200 but ignore.
        $secret = config('services.acuity.webhook_secret') ?? env('ACUITY_WEBHOOK_SECRET');
        if (!empty($secret)) {
            $sig = $request->header('X-Acuity-Hmac-Sha256') ?? $request->header('X-Acuity-Signature');
            if (is_string($sig)) {
                $calc = base64_encode(hash_hmac('sha256', $raw, $secret, true));
                if (!hash_equals($sig, $calc)) {
                    $logger->warning('Acuity webhook signature invalid', [
                        'reason' => 'hmac_mismatch',
                    ]);
                    return response('ok', 200);
                }
            }
        }

        // Size guard: skip storing full payloads >1MB, but still enqueue with minimal envelope
        if (strlen($raw) > 1024 * 1024) {
            $logger->warning('Acuity webhook body too large; skipping full payload', [
                'size' => strlen($raw),
            ]);
            $rawForJob = '';
        } else {
            $rawForJob = $raw;
        }

        // Enqueue the processor and return immediately
        ProcessAcuityWebhook::dispatch($envelope, $rawForJob, $headers, $contentType)->onQueue('acuity');

        return response('ok', 200);
    }
}
