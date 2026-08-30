<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeEnrollment extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;

    public function __construct(Student $student)
    {
        $this->student = $student;
    }

    public function build()
    {
        $name = trim(($this->student->first_name ?? '').' '.($this->student->last_name ?? '')) ?: 'Welcome';
        return $this->subject('Welcome to Lumina Language Academy')
            ->view('emails.welcome_enrollment')
            ->with(['name' => $name]);
    }
}

