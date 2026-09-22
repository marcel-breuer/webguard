<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\TeamRole;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringGroup;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

class InternalUiMonitoringApiTest extends TestCase
{
    use AssertsApiContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_verified_user_can_list_only_visible_monitorings_with_a_bounded_page(): void
    {
        $user = User::factory()->create();
        $visibleMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Visible API']);
        Monitoring::factory()->for($user)->create(['name' => 'Another API']);
        Monitoring::factory()->create(['name' => 'Hidden API']);

        MonitoringResponse::query()->create([
            'monitoring_id' => $visibleMonitoring->id,
            'status' => MonitoringStatus::UP,
            'response_time' => 128.4,
        ]);

        $this->actingAs($user)->getJson(route('app.monitorings.index', [
            'per_page' => 1,
            'search' => 'Visible',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $visibleMonitoring->id)
            ->assertJsonPath('data.0.latest_check.status', MonitoringStatus::UP->value)
            ->assertJsonPath('data.0.latest_check.response_time_ms', 128.4)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'target',
                    'type',
                    'lifecycle_status',
                    'groups',
                    'latest_check',
                    'open_incident',
                    'maintenance',
                ]],
                'meta' => ['as_of'],
            ])
            ->assertJsonMissing(['name' => 'Hidden API']);

        $this->assertInternalUiTelemetry(
            $this->actingAs($user)->getJson(route('app.monitorings.index')),
            10,
            131072,
        );
    }

    public function test_guest_cannot_read_the_internal_ui_monitoring_projections(): void
    {
        $monitoring = Monitoring::factory()->create();

        $this->getJson(route('app.monitorings.index'))
            ->assertUnauthorized();
        $this->getJson(route('app.monitorings.show', $monitoring))
            ->assertUnauthorized();
        $this->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertUnauthorized();
        $this->getJson(route('app.monitorings.cards', ['ids' => [$monitoring->id]]))
            ->assertUnauthorized();
        $this->getJson(route('app.monitorings.form-options'))
            ->assertUnauthorized();
        $this->getJson(route('app.monitoring-groups.index'))
            ->assertUnauthorized();
        $this->getJson(route('app.maintenance.capabilities'))
            ->assertUnauthorized();
        $this->postJson(route('app.monitorings.store'), [])
            ->assertUnauthorized();
    }

    public function test_internal_ui_monitoring_detail_returns_not_found_for_another_users_monitoring(): void
    {
        $user = User::factory()->create();
        $foreignMonitoring = Monitoring::factory()->create();

        $this->actingAs($user)->getJson(route('app.monitorings.show', $foreignMonitoring))
            ->assertNotFound();
        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', $foreignMonitoring))
            ->assertNotFound();
    }

    public function test_monitoring_detail_includes_readable_check_location_names(): void
    {
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'location-1',
            'display_name' => 'Location 1',
            'country_code' => 'DE',
            'region' => 'Frankfurt',
            'ip_address' => '192.0.2.151',
            'api_key_hash' => 'location-label-test-token',
            'is_active' => true,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->actingAs($user)->getJson(route('app.monitorings.show', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.check_locations.0.code', 'location-1')
            ->assertJsonPath('data.check_locations.0.name', 'Frankfurt, DE');
    }

    public function test_internal_ui_monitoring_detail_data_returns_bounded_diagnostics_without_configuration_secrets(): void
    {
        Date::setTestNow('2026-08-22 12:00:00');

        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'server_health_token' => 'private-token',
            'http_headers' => ['Authorization' => 'Bearer private-token'],
        ]);
        $checkedAt = now()->subMinute();
        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 200,
            'response_time' => 123.4,
            'created_at' => $checkedAt,
            'updated_at' => $checkedAt,
        ]);
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => now()->subDay()->toDateString(),
            'uptime_total' => 1440,
            'downtime_total' => 0,
            'unknown_total' => 0,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 1440,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'avg_response_time' => 120,
            'min_response_time' => 100,
            'max_response_time' => 140,
            'incidents_count' => 0,
        ]);
        Incident::query()->create([
            'monitoring_id' => $monitoring->id,
            'down_at' => now()->subHours(2),
            'up_at' => now()->subHour(),
        ]);

        $testResponse = $this->actingAs($user)->getJson(route('app.monitorings.detail-data', $monitoring));

        $testResponse->assertOk()
            ->assertJsonPath('data.recent_checks.0.response_time', 123.4)
            ->assertJsonPath('data.response_times.aggregated.avg', 123.4)
            ->assertJsonPath('data.incidents.0.down_at', now()->subHours(2)->toIso8601String())
            ->assertJsonStructure([
                'data' => [
                    'availability_periods' => [
                        '7' => ['has_data', 'uptime', 'downtime', 'unknown'],
                        '30' => ['has_data', 'uptime', 'downtime', 'unknown'],
                        '90' => ['has_data', 'uptime', 'downtime', 'unknown'],
                    ],
                ],
            ])
            ->assertJsonPath('meta.range.days', 30)
            ->assertJsonPath('meta.incidents.limit', 5)
            ->assertJsonPath('meta.recent_checks.limit', 5)
            ->assertJsonPath('meta.response_times.days', 1)
            ->assertJsonCount(1, 'data.uptime_calendar')
            ->assertJsonPath('meta.uptime_calendar.oldest_available_month', '2026-08')
            ->assertJsonStructure([
                'data' => [
                    'uptime_calendar' => [
                        '2026-08' => ['days', 'monthly_average_uptime'],
                    ],
                ],
            ])
            ->assertJsonMissing(['server_health_token' => 'private-token'])
            ->assertJsonMissing(['Authorization' => 'Bearer private-token']);

        $this->assertInternalUiTelemetry($testResponse, 25, 262144);
    }

    public function test_internal_ui_monitoring_detail_lists_status_pages_from_monitoring_groups(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $group = MonitoringGroup::factory()->for($user)->create();
        $group->monitorings()->attach($monitoring);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Customer status',
            'is_public' => true,
        ]);
        $statusPage->components()->create([
            'monitoring_group_id' => $group->id,
            'name' => 'Services',
            'position' => 0,
            'source_type' => 'monitoring_group',
        ]);

        $this->actingAs($user)
            ->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.summary.status_pages.0.id', $statusPage->id)
            ->assertJsonPath('data.summary.status_pages.0.name', 'Customer status');
    }

    public function test_internal_ui_monitoring_detail_data_pages_recent_checks_with_a_bounded_offset(): void
    {
        Date::setTestNow('2026-08-22 12:00:00');

        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach (range(0, 11) as $index) {
            $checkedAt = now()->subMinutes($index);
            MonitoringResponse::query()->create([
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP,
                'http_status_code' => 200,
                'response_time' => 100 + $index,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);
        }

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertOk()
            ->assertJsonCount(5, 'data.recent_checks')
            ->assertJsonPath('meta.recent_checks.has_more', true)
            ->assertJsonPath('meta.recent_checks.next_offset', 5);

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', [
            'monitoring' => $monitoring,
            'checks_offset' => 5,
        ]))
            ->assertOk()
            ->assertJsonCount(5, 'data.recent_checks')
            ->assertJsonPath('meta.recent_checks.has_more', true)
            ->assertJsonPath('meta.recent_checks.next_offset', 10);

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', [
            'monitoring' => $monitoring,
            'checks_offset' => -1,
        ]))->assertUnprocessable();
    }

    public function test_internal_ui_monitoring_detail_data_pages_incidents_and_switches_response_time_period(): void
    {
        Date::setTestNow('2026-08-22 12:00:00');

        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach (range(0, 5) as $index) {
            Incident::query()->create([
                'monitoring_id' => $monitoring->id,
                'down_at' => now()->subDays($index + 1),
                'up_at' => now()->subDays($index + 1)->addMinutes(10),
            ]);
        }

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertOk()
            ->assertJsonCount(5, 'data.incidents')
            ->assertJsonPath('meta.incidents.limit', 5)
            ->assertJsonPath('meta.incidents.has_more', true)
            ->assertJsonPath('meta.incidents.next_offset', 5);

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', [
            'monitoring' => $monitoring,
            'incident_offset' => 5,
            'response_time_days' => 7,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.incidents')
            ->assertJsonPath('meta.incidents.has_more', false)
            ->assertJsonPath('meta.incidents.next_offset', null)
            ->assertJsonPath('meta.response_times.days', 7);

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', [
            'monitoring' => $monitoring,
            'incident_offset' => -1,
        ]))->assertUnprocessable();

        $this->actingAs($user)->getJson(route('app.monitorings.detail-data', [
            'monitoring' => $monitoring,
            'response_time_days' => 2,
        ]))->assertUnprocessable();
    }

    public function test_internal_ui_monitoring_operations_manage_private_groups_preferences_and_maintenance(): void
    {
        $user = User::factory()->create();
        $firstMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Primary API']);
        $secondMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Website']);

        $testResponse = $this->actingAs($user)->postJson(route('app.monitoring-groups.store'), [
            'name' => 'Production',
            'description' => 'Critical services',
            'monitoring_ids' => [$firstMonitoring->id],
        ])->assertCreated()
            ->assertJsonPath('data.assignments.0.id', $firstMonitoring->id);
        $groupId = $testResponse->json('data.id');

        $this->actingAs($user)->patchJson(route('app.monitoring-groups.update', $groupId), [
            'monitoring_ids' => [$secondMonitoring->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.id', $secondMonitoring->id);

        $this->actingAs($user)->getJson(route('app.monitorings.notification-preferences.show', $firstMonitoring))
            ->assertOk()
            ->assertJsonPath('data.monitoring_id', $firstMonitoring->id);
        $this->actingAs($user)->patchJson(route('app.monitorings.notification-preferences.update', $firstMonitoring), [
            'notification_on_failure' => false,
            'notification_channels' => [],
            'ssl_expiry_warning_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('data.effective.notification_on_failure', false)
            ->assertJsonPath('data.effective.ssl_expiry_warning_days', 14);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'internal-ui-maintenance-001')
            ->postJson(route('app.maintenance.store'), [
                'mode' => 'one_off',
                'scope' => 'group',
                'monitoring_group_id' => $groupId,
                'maintenance_from' => '2026-08-22T15:00:00Z',
                'maintenance_until' => '2026-08-22T16:00:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'one_off')
            ->assertJsonPath('data.updated_count', 1);

        $this->actingAs($user)->getJson(route('app.maintenance.one-off.index'))
            ->assertOk()
            ->assertJsonPath('data.0.target.id', $secondMonitoring->id);
    }

    public function test_internal_ui_monitoring_operations_only_allow_team_administrators_to_change_ownership(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $monitoring = Monitoring::factory()->for($owner)->create();
        $team = Team::factory()->create(['created_by_user_id' => $owner->id]);
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::ADMIN]);

        $this->actingAs($owner)->postJson(route('app.monitorings.ownership.team.store', $monitoring), [
            'team_id' => $team->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.ownership.type', 'team')
            ->assertJsonPath('data.ownership.team_id', $team->id);

        $this->actingAs($otherUser)->postJson(route('app.monitorings.ownership.private.store', $monitoring))
            ->assertNotFound();
        $this->actingAs($owner)->postJson(route('app.monitorings.ownership.private.store', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.ownership.type', 'private');
    }

    public function test_internal_ui_monitoring_cards_are_scoped_and_require_a_verified_session(): void
    {
        $user = User::factory()->create();
        $visibleMonitoring = Monitoring::factory()->for($user)->create();
        $foreignMonitoring = Monitoring::factory()->create();

        MonitoringResponse::query()->create([
            'monitoring_id' => $visibleMonitoring->id,
            'status' => MonitoringStatus::UP,
            'response_time' => 128.4,
        ]);

        $this->actingAs($user)->getJson(route('app.monitorings.cards', [
            'ids' => [$visibleMonitoring->id, $foreignMonitoring->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.' . $visibleMonitoring->id . '.status', MonitoringStatus::UP->value)
            ->assertJsonMissingPath('data.' . $foreignMonitoring->id);

        $unverifiedUser = User::factory()->unverified()->create();

        $this->actingAs($unverifiedUser)->getJson(route('app.monitorings.index'))
            ->assertForbidden();
    }

    public function test_internal_ui_monitoring_projections_validate_bounded_queries_and_return_a_scoped_detail(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Scoped detail API']);

        $this->actingAs($user)
            ->getJson(route('app.monitorings.index', ['per_page' => 101]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->actingAs($user)
            ->getJson(route('app.monitorings.cards'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
        $this->actingAs($user)
            ->getJson(route('app.monitorings.cards', ['ids' => array_fill(0, 101, $monitoring->id)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $testResponse = $this->actingAs($user)->getJson(route('app.monitorings.show', $monitoring));

        $this->assertDataEnvelope($testResponse, ['data.id', 'data.name']);
        $testResponse->assertJsonPath('data.id', $monitoring->id)
            ->assertJsonPath('data.name', 'Scoped detail API')
            ->assertJsonStructure(['data' => ['initial_results_wait_minutes']]);
        $this->assertInternalUiTelemetry($testResponse, 15, 131072);
    }

    public function test_internal_ui_monitoring_cards_scope_heatmap_data_to_the_authenticated_user(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedMonitoring = Monitoring::factory()->for($user)->create();
        $foreignMonitoring = Monitoring::factory()->for($otherUser)->create();

        $foreignCheckedAt = Date::now()->subMinutes(5);
        MonitoringResponse::query()->create([
            'monitoring_id' => $foreignMonitoring->id,
            'status' => MonitoringStatus::DOWN,
            'http_status_code' => 500,
            'response_time' => 321.0,
            'created_at' => $foreignCheckedAt,
            'updated_at' => $foreignCheckedAt,
        ]);

        $this->actingAs($user)->getJson(route('app.monitorings.cards', [
            'ids' => [$ownedMonitoring->id, $foreignMonitoring->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.' . $ownedMonitoring->id . '.heatmap.0.uptime', 0)
            ->assertJsonMissingPath('data.' . $foreignMonitoring->id);
    }

    public function test_internal_ui_monitoring_cards_accept_a_full_page_of_requested_ids(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $package = Package::factory()->create(['monitoring_limit' => 50]);
        $user = User::factory()->create(['package_id' => $package->id]);

        $monitorings = Monitoring::factory()->count(26)->for($user)->create();

        $this->actingAs($user)->getJson(route('app.monitorings.cards', [
            'ids' => $monitorings->pluck('id')->all(),
        ]))
            ->assertOk()
            ->assertJsonCount(26, 'data')
            ->assertJsonPath('data.' . $monitorings->first()->id . '.heatmap.0.uptime', 0);
    }

    public function test_internal_ui_monitoring_cards_batch_query_count_stays_bounded(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $user = User::factory()->create();
        $monitorings = Monitoring::factory()->count(3)->for($user)->create();

        foreach ($monitorings as $index => $monitoring) {
            foreach (range(0, 2) as $hourOffset) {
                $checkedAt = Date::now()->subHours($hourOffset)->subMinutes($index + 1);

                MonitoringResponse::query()->create([
                    'monitoring_id' => $monitoring->id,
                    'status' => $index === 1 ? MonitoringStatus::DOWN : MonitoringStatus::UP,
                    'http_status_code' => $index === 1 ? 503 : 200,
                    'response_time' => 120.0 + $index,
                    'created_at' => $checkedAt,
                    'updated_at' => $checkedAt,
                ]);
            }
        }

        Incident::query()->create([
            'monitoring_id' => $monitorings[1]->id,
            'down_at' => Date::now()->subHours(2),
            'up_at' => null,
            'created_at' => Date::now()->subHours(2),
            'updated_at' => Date::now()->subHours(2),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($monitorings as $monitoring) {
            $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/status')->assertOk();
            $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/heatmap')->assertOk();
        }

        $unbatchedSelectCount = $this->selectQueryCount();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $testResponse = $this->actingAs($user)->getJson(route('app.monitorings.cards', [
            'ids' => $monitorings->pluck('id')->all(),
        ]));

        $testResponse->assertOk();
        $testResponse->assertJsonPath('data.' . $monitorings[0]->id . '.status', MonitoringStatus::UP->value);
        $testResponse->assertJsonPath('data.' . $monitorings[1]->id . '.status', MonitoringStatus::DOWN->value);
        $testResponse->assertJsonCount(24, 'data.' . $monitorings[2]->id . '.heatmap');

        $batchedSelectCount = $this->selectQueryCount();

        $this->assertGreaterThan($batchedSelectCount, $unbatchedSelectCount);
        $this->assertLessThanOrEqual(4, $batchedSelectCount, (string) collect(DB::getQueryLog())->pluck('query')->implode(PHP_EOL));
    }

    public function test_verified_user_can_manage_monitorings_without_leaking_configuration_secrets(): void
    {
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'ui-management-1',
            'display_name' => 'Location 1',
            'country_code' => 'DE',
            'region' => 'Frankfurt',
            'ip_address' => '192.0.2.101',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $locationOptionsResponse = $this->actingAs($user)->getJson(route('app.monitorings.form-options'));

        $locationOptionsResponse
            ->assertOk()
            ->assertSee($serverInstance->code)
            ->assertJsonFragment(['http']);
        $this->assertContains($serverInstance->code, $locationOptionsResponse->json('data.locations'));
        $locationOptionsResponse->assertJsonFragment(['code' => $serverInstance->code, 'name' => 'Frankfurt, DE']);

        $payload = [
            'name' => 'First-party API check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://monitoring.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'preferred_locations' => [$serverInstance->code],
            'notification_on_failure' => true,
            'failure_confirmation_threshold' => 2,
            'ssl_expiry_warning_days' => 7,
        ];

        $testResponse = $this->actingAs($user)->postJson(route('app.monitorings.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'First-party API check')
            ->assertJsonMissingPath('data.auth_password');
        $monitoringId = $testResponse->json('data.id');

        $this->actingAs($user)->getJson(route('app.monitorings.form-options.edit', $monitoringId))
            ->assertOk()
            ->assertJsonPath('data.monitoring.name', 'First-party API check')
            ->assertJsonMissingPath('data.monitoring.auth_password')
            ->assertJsonMissingPath('data.monitoring.http_headers');

        $this->actingAs($user)->patchJson(route('app.monitorings.update', $monitoringId), [
            ...$payload,
            'name' => 'Updated first-party API check',
            'status' => MonitoringLifecycleStatus::PAUSED->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated first-party API check');

        $this->actingAs($user)->deleteJson(route('app.monitorings.destroy', $monitoringId))
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_internal_ui_monitoring_creation_rejects_private_monitoring_limit_overflow(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 1]);
        $user = User::factory()->create(['package_id' => $package->id]);
        Monitoring::factory()->for($user)->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'ui-limit-1',
            'ip_address' => '192.0.2.102',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(route('app.monitorings.store'), [
            'name' => 'Overflow check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://monitoring.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'preferred_locations' => [$serverInstance->code],
            'failure_confirmation_threshold' => 2,
            'ssl_expiry_warning_days' => 7,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('monitoring.messages.limit_reached'));
    }

    public function test_demo_user_cannot_create_an_internal_ui_monitoring(): void
    {
        $demoUser = User::factory()->create(['role' => UserRole::DEMO]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'ui-demo-1',
            'ip_address' => '192.0.2.104',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $this->actingAs($demoUser)->postJson(route('app.monitorings.store'), [
            'name' => 'Demo check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://monitoring.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'preferred_locations' => [$serverInstance->code],
            'failure_confirmation_threshold' => 2,
            'ssl_expiry_warning_days' => 7,
        ])
            ->assertForbidden();

        $this->assertDatabaseMissing('monitorings', ['name' => 'Demo check']);
    }

    public function test_internal_ui_monitoring_creation_rejects_group_assignments_for_team_monitorings(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::ADMIN]);
        $group = MonitoringGroup::factory()->create(['user_id' => $user->id]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'ui-team-1',
            'ip_address' => '192.0.2.103',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(route('app.monitorings.store'), [
            'name' => 'Team check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://monitoring.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'preferred_locations' => [$serverInstance->code],
            'failure_confirmation_threshold' => 2,
            'ssl_expiry_warning_days' => 7,
            'team_id' => $team->id,
            'group_ids' => [$group->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group_ids');
    }

    private function selectQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(static fn (array $entry): bool => str_starts_with(mb_strtolower($entry['query']), 'select'))
            ->count();
    }
}
