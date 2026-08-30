<?php

namespace App\Console\Commands;

use App\Services\Adzuna\AdzunaJobImporter;
use App\Services\Adzuna\AdzunaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LuminaWorksSyncJobs extends Command
{
    protected $signature = 'luminaworks:sync-jobs
        {--where= : Location filter (e.g. "Northgate" or "London")}
        {--distance= : Radius in km around --where}
        {--category= : Adzuna category tag (e.g. logistics-warehouse-jobs)}
        {--pages= : Pages to pull (default from config luminaworks.pull.max_pages)}
        {--fixture= : Import from a local JSON fixture instead of the live API}';

    protected $description = 'Pull UK entry-level vacancies from Adzuna into lumina_works_jobs (Lumina Works)';

    public function handle(AdzunaJobImporter $importer): int
    {
        if (!config('luminaworks.enabled')) {
            $this->warn('Lumina Works is disabled (LUMINAWORKS_ENABLED=false). Nothing pulled.');

            return self::SUCCESS;
        }

        if ($fixture = $this->option('fixture')) {
            return $this->importFixture($importer, $fixture);
        }

        $service = new AdzunaService();
        $pages = (int) ($this->option('pages') ?: config('luminaworks.pull.max_pages'));
        $params = array_filter([
            'where' => $this->option('where'),
            'distance' => $this->option('distance'),
            'category' => $this->option('category'),
            'results_per_page' => config('luminaworks.pull.results_per_page'),
            'max_days_old' => config('luminaworks.pull.max_days_old'),
            'sort_by' => 'date',
        ]);

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        for ($page = 1; $page <= $pages; $page++) {
            $data = $service->search($page, $params);
            $results = $data['results'] ?? [];

            if ($results === []) {
                break;
            }

            $stats = $importer->import($results);
            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }

            $this->info("Page {$page}: created {$stats['created']}, updated {$stats['updated']}, skipped {$stats['skipped']}");
        }

        Log::info('Lumina Works job sync completed', $totals + ['params' => $params, 'pages' => $pages]);
        $this->info("Done: created {$totals['created']}, updated {$totals['updated']}, skipped {$totals['skipped']}.");

        return self::SUCCESS;
    }

    private function importFixture(AdzunaJobImporter $importer, string $path): int
    {
        if (!is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $results = $data['results'] ?? null;

        if (!is_array($results)) {
            $this->error('Fixture must be an Adzuna /search response with a "results" array.');

            return self::FAILURE;
        }

        $stats = $importer->import($results);
        $this->info("Fixture import: created {$stats['created']}, updated {$stats['updated']}, skipped {$stats['skipped']}.");

        return self::SUCCESS;
    }
}
