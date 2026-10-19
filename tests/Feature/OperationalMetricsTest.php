<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class OperationalMetricsTest extends TestCase
{
    public function test_runtime_events_are_aggregated_without_recording_cache_keys_sql_or_bindings(): void
    {
        $recordedMetrics = [];
        $this->mockRedisPipeline($recordedMetrics);
        Log::spy();

        Event::dispatch(new CacheHit('array', 'private-cache-key', 'value'));
        Event::dispatch(new CacheMissed('array', 'another-private-cache-key'));
        Event::dispatch(new QueryExecuted(
            'select * from users where email = ?',
            ['private@example.test'],
            700,
            DB::connection(),
        ));

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['created_at' => microtime(true) - 61]);
        $job->shouldReceive('getQueue')->andReturn('default');
        Event::dispatch(new JobProcessing('redis', $job));

        $scheduledTask = Mockery::mock(ScheduledEvent::class);
        $scheduledTask->shouldReceive('getSummaryForDisplay')->andReturn('/usr/bin/php artisan notifications:dispatch-status-changes');
        Event::dispatch(new ScheduledTaskFinished($scheduledTask, 6));

        app(\App\Services\OperationalMetricsService::class)->recordApiRequest('api.dashboard', 'GET', 200, 1250);

        $this->assertSame(1, $recordedMetrics['cache_hits']);
        $this->assertSame(1, $recordedMetrics['cache_misses']);
        $this->assertSame(1, $recordedMetrics['slow_queries']);
        $this->assertSame(700, $recordedMetrics['slow_query_duration_ms']);
        $this->assertSame(1, $recordedMetrics['queue_jobs']);
        $this->assertSame(1, $recordedMetrics['queue_slow_jobs']);
        $this->assertGreaterThanOrEqual(60_000, $recordedMetrics['queue_lag_ms']);
        $this->assertSame(1, $recordedMetrics['scheduler_runs']);
        $this->assertSame(6000, $recordedMetrics['scheduler_duration_ms']);
        $this->assertSame(1, $recordedMetrics['scheduler_slow_runs']);
        $this->assertSame(1, $recordedMetrics['api_requests']);
        $this->assertSame(1250, $recordedMetrics['api_duration_ms']);
        $this->assertSame(1, $recordedMetrics['api_slow_requests']);

        Log::shouldHaveReceived('warning')->with('webguard.operational_metrics.slow_query', Mockery::on(
            static fn (array $context): bool => isset($context['fingerprint'], $context['duration_ms'])
                && ! array_key_exists('sql', $context)
                && ! array_key_exists('bindings', $context),
        ))->once();
        Log::shouldHaveReceived('warning')->with('webguard.operational_metrics.slow_api_request', Mockery::on(
            static fn (array $context): bool => $context['route'] === 'api.dashboard'
                && $context['method'] === 'GET'
                && $context['status'] === 200
                && $context['duration_ms'] === 1250,
        ))->once();
    }

    public function test_previous_minute_metrics_are_reported_with_derived_rates(): void
    {
        $key = 'webguard:operational-metrics:' . now()->subMinute()->startOfMinute()->format('YmdHi');
        $redis = Mockery::mock();
        $redis->shouldReceive('hgetall')->once()->with($key)->andReturn([
            'api_requests' => '4',
            'api_duration_ms' => '1000',
            'cache_hits' => '3',
            'cache_misses' => '1',
            'queue_jobs' => '2',
            'queue_lag_ms' => '600',
            'scheduler_runs' => '1',
            'scheduler_duration_ms' => '500',
        ]);
        $redis->shouldReceive('del')->once()->with($key)->andReturn(1);
        Redis::shouldReceive('connection')->with('cache')->once()->andReturn($redis);
        Log::spy();

        app(\App\Services\OperationalMetricsService::class)->flushPreviousMinute();

        Log::shouldHaveReceived('info')->with('webguard.operational_metrics.window', Mockery::on(
            static fn (array $context): bool => $context['metrics']['api_average_duration_ms'] === 250.0
                && $context['metrics']['cache_hit_rate'] === 0.75
                && $context['metrics']['queue_average_lag_ms'] === 300.0
                && $context['metrics']['scheduler_average_duration_ms'] === 500.0,
        ))->once();
    }

    public function test_api_middleware_records_request_count_and_latency(): void
    {
        $recordedMetrics = [];
        $this->mockRedisPipeline($recordedMetrics);
        Package::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/session')->assertOk();

        $this->assertSame(1, $recordedMetrics['api_requests']);
        $this->assertGreaterThanOrEqual(0, $recordedMetrics['api_duration_ms']);
    }

    public function test_metrics_command_reports_both_operational_queues_and_warns_at_the_backlog_threshold(): void
    {
        $queue = Mockery::mock();
        $queue->shouldReceive('size')->with('default')->once()->andReturn(5);
        $queue->shouldReceive('size')->with('heartbeat')->once()->andReturn(100);
        Queue::shouldReceive('connection')->with('sync')->once()->andReturn($queue);

        $redis = Mockery::mock();
        $redis->shouldReceive('hgetall')->once()->andReturn([]);
        $redis->shouldReceive('del')->once()->andReturn(1);
        Redis::shouldReceive('connection')->with('cache')->once()->andReturn($redis);
        Log::spy();

        $this->artisan('webguard:report-operational-metrics')->assertSuccessful();

        Log::shouldHaveReceived('info')->with('webguard.operational_metrics.queue_backlog', Mockery::on(
            static fn (array $context): bool => $context['queue'] === 'default' && $context['backlog_jobs'] === 5,
        ))->once();
        Log::shouldHaveReceived('info')->with('webguard.operational_metrics.queue_backlog', Mockery::on(
            static fn (array $context): bool => $context['queue'] === 'heartbeat' && $context['backlog_jobs'] === 100,
        ))->once();
        Log::shouldHaveReceived('warning')->with('webguard.operational_metrics.queue_backlog_threshold_exceeded', Mockery::on(
            static fn (array $context): bool => $context['queue'] === 'heartbeat' && $context['backlog_jobs'] === 100,
        ))->once();
    }

    /** @param array<string, int> $recordedMetrics */
    private function mockRedisPipeline(array &$recordedMetrics): void
    {
        $pipeline = Mockery::mock();
        $pipeline->shouldReceive('hIncrBy')->withArgs(static function (string $key, string $name, int $value) use (&$recordedMetrics): bool {
            $recordedMetrics[$name] = ($recordedMetrics[$name] ?? 0) + $value;

            return str_starts_with($key, 'webguard:operational-metrics:');
        })->andReturn(1);
        $pipeline->shouldReceive('expire')->withArgs(static fn (string $key, int $seconds): bool => $seconds >= 60)->andReturn(1);

        $redis = Mockery::mock();
        $redis->shouldReceive('pipeline')->andReturnUsing(static function (callable $callback) use ($pipeline): array {
            $callback($pipeline);

            return [];
        });

        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
    }
}
