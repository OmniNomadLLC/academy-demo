<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SlackNotifier
{
    private ?string $token;
    private ?string $channel;
    private Client $http;

    public function __construct()
    {
        $this->token = config('services.slack.notifications.bot_user_oauth_token');
        $this->channel = config('services.slack.notifications.channel');
        $this->http = new Client(['base_uri' => 'https://slack.com/api/']);
    }

    public function enabled(): bool
    {
        return !empty($this->token) && !empty($this->channel);
    }

    public function post(string $text): bool
    {
        if (!$this->enabled()) {
            Log::warning('SlackNotifier: missing token or channel; skipping');
            return false;
        }
        try {
            $resp = $this->http->post('chat.postMessage', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'channel' => $this->channel,
                    'text' => $text,
                ],
                'timeout' => 10,
            ]);
            $ok = $resp->getStatusCode() === 200;
            if (!$ok) Log::warning('SlackNotifier: non-200', ['code' => $resp->getStatusCode()]);
            return $ok;
        } catch (\Throwable $e) {
            Log::error('SlackNotifier error: '.$e->getMessage());
            return false;
        }
    }
}

