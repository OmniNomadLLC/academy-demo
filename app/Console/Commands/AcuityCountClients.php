<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AcuityService;
use Illuminate\Support\Facades\Cache;

class AcuityCountClients extends Command
{
    protected $signature = 'acuity:count-clients {--page-size=200 : Page size per request (1-1000)}';
    protected $description = 'Print the number of clients in Acuity (via API)';

    public function handle(): int
    {
        try {
            $pageSize = min(max((int) $this->option('page-size'), 1), 1000);
            $svc = new AcuityService();
            $total = 0; $page = 1;
            do {
                $list = $svc->getClients(['max' => $pageSize, 'page' => $page]);
                $n = is_array($list) ? count($list) : 0;
                $total += $n;
                $page++;
            } while ($n === $pageSize && $page <= 10000);
            // Cache for dashboards to avoid network in request lifecycle
            Cache::put('acuity_client_count', $total, 600);
            Cache::put('acuity_client_count_at', now()->toDateTimeString(), 600);
            $this->info((string) $total);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to fetch from Acuity: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
