<?php

namespace App\Mail;

use App\Data\EnrollmentMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EnrollmentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EnrollmentMessageData $messageData)
    {
    }

    public function build(): self
    {
        $fromConfig = config('mail.from_overrides.admin');

        return $this->subject('Your ESOL enrolment details')
            ->from($fromConfig['address'] ?? config('mail.from.address'), $fromConfig['name'] ?? config('mail.from.name'))
            ->view('emails.enrollment_mail')
            ->with([
                'bodyHtml' => $this->messageData->emailHtml,
                'preheader' => $this->messageData->context['preheader'] ?? 'Your class starts soon.',
                'reference' => $this->messageData->context['reference'] ?? null,
                'student' => $this->messageData->student,
                'context' => $this->messageData->context,
            ]);
    }
}
