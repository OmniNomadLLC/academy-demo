<?php

namespace App\Services;

use App\Data\EnrollmentMessageData;
use App\Data\EnrollmentSendResult;
use App\Models\Student;
use App\Models\StudentEnrollmentMessage;
use Illuminate\Support\Carbon;

class EnrollmentMessageRecorder
{
    public function record(
        Student $student,
        string $channel,
        EnrollmentMessageData $data,
        EnrollmentSendResult $result,
        ?int $initiatorId = null,
    ): StudentEnrollmentMessage {
        $normalizedChannel = $channel === 'whatsapp'
            ? StudentEnrollmentMessage::CHANNEL_WHATSAPP
            : StudentEnrollmentMessage::CHANNEL_SMS;

        $twilioSid = $result->context['sid'] ?? null;
        $twilioStatus = strtolower((string) ($result->context['status'] ?? ''));
        $status = $result->success
            ? ($twilioStatus ?: StudentEnrollmentMessage::STATUS_SENT)
            : StudentEnrollmentMessage::STATUS_FAILED;

        $meta = array_filter([
            'requested_channel' => $channel,
            'email_sent' => $channel === 'sms_email'
                ? (bool) ($result->context['email_sent'] ?? $result->success)
                : null,
            'twilio_status_raw' => $result->context['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (! empty($result->context)) {
            $meta['context'] = $result->context;
        }

        $message = StudentEnrollmentMessage::create([
            'student_id' => $student->id,
            'initiated_by_user_id' => $initiatorId,
            'channel' => $normalizedChannel,
            'twilio_sid' => $twilioSid,
            'body' => $normalizedChannel === StudentEnrollmentMessage::CHANNEL_WHATSAPP ? $data->whatsappBody : $data->smsBody,
            'status' => $status,
            'status_updated_at' => Carbon::now(),
            'error_code' => $result->success ? null : ($result->context['error_code'] ?? null),
            'error_message' => $result->success ? null : $result->message,
            'meta' => $meta,
        ]);

        return $message;
    }
}
