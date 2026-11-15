<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringStatus;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

class InternalUiDashboardApiTest extends TestCase
{
    use AssertsApiContracts;

    public function test_guest_cannot_read_the_internal_ui_dashboard(): void
    {
        $this->getJson(route('app.dashboard'))
            ->assertUnauthorized();
    }

    public function test_verified_user_can_read_a_scoped_dashboard_projection(): void
    {
        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        $visibleMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Visible API']);
        Monitoring::factory()->create(['name' => 'Hidden API']);

        $testResponse = $this->actingAs($user)->getJson(route('app.dashboard'));

        $testResponse
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.services.0.id', $visibleMonitoring->id)
            ->assertJsonPath('data.services.0.name', 'Visible API')
            ->assertJsonMissing(['name' => 'Hidden API'])
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Query-Count')
            ->assertHeader('X-Response-Bytes')
            ->assertHeader('Server-Timing')
            ->assertJsonStructure([
                'meta' => ['as_of', 'service_pagination'],
            ]);

        $this->assertInternalUiTelemetry($testResponse, 30, 131072);
    }

    public function test_dashboard_projection_stays_within_its_response_budget(): void
    {
        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        Monitoring::factory()->count(3)->for($user)->create();

        $testResponse = $this->actingAs($user)->getJson(route('app.dashboard'));

        $testResponse->assertOk();
        $this->assertInternalUiTelemetry($testResponse, 30, 131072);
    }

    public function test_large_dashboard_keeps_pagination_and_custom_check_intervals(): void
    {
        Date::setTestNow('2026-08-31 12:00:00');
        Package::factory()->create(['monitoring_limit' => 200]);
        $user = User::factory()->create();
        $monitorings = Monitoring::factory()->count(100)->for($user)->create([
            'type' => \App\Enums\MonitoringType::HTTP,
            'check_interval_minutes' => 60,
        ]);
        foreach ($monitorings as $monitoring) {
            MonitoringResponse::withoutEvents(fn () => MonitoringResponse::query()->forceCreate([
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP,
                'created_at' => Date::now()->subMinutes(100),
                'updated_at' => Date::now()->subMinutes(100),
            ]));
        }

        $response = $this->actingAs($user)->getJson(route('app.dashboard', ['service_page' => 10]));

        $response->assertOk()
            ->assertJsonPath('data.summary.total', 100)
            ->assertJsonPath('data.summary.healthy', 100)
            ->assertJsonCount(10, 'data.services')
            ->assertJsonPath('meta.service_pagination.from', 91)
            ->assertJsonPath('meta.service_pagination.to', 100);
        $this->assertInternalUiTelemetry($response, 30, 131072);
    }

    public function test_dashboard_projection_preserves_a_gateway_request_identifier(): void
    {
        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        $requestId = str_repeat('a', 32);

        $this->actingAs($user)
            ->withHeader('X-Request-Id', $requestId)
            ->getJson(route('app.dashboard'))
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId);
    }

    public function test_unverified_user_cannot_read_the_internal_ui_dashboard(): void
    {
        Package::factory()->create();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson(route('app.dashboard'))
            ->assertForbidden();
    }

    public function test_dashboard_projection_supports_private_conditional_get_requests(): void
    {
        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        Monitoring::factory()->for($user)->create(['name' => 'Visible API']);

        $testResponse = $this->actingAs($user)->getJson(route('app.dashboard'));
        $etag = (string) $testResponse->headers->get('ETag');

        $testResponse
            ->assertOk();
        $this->assertNotSame('', $etag);
        $this->assertStringContainsString('private', (string) $testResponse->headers->get('Cache-Control'));

        $this->actingAs($user)
            ->withHeader('If-None-Match', $etag)
            ->getJson(route('app.dashboard'))
            ->assertNotModified()
            ->assertHeader('ETag', $etag);
    }

    public function test_dashboard_projection_rejects_an_invalid_page_and_changes_its_etag_after_a_monitoring_result(): void
    {
        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Cache contract API']);

        $this->actingAs($user)
            ->getJson(route('app.dashboard', ['service_page' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_page');

        $testResponse = $this->actingAs($user)->getJson(route('app.dashboard'));
        $firstEtag = (string) $testResponse->headers->get('ETag');

        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'response_time' => 120,
        ]);

        $secondResponse = $this->actingAs($user)->getJson(route('app.dashboard'));

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.services.0.status', 'up');
        $this->assertNotSame($firstEtag, (string) $secondResponse->headers->get('ETag'));
    }

    public function test_dashboard_projection_includes_live_uptime_for_today_before_daily_aggregation(): void
    {
        Date::setTestNow('2026-08-31 12:00:00');

        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Live uptime API']);
        $responseAt = Date::now()->subHours(2);

        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'response_time' => 120,
            'created_at' => $responseAt,
            'updated_at' => $responseAt,
        ]);
        $downResponseAt = Date::now()->subHour();
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN,
            'response_time' => 120,
            'created_at' => $downResponseAt,
            'updated_at' => $downResponseAt,
        ]);

        $this->actingAs($user)
            ->getJson(route('app.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.trend.6.date', '2026-08-31')
            ->assertJsonPath('data.trend.6.has_data', true)
            ->assertJsonPath('data.trend.6.uptime_percentage', 50);
    }

    public function test_dashboard_skips_raw_today_responses_when_daily_aggregation_exists(): void
    {
        Date::setTestNow('2026-08-31 12:00:00');

        Package::factory()->create(['monitoring_limit' => 10]);
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Aggregated trend API']);
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => Date::yesterday()->toDateString(),
            'uptime_total' => 1440,
            'downtime_total' => 0,
            'unknown_total' => 0,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 1440,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'incidents_count' => 0,
        ]);
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => Date::today()->toDateString(),
            'uptime_total' => 360,
            'downtime_total' => 0,
            'unknown_total' => 0,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 360,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'incidents_count' => 0,
        ]);
        $this->assertSame(
            1,
            MonitoringDailyResult::query()
                ->where('monitoring_id', $monitoring->id)
                ->whereDate('date', Date::today())
                ->count(),
        );
        $rawTrendQueries = 0;
        DB::listen(function ($query) use (&$rawTrendQueries): void {
            if (str_contains($query->sql, 'monitoring_response_results')
                && str_contains($query->sql, 'server_health_metrics')
                && str_contains($query->sql, 'vital_values')
                && ! str_contains($query->sql, 'join')) {
                $rawTrendQueries++;
            }
        });

        $this->actingAs($user)
            ->getJson(route('app.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.trend.5.date', '2026-08-30')
            ->assertJsonPath('data.trend.5.uptime_percentage', 100);

        $this->assertSame(0, $rawTrendQueries);
    }
}
