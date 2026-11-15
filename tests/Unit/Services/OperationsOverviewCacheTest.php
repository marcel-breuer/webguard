<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MonitoringStatus;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\OperationsOverviewCache;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OperationsOverviewCacheTest extends TestCase
{
    public function test_it_scopes_cached_overviews_to_the_user_and_service_page(): void
    {
        $user = new User();
        $user->id = 'user-123';
        $mock = Mockery::mock(TaggableStore::class);
        $taggedCache = Mockery::mock();

        Cache::shouldReceive('getStore')->once()->andReturn($mock);
        Cache::shouldReceive('tags')
            ->once()
            ->with(['operations-overview', 'operations-overview:user:user-123'])
            ->andReturn($taggedCache);
        $taggedCache->shouldReceive('remember')
            ->once()
            ->with('operations-overview:user:user-123:page:2', 30, Mockery::type('callable'))
            ->andReturnUsing(fn (string $key, int $ttl, callable $callback): array => $callback());

        $payload = resolve(OperationsOverviewCache::class)->remember($user, 2, fn (): array => [
            'data' => ['summary' => ['total' => 1]],
            'service_pagination' => ['current_page' => 2, 'last_page' => 2, 'total' => 11, 'from' => 11, 'to' => 11],
        ]);

        $this->assertSame(1, $payload['data']['summary']['total']);
    }

    public function test_it_serves_fresh_data_when_the_tagged_cache_is_unavailable(): void
    {
        $user = new User();
        $user->id = 'user-123';
        $mock = Mockery::mock(TaggableStore::class);
        $taggedCache = Mockery::mock();

        Cache::shouldReceive('getStore')->once()->andReturn($mock);
        Cache::shouldReceive('tags')->once()->andReturn($taggedCache);
        $taggedCache->shouldReceive('remember')->once()->andThrow(new RuntimeException('Redis unavailable'));

        $payload = resolve(OperationsOverviewCache::class)->remember($user, 1, fn (): array => [
            'data' => ['summary' => ['total' => 1]],
            'service_pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'from' => 1, 'to' => 1],
        ]);

        $this->assertSame(1, $payload['service_pagination']['total']);
    }

    public function test_it_reuses_the_cached_projection_without_rebuilding_it(): void
    {
        $user = new User();
        $user->id = 'user-123';
        $mock = Mockery::mock(TaggableStore::class);
        $taggedCache = Mockery::mock();
        $cachedPayload = null;
        $buildCount = 0;

        Cache::shouldReceive('getStore')->twice()->andReturn($mock);
        Cache::shouldReceive('tags')
            ->twice()
            ->with(['operations-overview', 'operations-overview:user:user-123'])
            ->andReturn($taggedCache);
        $taggedCache->shouldReceive('remember')
            ->twice()
            ->with('operations-overview:user:user-123:page:1', 30, Mockery::type('callable'))
            ->andReturnUsing(function (string $key, int $ttl, callable $callback) use (&$cachedPayload): array {
                return $cachedPayload ??= $callback();
            });

        $payloadFactory = function () use (&$buildCount): array {
            $buildCount++;

            return [
                'data' => ['summary' => ['total' => 1]],
                'service_pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'from' => 1, 'to' => 1],
            ];
        };

        $operationsOverviewCache = resolve(OperationsOverviewCache::class);

        $this->assertSame(1, $operationsOverviewCache->remember($user, 1, $payloadFactory)['data']['summary']['total']);
        $this->assertSame(1, $operationsOverviewCache->remember($user, 1, $payloadFactory)['data']['summary']['total']);
        $this->assertSame(1, $buildCount);
    }

    public function test_measurements_invalidate_only_the_owner_and_team_members(): void
    {
        Package::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $unrelatedUser = User::factory()->create();
        $team = Team::factory()->create();
        TeamMembership::factory()->for($team)->for($member)->create();
        $monitoring = Monitoring::factory()->for($owner)->for($team)->create();
        $cache = resolve(OperationsOverviewCache::class);
        $builds = 0;
        $factory = function () use (&$builds): array {
            $builds++;

            return [
                'data' => [],
                'service_pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'from' => null, 'to' => null],
            ];
        };

        foreach ([$owner, $member, $unrelatedUser] as $user) {
            $cache->remember($user, 1, $factory);
            $cache->remember($user, 2, $factory);
        }
        $this->assertSame(6, $builds);

        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'response_time' => 120,
        ]);

        foreach ([$owner, $member, $unrelatedUser] as $user) {
            $cache->remember($user, 1, $factory);
            $cache->remember($user, 2, $factory);
        }
        $this->assertSame(10, $builds);
    }

    public function test_it_flushes_all_cached_overviews_after_domain_changes(): void
    {
        $mock = Mockery::mock(TaggableStore::class);
        $taggedCache = Mockery::mock();

        Cache::shouldReceive('getStore')->once()->andReturn($mock);
        Cache::shouldReceive('tags')->once()->with(['operations-overview'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('flush')->once();

        resolve(OperationsOverviewCache::class)->flush();
    }
}
