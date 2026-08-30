<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AcuityService;

class ListAcuityCalendars extends Command
{
    protected $signature = 'acuity:list-calendars {--json : Output raw JSON}';
    protected $description = 'List calendars from Acuity (id and name)';

    public function handle(): int
    {
        $svc = new AcuityService();
        $cals = $svc->getCalendars();
        if ($this->option('json')) {
            $this->line(json_encode($cals));
            return self::SUCCESS;
        }
        $this->info('Calendars:');
        foreach ($cals as $c) {
            $id = $c['id'] ?? '';
            $name = $c['name'] ?? ($c['calendar'] ?? '');
            $this->line("  {$id}	{$name}");
        }
        return self::SUCCESS;
    }
}

