<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BackupHealthReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $report)
    {
    }

    public function build(): self
    {
        return $this->subject('[Backups] Nightly health report')
            ->view('emails.backups.health-report')
            ->with([
                'report' => $this->report,
                'hasFailures' => collect($this->report)->contains(fn ($row) => ($row['status'] ?? 'healthy') !== 'healthy'),
            ]);
    }
}
