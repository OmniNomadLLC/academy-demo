<?php

namespace App\Filament\Resources\Concerns;

use App\Exceptions\EnrollmentMessageException;
use App\Models\Student;
use App\Services\EnrollmentMessageBuilder;
use App\Services\EnrollmentMessageRecorder;
use App\Services\EnrollmentMessenger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

trait HandlesEnrollmentActions
{
    protected function enrollmentActions(): array
    {
        $user = Auth::user();
        $canSend = $user && $user->hasRole('admin', 'super_admin') && $this->canSendEnrollment();

        // Demo mode: no outbound messaging exists (no Twilio, mail logs to
        // file), so hide the send actions rather than let them error.
        if (! $canSend || config('app.demo_mode')) {
            return [];
        }

        return [
            Actions\Action::make('sendEnrollmentWhatsapp')
                ->label('Send enrolment via WhatsApp')
                ->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->requiresConfirmation()
                ->action('sendEnrollmentWhatsApp'),
            Actions\Action::make('sendEnrollmentSmsEmail')
                ->label('Send enrolment via SMS / Email')
                ->icon('heroicon-o-envelope')
                ->requiresConfirmation()
                ->action('sendEnrollmentSmsAndEmail'),
        ];
    }

    public function sendEnrollmentWhatsApp(): void
    {
        $this->sendEnrollment('whatsapp');
    }

    public function sendEnrollmentSmsAndEmail(): void
    {
        $this->sendEnrollment('sms_email');
    }

    protected function sendEnrollment(string $channel): void
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('admin', 'super_admin') || ! $this->canSendEnrollment()) {
            Notification::make()
                ->title('Permission denied')
                ->body('You do not have access to send enrolment messages.')
                ->danger()
                ->send();

            return;
        }

        try {
            /** @var EnrollmentMessageBuilder $builder */
            $builder = App::make(EnrollmentMessageBuilder::class);
            /** @var EnrollmentMessenger $messenger */
            $messenger = App::make(EnrollmentMessenger::class);

            $data = $builder->build($this->record);

            $result = $channel === 'whatsapp'
                ? $messenger->sendWhatsApp($data)
                : $messenger->sendSmsAndEmail($data);

            /** @var EnrollmentMessageRecorder $recorder */
            $recorder = App::make(EnrollmentMessageRecorder::class);
            $recorder->record($this->record, $channel, $data, $result, $user?->id);

            $notification = Notification::make()
                ->title($result->success ? 'Enrolment message sent' : 'Enrolment message failed')
                ->body($result->message);

            $result->success ? $notification->success() : $notification->danger();

            $notification->send();

            if ($result->success && ($this->record ?? null) instanceof Student) {
                $this->record->forceFill([
                    'enrollment_last_channel' => $channel,
                    'enrollment_last_sent_at' => now(),
                    'enrollment_last_sent_by_user_id' => $user?->id,
                ])->save();
                $this->record->refresh();
            } elseif (($this->record ?? null) instanceof Student) {
                $this->record->load('latestEnrollmentMessage');
            }
        } catch (EnrollmentMessageException $exception) {
            Notification::make()
                ->title('Cannot send enrolment message')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } catch (\Throwable $exception) {
            \Log::error('Enrolment message unexpected error', [
                'student_id' => $this->record->id ?? null,
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('Cannot send enrolment message')
                ->body('Unexpected error while sending. Please try again or check logs.')
                ->danger()
                ->send();
        }
    }

    protected function canSendEnrollment(): bool
    {
        $record = $this->record ?? null;
        if (! $record) {
            return false;
        }

        $location = strtolower((string) ($record->location ?? ''));

        if (property_exists($record, 'in_uk') && (bool) $record->in_uk) {
            return true;
        }

        $ukSlugs = ['uk', 'riverside', 'southbank', 'parkside', 'northgate'];

        return in_array($location, $ukSlugs, true);
    }
}
