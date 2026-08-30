<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\StudentEnrollmentMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class TwilioEnrollmentStatusController extends Controller
{
    public function __invoke(Request $request)
    {
        $twilioToken = config('services.twilio.token');

        if ($twilioToken && ! app()->environment(['local', 'testing'])) {
            $validator = new RequestValidator($twilioToken);
            $signature = $request->header('X-Twilio-Signature');
            if (! $signature || ! $validator->validate($signature, $request->fullUrl(), $request->all())) {
                Log::warning('Twilio enrolment webhook rejected: invalid signature', [
                    'sid' => $request->input('MessageSid'),
                ]);

                return response()->json(['message' => 'Invalid signature'], 403);
            }
        }

        $sid = $request->input('MessageSid');
        if (! $sid) {
            return response()->noContent();
        }

        $message = StudentEnrollmentMessage::where('twilio_sid', $sid)->first();

        if (! $message) {
            Log::warning('Twilio enrolment webhook received for unknown message', [
                'sid' => $sid,
            ]);

            return response()->noContent();
        }

        $status = strtolower((string) ($request->input('MessageStatus') ?? $request->input('SmsStatus') ?? ''));
        $now = Carbon::now();

        $updates = [
            'status' => $status ?: $message->status,
            'status_updated_at' => $now,
        ];

        if (in_array($status, ['delivered', 'received'])) {
            $updates['delivered_at'] = $message->delivered_at ?? $now;
        }

        if ($status === 'read') {
            $updates['read_at'] = $message->read_at ?? $now;
            $updates['delivered_at'] = $message->delivered_at ?? $now;
        }

        if (in_array($status, ['failed', 'undelivered'])) {
            $updates['error_code'] = $request->input('ErrorCode');
            $updates['error_message'] = $request->input('ErrorMessage');
        }

        $meta = $message->meta ?? [];
        $meta['last_webhook'] = array_filter([
            'status' => $status ?: null,
            'timestamp' => $now->toIso8601String(),
            'raw' => Arr::only($request->all(), [
                'MessageStatus',
                'SmsStatus',
                'ErrorCode',
                'ErrorMessage',
                'ChannelTo',
                'ChannelPrefix',
                'To',
            ]),
        ]);

        $message->forceFill($updates + ['meta' => $meta])->save();

        Log::info('Twilio enrolment status updated', [
            'sid' => $sid,
            'status' => $status,
        ]);

        return response()->noContent();
    }
}
