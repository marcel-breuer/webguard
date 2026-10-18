<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MaintenanceWindowRecurrence;
use App\Enums\TeamRole;
use App\Jobs\SendPlannedMaintenanceNotification;
use App\Mail\PublicStatusPageMaintenanceScheduledMail;
use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageMaintenanceDelivery;
use App\Models\StatusPageSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MobileMaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_workspace_scopes_visible_windows_and_management_capabilities(): void
    {
        Date::setTestNow('2026-03-29 12:00:00 UTC');
        $user = $this->user();
        $privateMonitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Private API',
            'maintenance_from' => Date::now()->subHour(),
            'maintenance_until' => Date::now()->addHour(),
        ]);
        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::MEMBER]);
        $teamMonitoring = Monitoring::factory()->for($team)->create([
            'user_id' => null,
            'name' => 'Team API',
            'maintenance_from' => Date::now()->addHour(),
            'maintenance_until' => Date::now()->addHours(2),
        ]);
        $otherMonitoring = Monitoring::factory()->for($this->user())->create([
            'name' => 'Hidden API',
            'maintenance_from' => Date::now()->subHour(),
        ]);
        $maintenanceWindow = MaintenanceWindow::query()->create([
            'monitoring_id' => $privateMonitoring->id,
            'starts_at' => '2026-03-22 01:30:00',
            'duration_minutes' => 90,
            'recurrence' => MaintenanceWindowRecurrence::WEEKLY,
            'timezone' => 'Europe/Berlin',
            'enabled' => true,
        ]);
        $this->actingAsMobile($user);

        $this->getJson('/api/mobile/maintenance/capabilities')
            ->assertOk()
            ->assertJsonPath('data.can_schedule', true)
            ->assertJsonPath('data.manageable_monitoring_ids.0', $privateMonitoring->id)
            ->assertJsonMissing(['id' => $teamMonitoring->id]);

        $this->getJson('/api/mobile/maintenance/one-off')
            ->assertOk()
            ->assertJsonFragment(['id' => $privateMonitoring->id, 'state' => 'active', 'can_manage' => true])
            ->assertJsonFragment(['id' => $teamMonitoring->id, 'state' => 'upcoming', 'can_manage' => false])
            ->assertJsonMissing(['id' => $otherMonitoring->id]);

        $this->getJson('/api/mobile/maintenance/recurring?state=upcoming')
            ->assertOk()
            ->assertJsonPath('data.0.id', $maintenanceWindow->id)
            ->assertJsonPath('data.0.schedule.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.0.kind', 'recurring');
    }

    public function test_mobile_user_can_idempotently_schedule_and_toggle_recurring_maintenance(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $this->actingAsMobile($user);

        $payload = [
            'mode' => 'recurring',
            'scope' => 'monitoring',
            'monitoring_id' => $monitoring->id,
            'recurring_starts_at' => '2026-10-25T02:30:00',
            'recurring_duration_minutes' => 90,
            'recurrence' => MaintenanceWindowRecurrence::WEEKLY->value,
            'recurring_timezone' => 'Europe/Berlin',
        ];

        $this->withHeaders(['Idempotency-Key' => 'recurring-maintenance-001'])
            ->postJson('/api/mobile/maintenance', $payload)
            ->assertCreated()
            ->assertJsonPath('data.kind', 'recurring')
            ->assertJsonPath('idempotent', false);
        $this->withHeaders(['Idempotency-Key' => 'recurring-maintenance-001'])
            ->postJson('/api/mobile/maintenance', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $this->assertDatabaseCount('maintenance_windows', 1);

        $maintenanceWindow = MaintenanceWindow::query()->firstOrFail();
        $this->patchJson('/api/mobile/maintenance/recurring/' . $maintenanceWindow->id, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.state', 'disabled');
        $this->patchJson('/api/mobile/maintenance/recurring/' . $maintenanceWindow->id, ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->postJson('/api/mobile/maintenance', [
            'scope' => 'monitoring',
            'monitoring_id' => $monitoring->id,
            'maintenance_from' => '2026-10-26T10:00:00+01:00',
            'maintenance_until' => '2026-10-26T11:00:00+01:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_mobile_user_can_schedule_group_one_off_maintenance_and_cannot_mutate_team_member_windows(): void
    {
        $user = $this->user();
        $group = MonitoringGroup::factory()->for($user)->create();
        $firstMonitoring = Monitoring::factory()->for($user)->create();
        $secondMonitoring = Monitoring::factory()->for($user)->create();
        $group->monitorings()->attach([$firstMonitoring->id, $secondMonitoring->id]);
        $team = Team::factory()->create(['created_by_user_id' => $user->id]);
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::MEMBER]);
        $teamMonitoring = Monitoring::factory()->for($team)->create(['user_id' => null]);
        $this->actingAsMobile($user);

        $payload = [
            'scope' => 'group',
            'monitoring_group_id' => $group->id,
            'maintenance_from' => '2026-08-10T10:00:00Z',
            'maintenance_until' => '2026-08-10T11:00:00Z',
        ];
        $this->withHeaders(['Idempotency-Key' => 'group-maintenance-001'])
            ->postJson('/api/mobile/maintenance', $payload)
            ->assertCreated()
            ->assertJsonPath('data.updated_count', 2);
        $this->withHeaders(['Idempotency-Key' => 'group-maintenance-001'])
            ->postJson('/api/mobile/maintenance', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $this->assertDatabaseHas('monitorings', ['id' => $firstMonitoring->id, 'maintenance_until' => '2026-08-10 11:00:00']);
        $this->deleteJson('/api/mobile/maintenance/one-off/' . $teamMonitoring->id)->assertNotFound();

        $this->deleteJson('/api/mobile/maintenance/one-off/' . $firstMonitoring->id)->assertNoContent();
        $this->assertDatabaseHas('monitorings', ['id' => $firstMonitoring->id, 'maintenance_from' => null, 'maintenance_until' => null]);
    }

    public function test_mobile_maintenance_schedule_notifies_verified_public_status_page_subscribers(): void
    {
        Mail::fake();
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageComponent = $statusPage->components()->create(['name' => 'Checkout', 'position' => 0]);
        $statusPageComponent->monitorings()->attach($monitoring->id, ['position' => 0]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => Date::now(),
        ]);
        $this->actingAsMobile($user);

        $this->withHeaders(['Idempotency-Key' => 'maintenance-notice-001'])
            ->postJson('/api/mobile/maintenance', [
                'scope' => 'monitoring',
                'monitoring_id' => $monitoring->id,
                'maintenance_from' => '2026-08-20T10:00:00+00:00',
                'maintenance_until' => '2026-08-20T11:00:00+00:00',
            ])
            ->assertCreated();

        Mail::assertNothingSent();
        $statusPageMaintenanceDelivery = StatusPageMaintenanceDelivery::query()->firstOrFail();
        (new SendPlannedMaintenanceNotification($statusPageMaintenanceDelivery->id))->handle();

        Mail::assertSent(PublicStatusPageMaintenanceScheduledMail::class, function (PublicStatusPageMaintenanceScheduledMail $publicStatusPageMaintenanceScheduledMail) use ($statusPageSubscription): bool {
            return $publicStatusPageMaintenanceScheduledMail->hasTo('subscriber@example.com') && $publicStatusPageMaintenanceScheduledMail->subscription->is($statusPageSubscription);
        });
    }

    public function test_maintenance_scheduling_does_not_contact_smtp_and_replays_without_duplicate_notices(): void
    {
        Mail::shouldReceive('to')->never();
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'maintenance-retry',
            'is_public' => true,
        ]);
        $statusPageComponent = $statusPage->components()->create(['name' => 'Checkout', 'position' => 0]);
        $statusPageComponent->monitorings()->attach($monitoring->id, ['position' => 0]);
        $statusPage->subscriptions()->create([
            'email' => 'subscriber@example.com',
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => Date::now(),
        ]);
        $this->actingAsMobile($user);
        $payload = [
            'scope' => 'monitoring',
            'monitoring_id' => $monitoring->id,
            'maintenance_from' => '2026-08-20T10:00:00+00:00',
            'maintenance_until' => '2026-08-20T11:00:00+00:00',
        ];

        $this->withHeaders(['Idempotency-Key' => 'maintenance-retry'])
            ->postJson('/api/mobile/maintenance', $payload)->assertCreated();
        $this->postJson('/api/mobile/maintenance', $payload)
            ->assertOk()->assertJsonPath('idempotent', true);

        $this->assertDatabaseCount('status_page_maintenance_deliveries', 1);
        $this->assertNull(StatusPageMaintenanceDelivery::query()->firstOrFail()->sent_at);
    }

    private function user(): User
    {
        return User::factory()->create(['package_id' => Package::factory()->create(['monitoring_limit' => 20])->id]);
    }

    private function actingAsMobile(User $user): void
    {
        $this->withToken($user->createToken('ios-app: Test Device')->plainTextToken);
    }
}
