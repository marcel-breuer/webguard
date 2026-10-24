<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Enums\TeamRole;
use App\Http\Middleware\TrackApiUsage;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringManagementApiTest extends TestCase
{
    public function test_user_can_manage_private_monitorings_through_api(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 5]);
        $user = User::factory()->create(['package_id' => $package->id]);
        $serverInstance = $this->serverInstance('api-management-1');
        Sanctum::actingAs($user);

        $testResponse = $this->postJson('/api/monitorings', $this->monitoringPayload([
            'name' => 'API Created Check',
            'target' => 'https://created.example.test',
            'preferred_locations' => [$serverInstance->code],
        ]));

        $testResponse->assertCreated();
        $monitoring = Monitoring::query()->where('name', 'API Created Check')->firstOrFail();
        $this->assertSame($user->id, $monitoring->user_id);
        $this->assertSame(15, $monitoring->check_interval_minutes);
        $testResponse->assertJsonPath('data.check_interval_minutes', 15);

        $this->getJson('/api/monitorings?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'API Created Check');

        $this->patchJson('/api/monitorings/' . $monitoring->id, $this->monitoringPayload([
            'name' => 'API Updated Check',
            'target' => 'https://updated.example.test',
            'status' => MonitoringLifecycleStatus::PAUSED->value,
            'preferred_locations' => [$serverInstance->code],
        ]))->assertOk()
            ->assertJsonPath('data.name', 'API Updated Check');
        $this->assertSame(MonitoringLifecycleStatus::PAUSED, $monitoring->fresh()->status);
        $this->assertSame(15, $monitoring->fresh()->check_interval_minutes);

        $this->patchJson('/api/monitorings/' . $monitoring->id, $this->monitoringPayload([
            'name' => 'API Updated Check',
            'target' => 'https://updated.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'preferred_locations' => [$serverInstance->code],
        ]))->assertOk();
        $this->assertSame(MonitoringLifecycleStatus::ACTIVE, $monitoring->fresh()->status);

        $this->deleteJson('/api/monitorings/' . $monitoring->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertSoftDeleted('monitorings', ['id' => $monitoring->id]);
        $this->deleteJson('/api/monitorings/' . $monitoring->id)->assertNotFound();
    }

    public function test_http_and_keyword_monitorings_accept_only_the_supported_per_monitoring_intervals(): void
    {
        $this->withoutMiddleware(TrackApiUsage::class);
        $package = Package::factory()->create(['monitoring_limit' => 20]);
        $user = User::factory()->create(['package_id' => $package->id]);
        $serverInstance = $this->serverInstance('api-interval-selection');
        Sanctum::actingAs($user);

        foreach ([MonitoringType::HTTP, MonitoringType::KEYWORD] as $type) {
            foreach ([1, 5, 10, 15, 30, 60] as $interval) {
                $response = $this->postJson('/api/monitorings', $this->monitoringPayload([
                    'name' => $type->value . ' ' . $interval,
                    'type' => $type->value,
                    'keyword' => $type === MonitoringType::KEYWORD ? 'healthy' : null,
                    'check_interval_minutes' => $interval,
                    'preferred_locations' => [$serverInstance->code],
                ]));

                $response->assertCreated()
                    ->assertJsonPath('data.check_interval_minutes', $interval);
                $response->assertJsonPath('data.keyword', $type === MonitoringType::KEYWORD ? 'healthy' : null);
                $monitoringId = (string) $response->json('data.id');
                $this->assertDatabaseHas('monitorings', [
                    'id' => $monitoringId,
                    'check_interval_minutes' => $interval,
                ]);
            }
        }

        $this->postJson('/api/monitorings', $this->monitoringPayload([
            'name' => 'Unsupported cadence',
            'check_interval_minutes' => 2,
            'preferred_locations' => [$serverInstance->code],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['check_interval_minutes'])
            ->assertJsonPath('errors.check_interval_minutes.0', __('monitoring.validation.check_interval_invalid_value'));
    }

    public function test_update_without_an_interval_preserves_the_existing_choice(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 5]);
        $user = User::factory()->create(['package_id' => $package->id]);
        $serverInstance = $this->serverInstance('api-interval-preserve');
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'check_interval_minutes' => 60,
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/monitorings/' . $monitoring->id, $this->monitoringPayload([
            'name' => 'Updated without cadence',
            'preferred_locations' => [$serverInstance->code],
        ]))->assertOk()
            ->assertJsonPath('data.check_interval_minutes', 60);

        $this->assertSame(60, $monitoring->fresh()->check_interval_minutes);
    }

    public function test_monitoring_list_uses_a_stable_name_and_id_order(): void
    {
        Package::factory()->create(['monitoring_limit' => 5]);
        $user = User::factory()->create();
        $first = Monitoring::factory()->for($user)->create(['name' => 'Same name']);
        $second = Monitoring::factory()->for($user)->create(['name' => 'Same name']);
        Sanctum::actingAs($user);

        $testResponse = $this->getJson('/api/monitorings?per_page=2');

        $testResponse->assertOk();
        $expectedIds = collect([$first->id, $second->id])->sort()->values()->all();
        $testResponse->assertJsonPath('data.0.id', $expectedIds[0]);
        $testResponse->assertJsonPath('data.1.id', $expectedIds[1]);
    }

    public function test_user_can_move_manageable_monitoring_between_private_and_team_ownership(): void
    {
        Package::factory()->create(['monitoring_limit' => 5]);
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        TeamMembership::factory()->for($team)->for($user)->admin()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/monitorings/' . $monitoring->id . '/ownership/team', [
            'team_id' => $team->id,
        ])->assertOk()
            ->assertJsonPath('data.ownership.team_id', $team->id)
            ->assertJsonPath('data.ownership.type', 'team');

        $this->postJson('/api/monitorings/' . $monitoring->id . '/ownership/private')
            ->assertOk()
            ->assertJsonPath('data.ownership.team_id', null)
            ->assertJsonPath('data.ownership.type', 'private');
    }

    public function test_store_rejects_private_monitoring_when_package_limit_is_reached(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 1]);
        $user = User::factory()->create(['package_id' => $package->id]);
        $serverInstance = $this->serverInstance('api-management-limit');
        Monitoring::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/monitorings', $this->monitoringPayload([
            'name' => 'Over Limit',
            'preferred_locations' => [$serverInstance->code],
        ]))->assertUnprocessable()
            ->assertJsonPath('message', __('monitoring.messages.limit_reached'));
    }

    public function test_non_admin_cannot_move_monitoring_to_team(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        TeamMembership::factory()->for($team)->for($user)->create(['role' => TeamRole::MEMBER]);
        $monitoring = Monitoring::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/monitorings/' . $monitoring->id . '/ownership/team', [
            'team_id' => $team->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_id']);
    }

    public function test_team_member_cannot_mutate_a_visible_team_monitoring(): void
    {
        Package::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $admin->id]);
        TeamMembership::factory()->for($team)->for($admin)->admin()->create();
        TeamMembership::factory()->for($team)->for($member)->create(['role' => TeamRole::MEMBER]);
        $monitoring = Monitoring::factory()->create([
            'user_id' => null,
            'team_id' => $team->id,
            'created_by_user_id' => $admin->id,
        ]);
        $serverInstance = $this->serverInstance('api-management-team');
        Sanctum::actingAs($member);

        $this->patchJson('/api/monitorings/' . $monitoring->id, $this->monitoringPayload([
            'preferred_locations' => [$serverInstance->code],
        ]))->assertForbidden();

        $this->deleteJson('/api/monitorings/' . $monitoring->id)->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function monitoringPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'API Check',
            'type' => MonitoringType::HTTP->value,
            'target' => 'https://api.example.test',
            'status' => MonitoringLifecycleStatus::ACTIVE->value,
            'timeout' => 5,
            'http_method' => 'get',
            'expected_http_statuses' => '200-299',
            'preferred_locations' => ['api-management-1'],
            'notification_on_failure' => true,
            'failure_confirmation_threshold' => 1,
            'ssl_expiry_warning_days' => 7,
        ], $overrides);
    }

    private function serverInstance(string $code): ServerInstance
    {
        return ServerInstance::query()->create([
            'code' => $code,
            'ip_address' => '192.0.2.55',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
    }
}
