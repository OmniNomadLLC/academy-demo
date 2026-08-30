<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentGeneralNotice extends Mailable
{
    use Queueable, SerializesModels;

    public string $bodyText;
    public string $subjectText;

    public function __construct(string $subject, string $body)
    {
        $this->subjectText = $subject;
        $this->bodyText = $body;
    }

    public function build()
    {
        $fromAddress = config('mail.from_overrides.admin.address', 'admin@example.test');
        $fromName = config('mail.from_overrides.admin.name', 'Lumina Admin');

        return $this->subject($this->subjectText)
            ->from($fromAddress, $fromName)
            ->view('emails.student_general_notice');
    }
}
