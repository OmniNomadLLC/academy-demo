<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Acuity\AppointmentExtractor;
use App\Services\LocationMappingService;

class BackfillClassSessionMetadata extends Command
{
    protected $signature = 'backfill:class-session-metadata
        {--limit=0 : Max rows to process}
        {--dry : Dry run (no writes)}';

    protected $description = 'Normalize class_sessions: set calendar_norm, category_norm, and location where missing.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        $this->info('Starting backfill for class_sessions metadata'.($dry ? ' (dry run)' : '')); 

        $base = DB::table('class_sessions')
            ->select('id','acuity_data','calendar_name','calendar_norm','category_norm','location')
            ->where(function ($w) {
                $w->whereNull('calendar_norm')
                  ->orWhereRaw("TRIM(COALESCE(calendar_norm, '')) = ''")
                  ->orWhereNull('category_norm')
                  ->orWhereRaw("TRIM(COALESCE(category_norm, '')) = ''")
                  ->orWhereNull('location')
                  ->orWhereRaw("TRIM(COALESCE(location, '')) = ''");
            })
            ->orderBy('id');

        if ($limit > 0) {
            $base->limit($limit);
        }

        $total = (clone $base)->count();
        $this->info("Rows to process: {$total}");

        $processed = 0; $updated = 0; $errors = 0;
        $base->chunkById(500, function ($rows) use (&$processed, &$updated, &$errors, $dry) {
            foreach ($rows as $r) {
                $processed++;
                try {
                    $data = $r->acuity_data;
                    if (!is_array($data)) {
                        $decoded = json_decode((string) $data, true);
                        $data = is_array($decoded) ? $decoded : [];
                    }
                    $ex = AppointmentExtractor::extract($data ?? []);
                    $cal = $ex['calendar'] ?? null;
                    $calNorm = $ex['calendar_norm'] ?? null;
                    $cat = $ex['category'] ?? null;
                    $catNorm = $ex['category_norm'] ?? null;
                    $loc = null;
                    if (!empty($cat)) {
                        $loc = LocationMappingService::getLocationFromCategory($cat);
                    }

                    $payload = [];
                    if (empty($r->calendar_name) && !empty($cal)) { $payload['calendar_name'] = $cal; }
                    if (empty($r->calendar_norm) && !empty($calNorm)) { $payload['calendar_norm'] = $calNorm; }
                    if (empty($r->category_norm) && !empty($catNorm)) { $payload['category_norm'] = $catNorm; }
                    if ((empty($r->location) || trim((string)$r->location) === '') && !empty($loc)) { $payload['location'] = $loc; }

                    if (!empty($payload)) {
                        $updated++;
                        if (!$dry) {
                            DB::table('class_sessions')->where('id', $r->id)->update($payload);
                        }
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->warn('Row '.$r->id.' error: '.$e->getMessage());
                }
            }
        }, 'id');

        $this->info("Processed={$processed} updated={$updated} errors={$errors}");
        return self::SUCCESS;
    }
}

