<?php

namespace App\Services\Adzuna;

use App\Models\LuminaWorksJob;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AdzunaJobImporter
{
    /**
     * Normalise a page of Adzuna /search results into lumina_works_jobs rows.
     * Dedup key is (source, external_id) — same convention as
     * school_classes.external_id/external_source.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(array $results, string $source = 'adzuna'): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($results as $result) {
            $externalId = (string) ($result['id'] ?? '');
            $applyUrl = (string) ($result['redirect_url'] ?? '');
            $title = trim((string) ($result['title'] ?? ''));

            if ($externalId === '' || $applyUrl === '' || $title === '') {
                $skipped++;
                continue;
            }

            $attributes = [
                'title' => Str::limit(strip_tags($title), 250, ''),
                'description' => strip_tags((string) ($result['description'] ?? '')),
                'employer_name' => $result['company']['display_name'] ?? null,
                'location_name' => $result['location']['display_name'] ?? null,
                'latitude' => $result['latitude'] ?? null,
                'longitude' => $result['longitude'] ?? null,
                'region' => $result['location']['area'][1] ?? null,
                'category' => $result['category']['label'] ?? null,
                'contract_time' => $result['contract_time'] ?? null,
                'contract_type' => $result['contract_type'] ?? null,
                'salary_min' => $result['salary_min'] ?? null,
                'salary_max' => $result['salary_max'] ?? null,
                'apply_url' => $applyUrl,
                'posted_at' => isset($result['created']) ? Carbon::parse($result['created']) : null,
                'raw' => $result,
                'last_seen_at' => now(),
            ];

            $job = LuminaWorksJob::updateOrCreate(
                ['source' => $source, 'external_id' => $externalId],
                $attributes
            );

            $job->wasRecentlyCreated ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
