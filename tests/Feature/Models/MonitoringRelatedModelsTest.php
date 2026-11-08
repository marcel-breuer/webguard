<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\IncidentUpdateStatus;
use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\NotificationType;
use App\Enums\TeamRole;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitoring;
use App\Models\MonitoringDomainResult;
use App\Models\MonitoringNotification;
use App\Models\MonitoringNotificationPreference;
use App\Models\MonitoringNotificationState;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Models\Package;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Notifications\MonitoringNotificationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringRelatedModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_domain_and_ssl_results_expose_monitoring_user_relations_and_casts(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $monitoringDomainResult = MonitoringDomainResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'expires_at' => '2026-07-27 00:00:00',
            'is_valid' => 1,
            'registrar' => 'Example Registrar',
            'checked_at' => '2026-06-27 00:00:00',
        ]);
        $monitoringSslResult = MonitoringSslResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'expires_at' => '2026-07-27 00:00:00',
            'is_valid' => 0,
            'issuer' => 'Example CA',
            'issued_at' => '2026-01-27 00:00:00',
        ]);

        $this->assertTrue($monitoringDomainResult->is_valid);
        $this->assertSame($monitoring->id, $monitoringDomainResult->monitoring->id);
        $this->assertSame($user->id, $monitoringDomainResult->user->id);
        $this->assertFalse($monitoringSslResult->is_valid);
        $this->assertSame($monitoring->id, $monitoringSslResult->monitoring->id);
        $this->assertSame($user->id, $monitoringSslResult->user->id);
    }

    public function test_team_invitation_pending_state_scope_relations_and_casts(): void
    {
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $inviter->id]);
        $teamInvitation = TeamInvitation::query()->create([
            'team_id' => $team->id,
            'email' => 'pending@example.com',
            'role' => TeamRole::ADMIN,
            'token_hash' => hash('sha256', 'pending-token'),
            'invited_by_user_id' => $inviter->id,
            'expires_at' => now()->addDay(),
        ]);
        $expiredInvitation = TeamInvitation::query()->create([
            'team_id' => $team->id,
            'email' => 'expired@example.com',
            'role' => TeamRole::MEMBER,
            'token_hash' => hash('sha256', 'expired-token'),
            'invited_by_user_id' => $inviter->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($teamInvitation->isPending());
        $this->assertFalse($expiredInvitation->isPending());
        $this->assertSame(TeamRole::ADMIN, $teamInvitation->role);
        $this->assertSame($team->id, $teamInvitation->team->id);
        $this->assertSame($inviter->id, $teamInvitation->invitedBy->id);
        $this->assertTrue(TeamInvitation::query()->pending()->whereKey($teamInvitation->id)->exists());
        $this->assertFalse(TeamInvitation::query()->pending()->whereKey($expiredInvitation->id)->exists());
    }

    public function test_monitoring_notification_states_track_read_state_and_service_updates(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'Monitoring is up',
            'read' => false,
            'sent' => false,
        ]);

        /** @var MonitoringNotificationState $monitoringNotificationState */
        $monitoringNotificationState = MonitoringNotificationState::query()
            ->where('monitoring_notification_id', $monitoringNotification->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame($monitoringNotification->id, $monitoringNotificationState->monitoringNotification->id);
        $this->assertSame($user->id, $monitoringNotificationState->user->id);
        $this->assertFalse($monitoringNotificationState->isRead());

        resolve(MonitoringNotificationStateService::class)->markRead($monitoringNotification, $user);
        $this->assertTrue($monitoringNotificationState->fresh()->isRead());

        $monitoringNotificationState->forceFill(['read_at' => now()])->save();

        $this->assertTrue($monitoringNotificationState->fresh()->isRead());
    }

    public function test_monitoring_response_preference_and_incident_update_relations_and_casts(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $monitoringResponse = MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => '200',
            'response_time' => '123.45',
            'server_health_metrics' => ['cpu' => 20],
        ]);
        $monitoringNotificationPreference = MonitoringNotificationPreference::query()->create([
            'monitoring_id' => $monitoring->id,
            'user_id' => $user->id,
            'notification_on_failure' => true,
            'notification_channels' => ['mail'],
            'ssl_expiry_warning_days' => 45,
        ]);
        $incident = Incident::query()->create([
            'monitoring_id' => $monitoring->id,
            'down_at' => now()->subHour(),
            'up_at' => now(),
        ]);
        $incidentUpdate = IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'status' => IncidentUpdateStatus::RESOLVED,
            'message' => 'Recovered',
        ]);

        $this->assertSame(MonitoringStatus::UP, $monitoringResponse->status);
        $this->assertSame(200, $monitoringResponse->http_status_code);
        $this->assertSame(123.45, $monitoringResponse->response_time);
        $this->assertSame(['cpu' => 20], $monitoringResponse->server_health_metrics);
        $this->assertSame($monitoring->id, $monitoringResponse->monitoring->id);
        $this->assertSame($user->id, $monitoringResponse->user->id);

        $this->assertTrue($monitoringNotificationPreference->notification_on_failure);
        $this->assertSame(['mail'], $monitoringNotificationPreference->notification_channels);
        $this->assertSame(45, $monitoringNotificationPreference->ssl_expiry_warning_days);
        $this->assertSame($monitoring->id, $monitoringNotificationPreference->monitoring->id);
        $this->assertSame($user->id, $monitoringNotificationPreference->user->id);

        $this->assertSame(IncidentUpdateStatus::RESOLVED, $incidentUpdate->status);
        $this->assertSame($incident->id, $incidentUpdate->incident->id);
    }

    public function test_monitoring_maintenance_windows_state_scope_route_binding_and_global_scope(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $upcoming = Monitoring::factory()->for($owner)->create([
            'maintenance_from' => now()->addHour(),
            'maintenance_until' => now()->addHours(2),
        ]);
        $ended = Monitoring::factory()->for($owner)->create([
            'maintenance_from' => now()->subHours(3),
            'maintenance_until' => now()->subHours(2),
        ]);
        $open = Monitoring::factory()->for($owner)->create([
            'maintenance_from' => now()->subHour(),
            'maintenance_until' => null,
        ]);
        $plain = Monitoring::factory()->for($owner)->create();
        $plain->maintenanceWindows()->create([
            'starts_at' => now()->subMinutes(5),
            'duration_minutes' => 60,
            'recurrence' => 'weekly',
            'timezone' => 'UTC',
            'enabled' => true,
        ]);

        $this->assertFalse($upcoming->isUnderMaintenance());
        $this->assertTrue($upcoming->hasUpcomingMaintenance());
        $this->assertFalse($upcoming->currentOrUpcomingMaintenanceWindow()['active']);
        $this->assertFalse($upcoming->currentOrUpcomingMaintenanceWindow()['recurring']);
        $this->assertFalse($ended->isUnderMaintenance());
        $this->assertNull($ended->currentOrUpcomingMaintenanceWindow());
        $this->assertFalse($ended->hasUpcomingMaintenance());
        $this->assertTrue($open->isUnderMaintenance());
        $this->assertNull($open->currentOrUpcomingMaintenanceWindow()['ends_at']);
        $this->assertTrue($open->currentOrUpcomingMaintenanceWindow()['active']);
        $this->assertFalse($open->hasUpcomingMaintenance());
        $this->assertTrue($plain->isUnderMaintenance());
        $this->assertTrue($plain->currentOrUpcomingMaintenanceWindow()['recurring'] ?? false);

        $flags = Monitoring::query()->withoutGlobalScope('user')->withMaintenanceWindowState()
            ->whereIn('id', [$plain->id, $open->id])
            ->get()
            ->mapWithKeys(fn (Monitoring $monitoring): array => [$monitoring->id => $monitoring->has_enabled_maintenance_windows]);
        $this->assertTrue($flags[$plain->id]);
        $this->assertNull($flags[$open->id]);

        $this->actingAs($owner);
        $bound = (new Monitoring)->resolveRouteBindingQuery(Monitoring::query(), $plain->id)->first();
        $this->assertTrue($bound->has_enabled_maintenance_windows);
        $this->assertCount(4, Monitoring::query()->get());

        $this->actingAs($other);
        $this->assertCount(0, Monitoring::query()->get());
    }

    public function test_monitoring_ownership_visibility_maintenance_locations_scopes_and_activity_options(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $admin->id]);
        $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::ADMIN]);
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::MEMBER]);

        $privateMonitoring = Monitoring::factory()->for($owner)->create([
            'type' => MonitoringType::HEARTBEAT,
            'status' => MonitoringLifecycleStatus::ACTIVE,
            'preferred_location' => 'de-1',
            'preferred_locations' => ['de-1', 'us-1', ''],
            'maintenance_from' => now()->subMinute(),
            'maintenance_until' => null,
        ]);
        $teamMonitoring = Monitoring::factory()->serverHealth()->create([
            'user_id' => null,
            'team_id' => $team->id,
            'created_by_user_id' => $admin->id,
            'status' => MonitoringLifecycleStatus::PAUSED,
            'preferred_location' => 'us-1',
            'preferred_locations' => ['us-1'],
            'maintenance_from' => now()->subHour(),
            'maintenance_until' => now()->addHour(),
        ]);
        Monitoring::factory()->for($outsider)->create([
            'preferred_location' => 'nl-1',
            'preferred_locations' => ['nl-1'],
        ]);

        $this->assertTrue($privateMonitoring->isActive());
        $this->assertFalse($privateMonitoring->isPaused());
        $this->assertTrue($privateMonitoring->isHeartbeat());
        $this->assertFalse($privateMonitoring->isServerHealth());
        $this->assertTrue($privateMonitoring->isPrivateOwned());
        $this->assertFalse($privateMonitoring->isTeamOwned());
        $this->assertTrue($privateMonitoring->isVisibleTo($owner));
        $this->assertFalse($privateMonitoring->isVisibleTo($outsider));
        $this->assertTrue($privateMonitoring->isManageableBy($owner));
        $this->assertFalse($privateMonitoring->isManageableBy($outsider));
        $this->assertTrue($privateMonitoring->isUnderMaintenance());
        $this->assertSame(['de-1', 'us-1'], $privateMonitoring->preferredLocationCodes());

        $this->assertTrue($teamMonitoring->isPaused());
        $this->assertTrue($teamMonitoring->isServerHealth());
        $this->assertTrue($teamMonitoring->isTeamOwned());
        $this->assertFalse($teamMonitoring->isPrivateOwned());
        $this->assertTrue($teamMonitoring->isVisibleTo($member));
        $this->assertTrue($teamMonitoring->isManageableBy($admin));
        $this->assertFalse($teamMonitoring->isManageableBy($member));
        $this->assertTrue($teamMonitoring->isUnderMaintenance());

        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->active()->whereKey($privateMonitoring->id)->exists());
        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->paused()->whereKey($teamMonitoring->id)->exists());
        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->visibleTo($member)->whereKey($teamMonitoring->id)->exists());
        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->manageableBy($admin)->whereKey($teamMonitoring->id)->exists());
        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->privateOwnedBy($owner)->whereKey($privateMonitoring->id)->exists());
        $this->assertTrue(Monitoring::query()->withoutGlobalScope('user')->assignedToLocation('us-1')->whereKey($teamMonitoring->id)->exists());
        $activityLogOptions = $privateMonitoring->getActivitylogOptions();

        $this->assertSame('monitoring', $activityLogOptions->logName);
        $this->assertSame('monitoring_created', ($activityLogOptions->descriptionForEvent)('created'));
    }
}
