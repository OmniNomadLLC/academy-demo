<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestEnrollmentMessaging extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enroll:test {studentId} {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preview and optionally send enrollment messages for a student.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $studentId = (int) $this->argument('studentId');
        $dryRun = (bool) $this->option('dry-run');

        $student = \App\Models\Student::find($studentId);

        if (! $student) {
            $this->error("Student {$studentId} not found.");
            return self::FAILURE;
        }

        $builder = app(\App\Services\EnrollmentMessageBuilder::class);
        $messenger = app(\App\Services\EnrollmentMessenger::class);

        try {
            $data = $builder->build($student);
        } catch (\App\Exceptions\EnrollmentMessageException $exception) {
            $this->error('Unable to build enrollment message: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->info('WhatsApp body');
        $this->line($data->whatsappBody);
        $this->newLine();

        $this->info('SMS body');
        $this->line($data->smsBody);
        $this->newLine();

        $this->info('Email preview');
        $this->line($data->emailText);
        $this->newLine();

        if ($dryRun) {
            $this->comment('Dry run only — messages were not sent.');
            return self::SUCCESS;
        }

        $whatsAppResult = $messenger->sendWhatsApp($data);
        $smsResult = $messenger->sendSmsAndEmail($data);

        $this->line(sprintf('WhatsApp: %s', $whatsAppResult->message));
        $this->line(sprintf('SMS + Email: %s', $smsResult->message));

        if (! $whatsAppResult->success || ! $smsResult->success) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
