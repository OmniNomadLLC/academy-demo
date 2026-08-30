<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;

class QueueStatusResolver
{
    public function resolve(): array
    {
        $driver = config('queue.default');
        $appEnv = app()->environment();

        $redisAvailable = null;
        $redisError = null;
        $redisVersion = null;

        if ($this->shouldCheckRedis($driver)) {
            $connectionName = config('queue.connections.redis.connection', 'default');
            try {
                $connection = Redis::connection($connectionName);
                $connection->ping();
                $redisAvailable = true;
                $info = $connection->info();
                $redisVersion = Arr::get($info, 'Server.redis_version', Arr::get($info, 'redis_version'));
            } catch (\Throwable $e) {
                $redisAvailable = false;
                $redisError = $e->getMessage();
            }
        }

        $horizonState = $this->detectHorizon();
        $configured = $this->configuredSupervisors($appEnv);
        $queueNames = collect($configured)->flatMap(fn ($sup) => $sup['queues'])->unique()->values()->all();

        return [
            'driver' => $driver,
            'app_env' => $appEnv,
            'redis_available' => $redisAvailable,
            'redis_error' => $redisError,
            'redis_version' => $redisVersion,
            'horizon_running' => $horizonState['running'],
            'horizon_status' => $horizonState['status'],
            'horizon_output' => $horizonState['output'],
            'horizon_version' => $horizonState['version'],
            'supervised_queues' => $queueNames,
            'configured_supervisors' => $configured,
            'supervisors' => $horizonState['supervisors'],
            'active_processes' => $horizonState['active_processes'],
        ];
    }

    protected function shouldCheckRedis(string $driver): bool
    {
        if ($driver === 'redis') {
            return true;
        }

        return ! empty(config('queue.connections.redis'));
    }

    protected function configuredSupervisors(string $env): array
    {
        $config = config("horizon.environments.$env") ?? config('horizon.environments.production') ?? [];

        $details = [];
        foreach ($config as $name => $settings) {
            $details[] = [
                'name' => $name,
                'queues' => array_values((array) ($settings['queue'] ?? [])),
                'balance' => $settings['balance'] ?? null,
                'minProcesses' => $settings['minProcesses'] ?? null,
                'maxProcesses' => $settings['maxProcesses'] ?? null,
                'processes' => $settings['processes'] ?? null,
            ];
        }

        return $details;
    }

    protected function detectHorizon(): array
    {
        if (! class_exists(Horizon::class)) {
            return [
                'running' => false,
                'status' => 'unavailable',
                'output' => null,
                'version' => null,
                'supervisors' => [],
                'active_processes' => 0,
            ];
        }

        $status = 'unknown';
        $output = null;
        $running = false;
        $activeProcesses = 0;
        $supervisors = [];

        try {
            $code = Artisan::call('horizon:status');
            $output = trim(Artisan::output());

            if ($code === 0) {
                if (stripos($output, 'running') !== false) {
                    $status = 'running';
                    $running = true;
                } elseif (stripos($output, 'inactive') !== false) {
                    $status = 'inactive';
                } else {
                    $status = 'unknown';
                }
            } else {
                $status = 'error';
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $output = $e->getMessage();
        }

        $version = null;
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $version = \Composer\InstalledVersions::getPrettyVersion('laravel/horizon');
            }
        } catch (\Throwable $e) {
            $version = null;
        }

        try {
            $raw = collect();

            if (app()->bound('horizon.supervisorRepository')) {
                $repository = app('horizon.supervisorRepository');
                if (method_exists($repository, 'all')) {
                    $raw = collect($repository->all());
                }
            } elseif (class_exists(Horizon::class) && method_exists(Horizon::class, 'supervisors')) {
                $raw = collect(Horizon::supervisors());
            }

            $supervisors = $raw->map(function ($supervisor) {
                return [
                    'name' => $supervisor->name ?? null,
                    'connection' => $supervisor->connection ?? null,
                    'queue' => $supervisor->queue ?? null,
                    'processes' => (int) ($supervisor->processes ?? 0),
                    'status' => $supervisor->status ?? null,
                    'balance' => $supervisor->balance ?? null,
                    'minProcesses' => $supervisor->minProcesses ?? null,
                    'maxProcesses' => $supervisor->maxProcesses ?? null,
                ];
            })->values()->all();
            $activeProcesses = $raw->sum(function ($supervisor) {
                return (int) ($supervisor->processes ?? 0);
            });
        } catch (\Throwable $e) {
            $supervisors = [];
            $activeProcesses = 0;
        }

        return [
            'running' => $running,
            'status' => $status,
            'output' => $output,
            'version' => $version,
            'supervisors' => $supervisors,
            'active_processes' => $activeProcesses,
        ];
    }
}
