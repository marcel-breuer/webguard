<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\MonitoringResponseArchived;
use App\Models\Package;
use App\Models\User;
use App\Services\MonitoringServerHealthTelemetryService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringServerHealthTelemetryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_it_aggregates_live_and_archived_server_health_metrics_by_day(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::SERVER_HEALTH,
            'server_health_cpu_threshold_percent' => 85,
            'server_health_load_threshold_per_cpu' => 1.5,
        ]);

        $this->storeLiveResponse($monitoring, '2026-04-11 10:00:00', [
            'cpu_usage_percent' => 40,
            'ram_usage_percent' => 60,
            'storage_usage_percent' => 70,
            'load_average_1m' => 4,
            'logical_cpu_count' => 2,
        ]);
        $this->storeArchivedResponse($monitoring, '2026-04-11 11:00:00', [
            'cpu_usage_percent' => 60,
            'ram_usage_percent' => 80,
            'storage_usage_percent' => 90,
            'load_average_1m' => 6,
            'logical_cpu_count' => 3,
        ]);
        $this->storeLiveResponse($monitoring, '2026-04-12 10:00:00', [
            'cpu_usage_percent' => 20,
            'ram_usage_percent' => 30,
        ]);

        $telemetry = resolve(MonitoringServerHealthTelemetryService::class)->getTelemetry(
            $monitoring,
            Date::parse('2026-04-10'),
            Date::parse('2026-04-12 23:59:59'),
        );

        $this->assertCount(2, $telemetry['data']);
        $this->assertSame('2026-04-11T00:00:00+02:00', $telemetry['data'][0]['checked_at']);
        $this->assertEqualsWithDelta(50.0, $telemetry['data'][0]['cpu_usage_percent'], 0.0001);
        $this->assertEqualsWithDelta(70.0, $telemetry['data'][0]['ram_usage_percent'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $telemetry['data'][0]['storage_usage_percent'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $telemetry['data'][0]['normalized_load'], 0.0001);
        $this->assertNull($telemetry['data'][1]['storage_usage_percent']);
        $this->assertSame(85.0, $telemetry['thresholds']['cpu_usage_percent']);
        $this->assertSame(1.5, $telemetry['thresholds']['load_per_cpu']);
    }

    public function test_it_aggregates_a_24_hour_telemetry_window_by_hour(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::SERVER_HEALTH,
        ]);

        $this->storeLiveResponse($monitoring, '2026-04-12 10:05:00', [
            'cpu_usage_percent' => 40,
            'ram_usage_percent' => 60,
        ]);
        $this->storeLiveResponse($monitoring, '2026-04-12 10:45:00', [
            'cpu_usage_percent' => 60,
            'ram_usage_percent' => 80,
        ]);
        $this->storeLiveResponse($monitoring, '2026-04-12 11:05:00', [
            'cpu_usage_percent' => 20,
            'ram_usage_percent' => 30,
        ]);

        $telemetry = resolve(MonitoringServerHealthTelemetryService::class)->getTelemetry(
            $monitoring,
            Date::parse('2026-04-11 00:00:00'),
            Date::parse('2026-04-12 23:59:59'),
        );

        $this->assertCount(2, $telemetry['data']);
        $this->assertSame('2026-04-12T10:00:00+02:00', $telemetry['data'][0]['checked_at']);
        $this->assertEqualsWithDelta(50.0, $telemetry['data'][0]['cpu_usage_percent'], 0.0001);
        $this->assertEqualsWithDelta(70.0, $telemetry['data'][0]['ram_usage_percent'], 0.0001);
        $this->assertSame('2026-04-12T11:00:00+02:00', $telemetry['data'][1]['checked_at']);
    }

    /** @param array<string, int|float> $metrics */
    private function storeLiveResponse(Monitoring $monitoring, string $checkedAt, array $metrics): void
    {
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'server_health_metrics' => $metrics,
            'created_at' => Date::parse($checkedAt),
            'updated_at' => Date::parse($checkedAt),
        ]);
    }

    /** @param array<string, int|float> $metrics */
    private function storeArchivedResponse(Monitoring $monitoring, string $checkedAt, array $metrics): void
    {
        MonitoringResponseArchived::query()->create([
            'id' => (string) Str::ulid(),
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
            'server_health_metrics' => $metrics,
            'created_at' => Date::parse($checkedAt),
            'updated_at' => Date::parse($checkedAt),
        ]);
    }
}
