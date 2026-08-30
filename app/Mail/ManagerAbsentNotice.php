<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ManagerAbsentNotice extends Mailable
{
    use Queueable, SerializesModels;

    public array $student;
    public object $session;

    public function __construct(array $student, object $session)
    {
        $this->student = $student;
        $this->session = $session;
    }

    public function build()
    {
        $name = $this->student['name'] ?? 'Student';
        $subject = "ESOL AUTOMATIC NOTIFICATION – {$name}";
        $fromConfig = config('mail.from_overrides.admin');

        return $this->subject($subject)
            ->from($fromConfig['address'] ?? config('mail.from.address'), $fromConfig['name'] ?? config('mail.from.name'))
            ->view('emails.manager_absent_notice');
    }
}
