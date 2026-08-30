<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DatabaseDumpFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Throwable $exception)
    {
    }

    public function build(): self
    {
        return $this->subject('[ALERT] Database dump failed')
            ->view('emails.backups.dump-failed')
            ->with([
                'exception' => $this->exception,
            ]);
    }
}
