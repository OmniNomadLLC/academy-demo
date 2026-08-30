<?php

namespace App\Services\LuminaWorks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostcodeGeocoder
{
    /**
     * Resolve a UK postcode to lat/long via postcodes.io (free, no key, ONS data).
     * Returns ['latitude' => float, 'longitude' => float] or null when the
     * postcode is unknown or the service is unavailable.
     */
    public function geocode(string $postcode): ?array
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $postcode));

        if ($normalized === '' || strlen($normalized) > 8) {
            return null;
        }

        return Cache::remember("luminaworks:postcode:{$normalized}", now()->addDays(30), function () use ($normalized) {
            try {
                $response = Http::timeout(10)->get("https://api.postcodes.io/postcodes/{$normalized}");
            } catch (\Throwable $e) {
                Log::warning('Postcode geocode failed', ['error' => $e->getMessage()]);

                return null;
            }

            if (!$response->ok()) {
                return null;
            }

            $result = $response->json('result');

            if (!isset($result['latitude'], $result['longitude'])) {
                return null;
            }

            return [
                'latitude' => (float) $result['latitude'],
                'longitude' => (float) $result['longitude'],
            ];
        });
    }
}
