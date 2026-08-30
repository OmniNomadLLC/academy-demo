<?php

namespace App\Jobs;

use App\Mail\StudentGeneralNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendStudentBlast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $emails;
    public string $subject;
    public string $message;

    public function __construct(array $emails, string $subject, string $message)
    {
        $this->emails = $emails;
        $this->subject = $subject;
        $this->message = $message;
    }

    public function handle(): void
    {
        foreach ($this->emails as $em) {
            try {
                Mail::to($em)->queue(new StudentGeneralNotice($this->subject, $this->message));
            } catch (\Throwable $e) {
            }
        }
    }
}
