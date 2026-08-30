<?php

namespace App\Jobs;

use App\Data\EnrollmentMessageData;
use App\Data\EnrollmentSendResult;
use App\Exceptions\EnrollmentMessageException;
use App\Models\Student;
use App\Services\EnrollmentMessageBuilder;
use App\Services\EnrollmentMessageRecorder;
use App\Services\EnrollmentMessenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEnrollmentMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $studentId,
        public string $channel,
        public ?int $initiatorId = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        EnrollmentMessageBuilder $builder,
        EnrollmentMessenger $messenger,
        EnrollmentMessageRecorder $recorder,
    ): void
    {
        $student = Student::find($this->studentId);
        if (! $student) {
            Log::warning('SendEnrollmentMessage: student missing', ['student_id' => $this->studentId]);
            return;
        }

        $channel = strtolower($this->channel);
        if (! in_array($channel, ['whatsapp', 'sms_email'], true)) {
            Log::warning('SendEnrollmentMessage: unknown channel', ['channel' => $this->channel]);
            return;
        }
        try {
            $data = $builder->build($student);
        } catch (EnrollmentMessageException $exception) {
            Log::warning('SendEnrollmentMessage: builder error', [
                'student_id' => $student->id,
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $result = $channel === 'whatsapp'
            ? $messenger->sendWhatsApp($data)
            : $messenger->sendSmsAndEmail($data);

        $recorder->record($student, $channel, $data, $result, $this->initiatorId);

        if ($result->success) {
            $student->forceFill([
                'enrollment_last_channel' => $channel,
                'enrollment_last_sent_at' => now(),
                'enrollment_last_sent_by_user_id' => $this->initiatorId,
            ])->save();
        }

        $this->logResult($result, $channel, $student->id);
    }

    protected function logResult(EnrollmentSendResult $result, string $channel, int $studentId): void
    {
        $context = array_merge([ 'student_id' => $studentId, 'channel' => $channel ], $result->context);

        if ($result->success) {
            Log::info('SendEnrollmentMessage success', $context + ['message' => $result->message]);
        } else {
            Log::warning('SendEnrollmentMessage failed', $context + ['message' => $result->message]);
        }
    }
}
