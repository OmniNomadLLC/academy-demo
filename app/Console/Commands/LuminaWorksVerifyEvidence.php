<?php

namespace App\Console\Commands;

use App\Models\LuminaWorksActivityLog;
use App\Services\LuminaWorks\EvidenceLogger;
use Illuminate\Console\Command;

class LuminaWorksVerifyEvidence extends Command
{
    protected $signature = 'luminaworks:verify-evidence {--student= : Limit to one student id}';

    protected $description = 'Verify the hash chain of the Lumina Works evidence log';

    public function handle(EvidenceLogger $logger): int
    {
        $studentIds = $this->option('student')
            ? [(int) $this->option('student')]
            : LuminaWorksActivityLog::distinct()->pluck('student_id')->all();

        $allIntact = true;

        foreach ($studentIds as $id) {
            $broken = $logger->verifyChain($id);
            if ($broken === []) {
                $this->line("Student {$id}: chain intact");
            } else {
                $allIntact = false;
                $this->error("Student {$id}: chain BROKEN at rows " . implode(', ', $broken));
            }
        }

        if ($studentIds === []) {
            $this->info('No evidence rows yet.');
        }

        return $allIntact ? self::SUCCESS : self::FAILURE;
    }
}
