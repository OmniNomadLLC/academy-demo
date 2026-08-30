<?php

namespace App\Console\Commands;

use App\Filament\Pages\LuminaWorksDashboard;
use Illuminate\Console\Command;

class LuminaWorksExportFunnel extends Command
{
    protected $signature = 'luminaworks:export-funnel';

    protected $description = 'Export the Lumina Works pilot funnel + placements as CSV (provider report)';

    public function handle(): int
    {
        if (!config('luminaworks.enabled')) {
            $this->warn('Lumina Works is disabled (LUMINAWORKS_ENABLED=false).');

            return self::SUCCESS;
        }

        $page = new LuminaWorksDashboard();
        $funnel = $page->getFunnel();
        $placements = $page->getPlacements();

        $dir = storage_path('app/luminaworks_evidence');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/funnel-' . now()->format('Ymd-His') . '.csv';

        $out = fopen($path, 'w');
        fputcsv($out, ['metric', 'value']);
        foreach ($funnel as $metric => $value) {
            fputcsv($out, [$metric, $value]);
        }
        fputcsv($out, []);
        fputcsv($out, ['placement_student', 'job', 'employer', 'started', 'weeks_of_26', 'target_date', 'employer_confirmed', 'note']);
        foreach ($placements as $placement) {
            fputcsv($out, [
                $placement['student'],
                $placement['job'],
                $placement['employer'],
                $placement['started'],
                $placement['weeks'],
                $placement['target_date'],
                $placement['employer_confirmed'] ? 'yes' : 'no',
                'provider-tracked, not HMRC-verified',
            ]);
        }
        fclose($out);

        $this->info("Funnel export: {$path}");

        return self::SUCCESS;
    }
}
