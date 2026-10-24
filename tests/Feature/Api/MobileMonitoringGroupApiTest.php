<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileMonitoringGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_create_update_and_delete_own_mobile_monitoring_groups(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $firstMonitoring = Monitoring::factory()->for($user)->create(['name' => 'API']);
        $secondMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Website']);
        $foreignGroup = MonitoringGroup::factory()->for(User::factory()->create())->create(['name' => 'Hidden group']);
        Sanctum::actingAs($user);

        $testResponse = $this->postJson('/api/mobile/monitoring-groups', [
            'name' => 'Production',
            'description' => 'Critical services',
            'monitoring_ids' => [$firstMonitoring->id, $secondMonitoring->id],
        ]);

        $testResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Production')
            ->assertJsonPath('data.ownership.type', 'private')
            ->assertJsonPath('data.ownership.can_manage', true)
            ->assertJsonPath('data.assignable_monitoring_count', 2)
            ->assertJsonPath('data.assignments.0.id', $firstMonitoring->id)
            ->assertJsonPath('data.assignments.1.id', $secondMonitoring->id);

        $monitoringGroup = MonitoringGroup::query()->where('name', 'Production')->firstOrFail();

        $this->getJson('/api/mobile/monitoring-groups?per_page=1')
            ->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $monitoringGroup->id)
            ->assertJsonMissing(['name' => $foreignGroup->name]);

        $this->getJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id)
            ->assertOk()
            ->assertJsonPath('data.assignments.0.target', $firstMonitoring->target);

        $this->patchJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id, [
            'name' => 'Production services',
            'monitoring_ids' => [$secondMonitoring->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Production services')
            ->assertJsonPath('data.assignments.0.id', $secondMonitoring->id);

        $this->assertDatabaseMissing('monitoring_group_monitoring', [
            'monitoring_group_id' => $monitoringGroup->id,
            'monitoring_id' => $firstMonitoring->id,
        ]);
        $this->assertDatabaseHas('monitoring_group_monitoring', [
            'monitoring_group_id' => $monitoringGroup->id,
            'monitoring_id' => $secondMonitoring->id,
        ]);

        $this->deleteJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('monitoring_groups', ['id' => $monitoringGroup->id]);
        $this->assertDatabaseHas('monitorings', ['id' => $secondMonitoring->id]);
    }

    public function test_mobile_group_assignment_options_and_mutations_exclude_team_and_foreign_monitorings(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $privateMonitoring = Monitoring::factory()->for($user)->create(['name' => 'Private API']);
        $foreignMonitoring = Monitoring::factory()->create(['name' => 'Foreign API']);
        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        TeamMembership::factory()->for($team)->for($user)->admin()->create();
        $teamMonitoring = Monitoring::factory()->for($team)->create([
            'user_id' => null,
            'name' => 'Team API',
        ]);
        $monitoringGroup = MonitoringGroup::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/monitoring-groups/assignment-options')
            ->assertOk()
            ->assertJsonPath('data.0.id', $privateMonitoring->id)
            ->assertJsonMissing(['id' => $foreignMonitoring->id])
            ->assertJsonMissing(['id' => $teamMonitoring->id]);

        $this->patchJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id, [
            'monitoring_ids' => [$foreignMonitoring->id, $teamMonitoring->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monitoring_ids.0', 'monitoring_ids.1']);

        $this->getJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id)
            ->assertOk()
            ->assertJsonPath('data.assignable_monitoring_count', 0);

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/mobile/monitoring-groups/' . $monitoringGroup->id)
            ->assertNotFound();
    }

    public function test_owner_can_configure_an_sla_target_and_monthly_reports_for_a_group(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $testResponse = $this->postJson('/api/mobile/monitoring-groups', [
            'name' => 'Production SLA',
            'sla_target_percentage' => '99.95',
            'sla_report_enabled' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.sla.target_percentage', 99.95)
            ->assertJsonPath('data.sla.monthly_reports_enabled', true);

        $groupId = $testResponse->json('data.id');
        $this->patchJson('/api/mobile/monitoring-groups/' . $groupId, [
            'sla_target_percentage' => '101',
        ])->assertUnprocessable()->assertJsonValidationErrors('sla_target_percentage');

        $this->patchJson('/api/mobile/monitoring-groups/' . $groupId, [
            'sla_target_percentage' => null,
            'sla_report_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.sla.target_percentage', null)
            ->assertJsonPath('data.sla.monthly_reports_enabled', false);
    }

    public function test_assignment_options_search_all_owned_private_monitorings_by_name_or_target(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        Monitoring::factory()->for($user)->count(100)->sequence(
            fn ($sequence): array => ['name' => sprintf('Alpha %03d', $sequence->index)]
        )->create();
        $nameMatch = Monitoring::factory()->for($user)->create(['name' => 'Needle monitoring']);
        $targetMatch = Monitoring::factory()->for($user)->create([
            'name' => 'Other monitoring',
            'target' => 'https://target-search.example.test',
        ]);
        $foreignMatch = Monitoring::factory()->create(['name' => 'Needle foreign monitoring']);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/monitoring-groups/assignment-options?per_page=100&search=needle')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $nameMatch->id)
            ->assertJsonMissing(['id' => $foreignMatch->id]);

        $this->getJson('/api/mobile/monitoring-groups/assignment-options?per_page=100&search=target-search')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $targetMatch->id);
    }

    public function test_monitoring_management_contract_includes_group_assignments_and_ownership_metadata(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 5]);
        $user = User::factory()->create(['package_id' => $package->id]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'mobile-group-api',
            'ip_address' => '192.0.2.71',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        $firstGroup = MonitoringGroup::factory()->for($user)->create(['name' => 'Production']);
        $secondGroup = MonitoringGroup::factory()->for($user)->create(['name' => 'Billing']);
        Sanctum::actingAs($user);

        $testResponse = $this->postJson('/api/monitorings', $this->monitoringPayload($serverInstance, [
            'group_ids' => [$firstGroup->id],
        ]));

        $testResponse
            ->assertCreated()
            ->assertJsonPath('data.ownership.type', 'private')
            ->assertJsonPath('data.ownership.can_manage', true)
            ->assertJsonPath('data.group_assignments.0.id', $firstGroup->id);

        $monitoringId = $testResponse->json('data.id');

        $this->patchJson('/api/monitorings/' . $monitoringId, $this->monitoringPayload($serverInstance, [
            'group_ids' => [$secondGroup->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.group_assignments.0.id', $secondGroup->id);

        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        TeamMembership::factory()->for($team)->for($user)->admin()->create();

        $this->postJson('/api/monitorings/' . $monitoringId . '/ownership/team', ['team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.ownership.type', 'team')
            ->assertJsonPath('data.ownership.team_id', $team->id);

        $this->patchJson('/api/monitorings/' . $monitoringId, $this->monitoringPayload($serverInstance, [
            'group_ids' => [$firstGroup->id],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group_ids');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function monitoringPayload(ServerInstance $serverInstance, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Mobile API Check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://mobile.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'expected_http_statuses' => '200-299',
            'preferred_locations' => [$serverInstance->code],
            'notification_on_failure' => true,
            'failure_confirmation_threshold' => 1,
            'ssl_expiry_warning_days' => 7,
        ], $overrides);
    }
}
