<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AcuityService
{
    private $client;
    private int $maxRetries = 5;
    private int $retryBaseMs = 500;
    private $userId;
    private $apiKey;
    private $baseUrl;

    public function __construct()
    {
        $this->userId = config('services.acuity.user_id');
        $this->apiKey = config('services.acuity.api_key');
        $this->baseUrl = config('services.acuity.base_url');

        if (!$this->userId || !$this->apiKey) {
            throw new \Exception('Acuity API credentials not configured');
        }

        $this->maxRetries = (int) env('ACUITY_RETRY_MAX', 5);
        $this->retryBaseMs = (int) env('ACUITY_RETRY_BASE_MS', 500);
        $this->buildClient();
    }

    private function buildClient(): void
    {
        $stack = HandlerStack::create();
        $max = $this->maxRetries;
        $base = $this->retryBaseMs;
        $decider = function ($retries, RequestInterface $request, ?ResponseInterface $response = null, ?TransferException $exception = null) use ($max, $base) {
            if ($retries >= $max) return false;
            if ($exception) {
                $next = $base * (2 ** $retries) + random_int(0, 250);
                Log::warning('Acuity retry (exception)', [
                    'attempt' => $retries + 1,
                    'next_backoff_ms' => $next,
                    'error' => $exception->getMessage(),
                ]);
                return true;
            }
            if ($response) {
                $code = $response->getStatusCode();
                if ($code == 429 || ($code >= 500 && $code < 600)) {
                    $next = $base * (2 ** $retries) + random_int(0, 250);
                    Log::warning('Acuity retry (response)', [
                        'attempt' => $retries + 1,
                        'status' => $code,
                        'reason' => $response->getReasonPhrase(),
                        'next_backoff_ms' => $next,
                    ]);
                    return true;
                }
            }
            return false;
        };
        $delay = function ($retries) use ($base) { return $base * (2 ** $retries) + random_int(0, 250); };
        $stack->push(Middleware::retry($decider, $delay));

        $this->client = new Client([
            'handler' => $stack,
            'base_uri' => $this->baseUrl,
            'auth' => [$this->userId, $this->apiKey],
            'headers' => [
                'Accept' => 'application/json',
            ],
            'connect_timeout' => 10,
            'read_timeout' => 25,
            'timeout' => 30,
            'verify' => true,
            'http_errors' => false,
        ]);
    }

    public function setRetryConfig(int $maxRetries, int $retryBaseMs): void
    {
        $this->maxRetries = $maxRetries;
        $this->retryBaseMs = $retryBaseMs;
        $this->buildClient();
    }

    public function setTimeouts(int $timeoutSeconds, int $connectTimeoutSeconds = 10): void
    {
        // Rebuild client with updated timeouts while preserving other settings
        $this->buildClient();
        $stack = HandlerStack::create();
        $max = $this->maxRetries; $base = $this->retryBaseMs;
        $decider = function ($retries, RequestInterface $request, ?ResponseInterface $response = null, ?TransferException $exception = null) use ($max, $base) {
            if ($retries >= $max) return false;
            if ($exception) return true;
            if ($response) {
                $code = $response->getStatusCode();
                if ($code == 429 || ($code >= 500 && $code < 600)) return true;
            }
            return false;
        };
        $delay = function ($retries) use ($base) { return $base * (2 ** $retries) + random_int(0, 250); };
        $stack->push(Middleware::retry($decider, $delay));

        $this->client = new Client([
            'handler' => $stack,
            'base_uri' => $this->baseUrl,
            'auth' => [$this->userId, $this->apiKey],
            'headers' => [ 'Accept' => 'application/json' ],
            'connect_timeout' => max(1, $connectTimeoutSeconds),
            'timeout' => max(1, $timeoutSeconds),
            'verify' => true,
            'http_errors' => false,
        ]);
    }

    /**
     * Get appointments from Acuity
     */
    public function getAppointments($params = [])
    {
        try {
            $cacheKey = 'acuity_appointments_' . md5(serialize($params));

            return Cache::remember($cacheKey, 300, function () use ($params) {
                $all = [];
                $page = 1;
                $perPage = isset($params['max']) ? (int) $params['max'] : 100;
                if ($perPage <= 0) $perPage = 100;

                // Hard cap to prevent infinite loops in case API misbehaves
                $maxPages = 200;

                do {
                    $query = array_merge([
                        'max' => $perPage,
                        'page' => $page,
                    ], $params);

                    $response = $this->client->get('appointments', [ 'query' => $query ]);
                    $status = $response->getStatusCode();
                    if ($status !== 200) {
                        Log::warning('Acuity: appointments request returned status '.$status.' for page '.$page);
                        break;
                    }

                    $data = json_decode($response->getBody()->getContents(), true) ?: [];
                    $count = is_array($data) ? count($data) : 0;
                    Log::info('Fetched page '.$page.' with '.$count.' appointments');

                    if ($count > 0 && is_array($data)) {
                        $all = array_merge($all, $data);
                    }

                    $page++;
                } while ($count === $perPage && $page <= $maxPages);

                Log::info('Acuity: Total aggregated appointments: '.count($all));

                return $all;
            });

        } catch (RequestException $e) {
            Log::error('Acuity API Error (appointments): ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch a single appointments page with given params + page & perPage.
     */
    public function fetchAppointmentsPage(array $params, int $page, int $perPage = 100): array
    {
        @ini_set('max_execution_time', '0');
        if (function_exists('set_time_limit')) { @set_time_limit(0); }

        $query = array_merge(['max' => $perPage, 'page' => $page], $params);
        $response = $this->client->get('appointments', ['query' => $query]);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            Log::warning('Acuity: fetchAppointmentsPage status '.$status.' page '.$page);
            return [];
        }
        $data = json_decode($response->getBody()->getContents(), true) ?: [];
        $count = is_array($data) ? count($data) : 0;
        Log::info('Fetched page '.$page.' with '.$count.' appointments');
        return is_array($data) ? $data : [];
    }

    /**
     * Return the number of HTTP retries since last check, and reset the counter.
     */
    public function getAndResetRetryCount(): int
    {
        $n = $this->retryCount ?? 0;
        $this->retryCount = 0;
        return $n;
    }

    /**
     * Get clients from Acuity
     */
    public function getClients($params = [])
    {
        try {
            $cacheKey = 'acuity_clients_' . md5(serialize($params));
            
            return Cache::remember($cacheKey, 600, function () use ($params) {
                $response = $this->client->get('clients', [
                    'query' => array_merge([
                        'max' => 100,
                    ], $params)
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                Log::info('Acuity: Fetched ' . count($data) . ' clients');
                
                return $data;
            });
            
        } catch (RequestException $e) {
            Log::error('Acuity API Error (clients): ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get appointment types from Acuity
     */
    public function getAppointmentTypes()
    {
        try {
            return Cache::remember('acuity_appointment_types', 3600, function () {
                $response = $this->client->get('appointment-types');
                $data = json_decode($response->getBody()->getContents(), true);
                
                Log::info('Acuity: Fetched ' . count($data) . ' appointment types');
                
                return $data;
            });
            
        } catch (RequestException $e) {
            Log::error('Acuity API Error (appointment-types): ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get calendars from Acuity
     */
    public function getCalendars(): array
    {
        try {
            return Cache::remember('acuity_calendars', 600, function () {
                $response = $this->client->get('calendars');
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    Log::warning('Acuity: calendars returned status '.$status);
                    return [];
                }
                $data = json_decode($response->getBody()->getContents(), true) ?: [];
                if (!is_array($data)) return [];
                Log::info('Acuity: Fetched '.count($data).' calendars');
                return $data;
            });
        } catch (RequestException $e) {
            Log::error('Acuity API Error (calendars): '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get a single appointment by ID
     */
    public function getAppointment($appointmentId)
    {
        try {
            $response = $this->client->get("appointments/{$appointmentId}");
            $status = $response->getStatusCode();

            if ($status !== 200) {
                // Return null so callers can gracefully handle missing/errored responses
                Log::warning("Acuity getAppointment {$appointmentId} returned status {$status}");
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                Log::warning("Acuity getAppointment {$appointmentId} returned invalid JSON payload");
                return null;
            }

            return $data;
            
        } catch (RequestException $e) {
            Log::error("Acuity API Error (appointment {$appointmentId}): " . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Get a single client by ID
     */
    public function getClient($clientId)
    {
        try {
            $response = $this->client->get("clients/{$clientId}");
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (RequestException $e) {
            Log::error("Acuity API Error (client {$clientId}): " . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Test API connection
     */
    public function testConnection()
    {
        try {
            $response = $this->client->get('appointment-types', [
                'query' => ['max' => 1]
            ]);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                Log::info('Acuity: API connection test successful');
                return true;
            }
            
            return false;
            
        } catch (RequestException $e) {
            Log::error('Acuity: API connection test failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get recent appointments (last 30 days)
     */
    public function getRecentAppointments()
    {
        $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d');
        
        return $this->getAppointments([
            'minDate' => $thirtyDaysAgo,
            'max' => 200
        ]);
    }

    /**
     * Get upcoming appointments (next 30 days)
     */
    public function getUpcomingAppointments()
    {
        $today = now()->format('Y-m-d');
        $thirtyDaysFromNow = now()->addDays(30)->format('Y-m-d');
        
        return $this->getAppointments([
            'minDate' => $today,
            'maxDate' => $thirtyDaysFromNow,
            'max' => 200
        ]);
    }

    

}
