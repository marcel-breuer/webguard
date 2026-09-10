<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\MonitoringUptimeCalendarPayload;
use App\Enums\MonitoringStatus;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use App\Services\MonitoringUptimeCalendarService;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class MonitoringUptimeCalendarServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_calendar_groups_days_by_month_and_calculates_monthly_average_from_minutes(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        $this->createDailyResult($monitoring, '2026-04-10', 75.0, 90, 30);
        $this->createDailyResult($monitoring, '2026-04-11', 50.0, 60, 60);

        $monitoringUptimeCalendarPayload = resolve(MonitoringUptimeCalendarService::class)->getGroupedByDateAndMonth(
            $monitoring,
            Date::parse('2026-04-01')->startOfDay(),
            Date::parse('2026-04-30')->endOfDay()
        );
        $calendar = $monitoringUptimeCalendarPayload->toArray();

        $this->assertInstanceOf(MonitoringUptimeCalendarPayload::class, $monitoringUptimeCalendarPayload);
        $this->assertArrayHasKey('2026-04', $calendar);
        $this->assertCount(30, $calendar['2026-04']['days']);
        $this->assertEqualsWithDelta(62.5, (float) $calendar['2026-04']['monthly_average_uptime'], 0.0001);
        $this->assertSame(75.0, $calendar['2026-04']['days'][9]['uptime_percentage']);
        $this->assertNull($calendar['2026-04']['days'][0]['uptime_percentage']);
    }

    public function test_calendar_uses_tracked_minutes_for_daily_percentage(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        $this->createDailyResult($monitoring, '2026-04-10', 0.0, 90, 30, 60);

        $calendar = resolve(MonitoringUptimeCalendarService::class)->getGroupedByDateAndMonth(
            $monitoring,
            Date::parse('2026-04-01')->startOfDay(),
            Date::parse('2026-04-30')->endOfDay()
        )->toArray();

        $this->assertEqualsWithDelta(75.0, (float) $calendar['2026-04']['days'][9]['uptime_percentage'], 0.0001);
        $this->assertEqualsWithDelta(75.0, (float) $calendar['2026-04']['monthly_average_uptime'], 0.0001);
    }

    public function test_calendar_includes_current_day_only_through_the_current_time(): void
    {
        Date::setTestNow('2026-04-20 09:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        $this->createResponse($monitoring, MonitoringStatus::UP, '2026-04-20 00:00:00');
        $this->createResponse($monitoring, MonitoringStatus::DOWN, '2026-04-20 08:00:00');

        $calendar = resolve(MonitoringUptimeCalendarService::class)->getGroupedByDateAndMonth(
            $monitoring,
            Date::parse('2026-04-01')->startOfDay(),
            Date::parse('2026-04-30')->endOfDay()
        )->toArray();

        $this->assertEqualsWithDelta(88.8889, (float) $calendar['2026-04']['days'][19]['uptime_percentage'], 0.0001);
    }

    public function test_calendar_marks_a_healthy_current_day_as_fully_available(): void
    {
        Date::setTestNow('2026-04-20 09:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        $this->createResponse($monitoring, MonitoringStatus::UP, '2026-04-20 00:00:00');

        $calendar = resolve(MonitoringUptimeCalendarService::class)->getGroupedByDateAndMonth(
            $monitoring,
            Date::parse('2026-04-01')->startOfDay(),
            Date::parse('2026-04-30')->endOfDay()
        )->toArray();

        $this->assertSame(100.0, $calendar['2026-04']['days'][19]['uptime_percentage']);
    }

    public function test_calendar_excludes_months_without_daily_records(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        $this->createDailyResult($monitoring, '2026-04-10', 100.0, 120, 0);

        $calendar = resolve(MonitoringUptimeCalendarService::class)->getGroupedByDateAndMonth(
            $monitoring,
            Date::parse('2026-01-01')->startOfDay(),
            Date::parse('2026-04-30')->endOfDay(),
            includeMonthsBeforeMonitoringCreation: true,
        )->toArray();

        $this->assertSame(['2026-04'], array_keys($calendar));
        $this->assertSame(100.0, $calendar['2026-04']['days'][9]['uptime_percentage']);
    }

    public function test_oldest_recorded_month_uses_the_first_daily_result(): void
    {
        Package::factory()->create();
        $monitoring = Monitoring::factory()->for(User::factory())->create();

        $this->createDailyResult($monitoring, '2025-12-31', 100.0, 120, 0);
        $this->createDailyResult($monitoring, '2026-04-10', 100.0, 120, 0);

        $this->assertSame(
            '2025-12',
            resolve(MonitoringUptimeCalendarService::class)->oldestRecordedMonth($monitoring),
        );
    }

    private function createDailyResult(
        Monitoring $monitoring,
        string $date,
        float $uptimePercentage,
        int $uptimeMinutes,
        int $downtimeMinutes,
        int $unknownMinutes = 0
    ): void {
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => $date,
            'uptime_total' => 1,
            'downtime_total' => 1,
            'unknown_total' => 0,
            'uptime_percentage' => $uptimePercentage,
            'downtime_percentage' => 100 - $uptimePercentage,
            'unknown_percentage' => $unknownMinutes > 0
                ? ($unknownMinutes / ($uptimeMinutes + $downtimeMinutes + $unknownMinutes)) * 100
                : 0.0,
            'uptime_minutes' => $uptimeMinutes,
            'downtime_minutes' => $downtimeMinutes,
            'unknown_minutes' => $unknownMinutes,
            'avg_response_time' => 100.0,
            'min_response_time' => 100,
            'max_response_time' => 100,
            'incidents_count' => 0,
        ]);
    }

    private function createResponse(Monitoring $monitoring, MonitoringStatus $monitoringStatus, string $createdAt): void
    {
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => $monitoringStatus,
            'http_status_code' => $monitoringStatus === MonitoringStatus::UP ? 200 : 503,
            'response_time' => $monitoringStatus === MonitoringStatus::UP ? 100.0 : null,
            'created_at' => Date::parse($createdAt),
            'updated_at' => Date::parse($createdAt),
        ]);
    }
}
