<?php

namespace Tests\Unit\Support;

use App\Support\QueueStatusResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class QueueStatusResolverTest extends TestCase
{
    protected $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new class {
            public array $supervisors = [];

            public function all(): array
            {
                return $this->supervisors;
            }
        };

        $this->app->instance('horizon.supervisorRepository', $this->repo);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Artisan::clearResolvedInstances();
        Redis::clearResolvedInstances();
        Mockery::close();
    }

    public function test_sync_driver_reports_without_redis_check(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.redis' => []]);

        Artisan::shouldReceive('call')->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Horizon is running');
        $this->repo->supervisors = [];

        $status = (new QueueStatusResolver())->resolve();

        $this->assertSame('sync', $status['driver']);
        $this->assertNull($status['redis_available']);
        $this->assertTrue($status['horizon_running']);
        $this->assertSame(0, $status['active_processes']);
    }

    public function test_redis_driver_reports_available_when_ping_succeeds(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.connection' => 'default']);

        $mock = Mockery::mock();
        $mock->shouldReceive('ping')->once();
        $mock->shouldReceive('info')->once()->andReturn(['Server' => ['redis_version' => '6.2.0']]);

        Redis::shouldReceive('connection')->with('default')->andReturn($mock);
        Artisan::shouldReceive('call')->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Horizon is running');
        $this->repo->supervisors = [
            (object) ['processes' => 3, 'name' => 'sup', 'queue' => 'high,acuity', 'status' => 'running', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 8],
        ];

        $status = (new QueueStatusResolver())->resolve();

        $this->assertTrue($status['redis_available']);
        $this->assertSame('6.2.0', $status['redis_version']);
        $this->assertTrue($status['horizon_running']);
        $this->assertSame(3, $status['active_processes']);
    }

    public function test_redis_driver_reports_unavailable_when_connection_fails(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.connection' => 'default']);

        Redis::shouldReceive('connection')->with('default')->andThrow(new \Exception('Connection refused'));
        Artisan::shouldReceive('call')->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Horizon inactive');
        $this->repo->supervisors = [];

        $status = (new QueueStatusResolver())->resolve();

        $this->assertFalse($status['redis_available']);
        $this->assertSame('Connection refused', $status['redis_error']);
        $this->assertFalse($status['horizon_running']);
    }
}
