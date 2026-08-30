<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BurstAcuityQueue extends Command
{
    protected $signature = 'queue:burst-acuity {--processes=4 : Number of worker processes}';
    protected $description = 'Spawn multiple fast Redis workers for a 1-hour local burst';

    public function handle(): int
    {
        $processes = (int) $this->option('processes');
        if ($processes < 1) {
            $this->error('Processes must be >= 1');
            return self::FAILURE;
        }

        $this->info("Starting {$processes} burst workers (sleep=0, tries=1, 1h max)...");

        $cmd = sprintf(
            '%s %s queue:work redis --queue=high,default --sleep=0 --timeout=300 --tries=1 --backoff=1 --max-time=3600',
            PHP_BINARY,
            escapeshellarg(base_path('artisan'))
        );

        $procs = [];
        for ($i = 0; $i < $processes; $i++) {
            $procs[$i] = Process::fromShellCommandline($cmd, base_path());
            $procs[$i]->setTimeout(null);
            $procs[$i]->start();
        }

        // Stream output briefly and then wait until completion
        foreach ($procs as $idx => $proc) {
            $proc->wait(function ($type, $buffer) use ($idx) {
                if ($type === Process::OUT) {
                    $this->output->write("[W{$idx}] ".$buffer);
                } else {
                    $this->output->write("[W{$idx} ERR] ".$buffer);
                }
            });
        }

        $this->info('Burst complete.');
        return self::SUCCESS;
    }
}

