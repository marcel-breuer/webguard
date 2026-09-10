<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Services\MonitoringStatsCache;
use App\Support\MonitoringDateRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Mockery;
use Tests\TestCase;

class MonitoringStatsCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_cache_tags_and_ttls_are_defined_in_one_place(): void
    {
        config([
            'monitoring.website_interval_minutes' => 15,
            'monitoring.default_interval_minutes' => 7,
        ]);

        $monitoringStatsCache = resolve(MonitoringStatsCache::class);
        $monitoring = $this->monitoring('monitoring-123');

        $this->assertSame(['monitoring:monitoring-123'], $monitoringStatsCache->tags($monitoring));
        $this->assertSame(420, $monitoringStatsCache->defaultTtlSeconds($monitoring));
        $this->assertSame(3600, $monitoringStatsCache->calendarTtlSeconds());

        $monitoring->type = MonitoringType::HTTP;

        $this->assertSame(900, $monitoringStatsCache->defaultTtlSeconds($monitoring));
    }

    public function test_heatmap_expiration_uses_five_minutes_from_now(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $expiresAt = resolve(MonitoringStatsCache::class)->heatmapExpiresAt();

        $this->assertSame('2026-04-12 12:05:00', $expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_remember_bypasses_cache_outside_production(): void
    {
        $monitoringStatsCache = resolve(MonitoringStatsCache::class);
        $monitoring = $this->monitoring('monitoring-123');
        $calls = 0;

        $firstResult = $monitoringStatsCache->remember($monitoring, 'monitoring:monitoring-123:test', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        });

        $secondResult = $monitoringStatsCache->remember($monitoring, 'monitoring:monitoring-123:test', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        });

        $this->assertFalse($monitoringStatsCache->shouldCache());
        $this->assertSame('value-1', $firstResult);
        $this->assertSame('value-2', $secondResult);
        $this->assertSame(2, $calls);
    }

    public function test_monitoring_stats_cache_builds_endpoint_specific_keys(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $monitoringStatsCache = resolve(MonitoringStatsCache::class);
        $monitoring = $this->monitoring('monitoring-123');
        $monitoringDateRange = MonitoringDateRange::pastDays(7);
        $calendarStartDate = Date::now()->startOfMonth();
        $calendarEndDate = Date::now()->endOfDay();

        $this->assertSame(
            'monitoring:monitoring-123:all:7:20260405:20260412:2026-04-01:2026-04-12',
            $monitoringStatsCache->dashboardKey($monitoring, 7, $monitoringDateRange, $calendarStartDate, $calendarEndDate)
        );
        $this->assertSame('monitoring:monitoring-123:uptime:7:20260405:20260412', $monitoringStatsCache->uptimeKey($monitoring, 7, $monitoringDateRange));
        $this->assertSame(
            'monitoring:monitoring-123:uptime-summary:7-30-90:20260412',
            $monitoringStatsCache->uptimeSummaryKey($monitoring, Collection::make([7, 30, 90]), $calendarEndDate)
        );
        $this->assertSame('monitoring:monitoring-123:response:7:20260405:20260412', $monitoringStatsCache->responseTimesKey($monitoring, 7, $monitoringDateRange));
        $this->assertSame('monitoring:monitoring-123:checks:all:100:0', $monitoringStatsCache->checksKey($monitoring, null, 100, 0));
        $this->assertSame('monitoring:monitoring-123:checks:7:50:10', $monitoringStatsCache->checksKey($monitoring, 7, 50, 10));
        $this->assertSame('monitoring:monitoring-123:heatmap', $monitoringStatsCache->heatmapKey($monitoring));
        $this->assertSame('monitoring:monitoring-123:badge', $monitoringStatsCache->badgeKey($monitoring));
        $this->assertSame('monitoring:monitoring-123:incidents:7:20260405:20260412', $monitoringStatsCache->incidentsKey($monitoring, 7, $monitoringDateRange));
        $this->assertSame('monitoring:monitoring-123:ssl-status', $monitoringStatsCache->sslStatusKey($monitoring));
        $this->assertSame(
            'monitoring_daily_uptime_calendar_monitoring-123_2026-04-01_2026-04-12',
            $monitoringStatsCache->uptimeCalendarKey($monitoring, $calendarStartDate, $calendarEndDate)
        );
    }

    public function test_flush_invalidates_all_cached_monitoring_statistics_in_production(): void
    {
        $monitoring = $this->monitoring('monitoring-123');
        $mock = Mockery::mock();
        $legacyMock = Mockery::mock(MonitoringStatsCache::class)->makePartial();

        $legacyMock->shouldReceive('shouldCache')->once()->andReturnTrue();
        Cache::shouldReceive('tags')->once()->with(['monitoring:monitoring-123'])->andReturn($mock);
        $mock->shouldReceive('flush')->once();

        $legacyMock->flush($monitoring);
    }

    private function monitoring(string $id): Monitoring
    {
        $monitoring = new Monitoring();
        $monitoring->id = $id;

        return $monitoring;
    }
}
