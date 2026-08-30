<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AcuityService;

class AcuityCheckIds extends Command
{
    protected $signature = 'acuity:check-ids {--ids=} {--dispatch : Dispatch SyncAcuityAppointment for those that return data}';
    protected $description = 'Fetch each specified Acuity appointment ID and report availability (200 vs not found).';

    public function handle(): int
    {
        $idsOpt = (string) ($this->option('ids') ?? '');
        if ($idsOpt === '') {
            $this->error('--ids is required (comma-separated)');
            return self::FAILURE;
        }
        $ids = collect(preg_split('/\s*,\s*/', $idsOpt, -1, PREG_SPLIT_NO_EMPTY))
            ->unique()->values();
        $dispatch = (bool) $this->option('dispatch');

        $svc = new AcuityService();
        $ok = 0; $missing = 0; $errors = 0;
        foreach ($ids as $id) {
            try {
                $data = $svc->getAppointment($id);
                if (is_array($data) && !empty($data)) {
                    $ok++;
                    $this->line("OK \t{$id}");
                    if ($dispatch) {
                        \App\Jobs\SyncAcuityAppointment::dispatch($id)->onQueue('acuity');
                    }
                } else {
                    $missing++;
                    $this->line("MISS\t{$id}");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->line("ERR \t{$id}\t".$e->getMessage());
            }
        }
        $this->info("Summary: ok={$ok}, missing={$missing}, errors={$errors}");
        if ($dispatch && $ok > 0) {
            $this->info('Dispatched sync jobs for OK IDs. Run a worker to import them.');
        }
        return self::SUCCESS;
    }
}

