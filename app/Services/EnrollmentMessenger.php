<?php

namespace App\Services;

use App\Data\EnrollmentMessageData;
use App\Data\EnrollmentSendResult;
use App\Mail\EnrollmentMail;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

class EnrollmentMessenger
{
    public function sendWhatsApp(EnrollmentMessageData $message): EnrollmentSendResult
    {
        $config = $this->twilioConfig();
        $to = PhoneNumber::normalize($message->student->phone ?? null);
        $from = $config['from_whatsapp'] ?? null;

        if (! $config['sid'] || ! $config['token'] || ! $from) {
            return EnrollmentSendResult::failure('WhatsApp sender is not configured. Check Twilio SID/token and WhatsApp from number.');
        }

        if (! $to) {
            return EnrollmentSendResult::failure('Student phone number is missing or invalid (expected E.164, e.g. +447123456789).');
        }

        $statusCallback = $this->statusCallbackUrl();

        try {
            $client = $this->client($config['sid'], $config['token']);
            $params = [
                'from' => $this->ensurePrefix($from, 'whatsapp:'),
                'body' => $message->whatsappBody,
            ];

            if ($statusCallback) {
                $params['statusCallback'] = $statusCallback;
            }

            $response = $client->messages->create(
                $this->ensurePrefix($to, 'whatsapp:'),
                $params
            );
        } catch (TwilioException $exception) {
            $human = $this->humanizeTwilioError($exception, 'whatsapp');
            Log::error('WhatsApp enrolment send failed', $this->logContext($message, [
                'error' => $exception->getMessage(),
                'code' => method_exists($exception, 'getCode') ? $exception->getCode() : null,
            ]));

            return EnrollmentSendResult::failure($human, [
                'error_code' => method_exists($exception, 'getCode') ? $exception->getCode() : null,
            ]);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp enrolment send exception', $this->logContext($message, [
                'error' => $exception->getMessage(),
            ]));

            return EnrollmentSendResult::failure('Unexpected error while sending WhatsApp message. Please try again or contact support.', [
                'error_code' => null,
            ]);
        }

        Log::info('WhatsApp enrolment sent', $this->logContext($message, [
            'sid' => $response->sid ?? null,
        ]));

        return EnrollmentSendResult::success('WhatsApp message sent.', [
            'sid' => $response->sid ?? null,
            'status' => $response->status ?? null,
            'to' => $to,
            'from' => $from,
        ]);
    }

    public function sendSmsAndEmail(EnrollmentMessageData $message): EnrollmentSendResult
    {
        $smsResult = $this->attemptSms($message);
        $emailResult = $this->sendEmail($message);

        if (! $emailResult->success && ! $smsResult->success) {
            return EnrollmentSendResult::failure($smsResult->message);
        }

        if (! $emailResult->success) {
            return EnrollmentSendResult::failure($emailResult->message, [
                'sid' => $smsResult->context['sid'] ?? null,
                'status' => $smsResult->context['status'] ?? null,
                'email_sent' => false,
            ]);
        }

        if (! $smsResult->success) {
            return EnrollmentSendResult::failure($smsResult->message . ' Email was sent successfully.', [
                'sid' => $smsResult->context['sid'] ?? null,
                'status' => $smsResult->context['status'] ?? null,
                'email_sent' => true,
            ]);
        }

        return EnrollmentSendResult::success('SMS and email sent.', [
            'sid' => $smsResult->context['sid'] ?? null,
            'status' => $smsResult->context['status'] ?? null,
            'email_sent' => true,
            'to' => $smsResult->context['to'] ?? null,
            'from' => $smsResult->context['from'] ?? null,
        ]);
    }

    protected function attemptSms(EnrollmentMessageData $message): EnrollmentSendResult
    {
        $config = $this->twilioConfig();
        $to = PhoneNumber::normalize($message->student->phone ?? null);
        $from = $config['from_sms'] ?? null;
        $sid = $config['sid'];
        $token = $config['token'];

        if (! $to) {
            return EnrollmentSendResult::failure('Student phone number is missing or invalid (expected E.164, e.g. +447123456789).');
        }

        if (! $from) {
            return EnrollmentSendResult::failure('Twilio SMS sender number is not configured.');
        }

        if (! $sid || ! $token) {
            return EnrollmentSendResult::failure('Twilio credentials are missing.');
        }

        $statusCallback = $this->statusCallbackUrl();

        try {
            $client = $this->client($sid, $token);
            $params = [
                'from' => trim($from),
                'body' => $message->smsBody,
            ];

            if ($statusCallback) {
                $params['statusCallback'] = $statusCallback;
            }

            $response = $client->messages->create($to, $params);
        } catch (TwilioException $exception) {
            $human = $this->humanizeTwilioError($exception, 'sms');
            Log::error('SMS enrolment send failed', $this->logContext($message, [
                'error' => $exception->getMessage(),
                'code' => method_exists($exception, 'getCode') ? $exception->getCode() : null,
            ]));

            return EnrollmentSendResult::failure($human, [
                'error_code' => method_exists($exception, 'getCode') ? $exception->getCode() : null,
            ]);
        } catch (\Throwable $exception) {
            Log::error('SMS enrolment send exception', $this->logContext($message, [
                'error' => $exception->getMessage(),
            ]));

            return EnrollmentSendResult::failure('Unexpected error while sending SMS. Please try again or contact support.', [
                'error_code' => null,
            ]);
        }

        Log::info('SMS enrolment sent', $this->logContext($message, [
            'sid' => $response->sid ?? null,
        ]));

        return EnrollmentSendResult::success('SMS sent.', [
            'sid' => $response->sid ?? null,
            'status' => $response->status ?? null,
            'to' => $to,
            'from' => $from,
        ]);
    }

    protected function sendEmail(EnrollmentMessageData $message): EnrollmentSendResult
    {
        $email = $message->student->email;
        if (! $email) {
            return EnrollmentSendResult::failure('Student email address is missing; email not sent.');
        }

        try {
            Mail::mailer('admin')->to($email)->send(new EnrollmentMail($message));
        } catch (\Throwable $exception) {
            Log::error('Enrollment email send failed', $this->logContext($message, [
                'error' => $exception->getMessage(),
            ]));

            return EnrollmentSendResult::failure('Failed to send enrollment email. Please check mail configuration.');
        }

        Log::info('Enrollment email sent', $this->logContext($message, [
            'email' => $email,
        ]));

        return EnrollmentSendResult::success('Email sent.');
    }

    protected function twilioConfig(): array
    {
        $config = config('services.twilio', []);

        return [
            'sid' => $config['sid'] ?? null,
            'token' => $config['token'] ?? null,
            'from_sms' => $config['from_sms'] ?? null,
            'from_whatsapp' => $config['from_whatsapp'] ?? null,
            'status_callback' => $config['status_callback'] ?? null,
        ];
    }

    protected function client(string $sid, string $token): TwilioClient
    {
        return new TwilioClient($sid, $token);
    }

    protected function ensurePrefix(string $value, string $prefix): string
    {
        if (Str::startsWith($value, $prefix)) {
            return $value;
        }

        if ($prefix === 'whatsapp:') {
            $normalized = Str::startsWith($value, '+') ? $value : '+' . ltrim($value, '+');

            return $prefix . $normalized;
        }

        return $value;
    }

    protected function humanizeTwilioError(TwilioException $exception, string $channel): string
    {
        $message = strtolower($exception->getMessage());
        $code = method_exists($exception, 'getCode') ? $exception->getCode() : null;

        if ($channel === 'whatsapp' && str_contains($message, 'sandbox')) {
            return 'Twilio rejected the WhatsApp message: the recipient must join the WhatsApp sandbox first.';
        }

        if ($channel === 'sms' && (int) $code === 21608) {
            return 'Twilio trial accounts can only send SMS to verified numbers. Please verify the recipient number in Twilio.';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'not enabled')) {
            return 'Twilio rejected the request: check that the messaging channel is enabled for this account.';
        }

        return 'Twilio rejected the request: ' . $exception->getMessage();
    }

    protected function logContext(EnrollmentMessageData $message, array $extra = []): array
    {
        return array_merge([
            'student_id' => $message->student->id,
            'student_email' => $message->student->email,
            'student_phone' => $message->student->phone,
            'first_session_date' => $message->context['first_session_date'] ?? null,
            'location_slug' => $message->context['location_slug'] ?? null,
        ], $extra);
    }

    protected function statusCallbackUrl(): ?string
    {
        $configured = config('services.twilio.status_callback');
        if ($configured) {
            return $configured;
        }

        try {
            if (function_exists('route') && \Route::has('webhooks.twilio.enrollment-status')) {
                return route('webhooks.twilio.enrollment-status');
            }
        } catch (\Throwable $e) {
            // Fall through to APP_URL fallback
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        return $appUrl ? $appUrl . '/webhooks/twilio/enrollment-status' : null;
    }
}
