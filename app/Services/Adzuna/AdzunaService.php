<?php

namespace App\Services\Adzuna;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AdzunaService
{
    private Client $client;
    private string $appId;
    private string $appKey;
    private string $baseUrl;
    private string $country;
    private int $maxRetries;
    private int $retryBaseMs;

    public function __construct()
    {
        $this->appId = (string) config('services.adzuna.app_id');
        $this->appKey = (string) config('services.adzuna.app_key');
        $this->baseUrl = (string) config('services.adzuna.base_url');
        $this->country = (string) config('services.adzuna.country', 'gb');

        if (!$this->appId || !$this->appKey) {
            throw new \Exception('Adzuna API credentials not configured');
        }

        $this->maxRetries = (int) env('ADZUNA_RETRY_MAX', 3);
        $this->retryBaseMs = (int) env('ADZUNA_RETRY_BASE_MS', 1000);
        $this->buildClient();
    }

    private function buildClient(): void
    {
        $stack = HandlerStack::create();
        $max = $this->maxRetries;
        $base = $this->retryBaseMs;
        $decider = function ($retries, RequestInterface $request, ?ResponseInterface $response = null, ?TransferException $exception = null) use ($max, $base) {
            if ($retries >= $max) {
                return false;
            }
            if ($exception) {
                Log::warning('Adzuna retry (exception)', [
                    'attempt' => $retries + 1,
                    'error' => $exception->getMessage(),
                ]);

                return true;
            }
            if ($response) {
                $code = $response->getStatusCode();
                if ($code == 429 || ($code >= 500 && $code < 600)) {
                    Log::warning('Adzuna retry (response)', [
                        'attempt' => $retries + 1,
                        'status' => $code,
                    ]);

                    return true;
                }
            }

            return false;
        };
        $delay = fn ($retries) => $base * (2 ** $retries) + random_int(0, 250);
        $stack->push(Middleware::retry($decider, $delay));

        $this->client = new Client([
            'handler' => $stack,
            'base_uri' => $this->baseUrl,
            'headers' => ['Accept' => 'application/json'],
            'connect_timeout' => 10,
            'timeout' => 30,
        ]);
    }

    /**
     * Search vacancies. $params maps straight onto Adzuna /search query params:
     * where, distance, category, full_time, part_time, salary_min, max_days_old,
     * results_per_page, what, what_exclude, sort_by.
     * Free tier: 25 calls/min, 250/day — callers must keep page counts low.
     */
    public function search(int $page = 1, array $params = []): array
    {
        $query = array_merge($params, [
            'app_id' => $this->appId,
            'app_key' => $this->appKey,
        ]);

        $response = $this->client->get("jobs/{$this->country}/search/{$page}", [
            'query' => $query,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || !array_key_exists('results', $data)) {
            throw new \RuntimeException('Unexpected Adzuna response shape');
        }

        return $data;
    }
}
