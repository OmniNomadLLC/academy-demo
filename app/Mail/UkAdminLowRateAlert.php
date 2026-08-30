<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UkAdminLowRateAlert extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;

    public function __construct(Student $student)
    {
        $this->student = $student;
    }

    public function build()
    {
        $subject = 'Low attendance alert – '.$this->student->full_name.' ('.$this->student->attendance_rate.'%)';
        return $this->subject($subject)
            ->view('emails.uk_admin_low_rate_alert');
    }
}

