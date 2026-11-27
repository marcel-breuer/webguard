<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use App\Queries\MonitoringOverviewQuery;
use App\Services\MonitoringStateResolver;
use Tests\TestCase;

class MonitoringOverviewQueryTest extends TestCase
{
    public function test_it_returns_only_monitorings_visible_to_the_requested_user(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $visibleMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Visible API']);
        Monitoring::factory()->for($otherUser)->create(['name' => 'Hidden API']);

        $monitorings = resolve(MonitoringOverviewQuery::class)->monitoringsFor($user);

        $this->assertSame([$visibleMonitoring->id], $monitorings->modelKeys());
        $this->assertTrue($monitorings->firstOrFail()->relationLoaded('latestResponseResult'));
        $this->assertTrue($monitorings->firstOrFail()->relationLoaded('latestIncident'));
        $this->assertTrue($monitorings->firstOrFail()->relationLoaded('groups'));
    }

    public function test_projection_preserves_health_inputs_and_omits_unused_monitoring_fields(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'expected_http_statuses' => '200-299',
        ]);
        MonitoringResponse::withoutEvents(fn () => MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 503,
            'vital_values' => ['transport_succeeded' => true],
        ]));

        $result = resolve(MonitoringOverviewQuery::class)->monitoringsFor($user)->firstOrFail();

        $this->assertArrayNotHasKey('http_headers', $result->getAttributes());
        $this->assertArrayHasKey('has_enabled_maintenance_windows', $result->getAttributes());
        $this->assertSame(503, $result->latestResponseResult->http_status_code);
        $this->assertSame('down', resolve(MonitoringStateResolver::class)->status($result));
    }

    public function test_maintenance_window_projection_handles_multiple_enabled_windows(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach ([now()->addDays(2), now()->addDays(9)] as $startsAt) {
            MaintenanceWindow::query()->create([
                'monitoring_id' => $monitoring->id,
                'starts_at' => $startsAt,
                'duration_minutes' => 60,
                'recurrence' => 'weekly',
                'timezone' => 'UTC',
                'enabled' => true,
            ]);
        }

        $result = resolve(MonitoringOverviewQuery::class)->monitoringsFor($user)->firstOrFail();

        $this->assertTrue((bool) $result->getAttribute('has_enabled_maintenance_windows'));
    }
}
