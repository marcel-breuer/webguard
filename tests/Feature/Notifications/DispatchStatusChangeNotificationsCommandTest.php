<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationType;
use App\Mail\PublicStatusPageStatusUpdateMail;
use App\Mail\StatusPageStatusUpdateMail;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\StatusPageSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DispatchStatusChangeNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_status_change_to_enabled_user_channels(): void
    {
        Package::factory()->create();
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.test/services/test',
                ],
            ],
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => true,
            'notification_channels' => ['slack'],
        ]);

        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake([
            'https://hooks.slack.test/*' => Http::response(['ok' => true], 200),
        ]);

        Artisan::call('notifications:dispatch-status-changes');

        $monitoringNotification->refresh();
        $this->assertTrue($monitoringNotification->sent);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://hooks.slack.test/services/test'
                && data_get($request->data(), 'payload.event_type') === 'incident';
        });
        $this->assertDatabaseHas('notification_channel_deliveries', [
            'user_id' => $user->id,
            'monitoring_notification_id' => $monitoringNotification->id,
            'channel' => 'slack',
            'event_type' => NotificationEventType::INCIDENT->value,
            'status' => NotificationDeliveryStatus::SENT->value,
        ]);
    }

    public function test_multi_location_incident_sends_human_readable_and_structured_consensus_evidence(): void
    {
        Package::factory()->create();
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.test/services/test',
                ],
            ],
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => true,
            'notification_channels' => ['slack'],
        ]);
        $snapshot = [
            'status' => 'regional',
            'total_locations' => 3,
            'reporting_locations' => 2,
            'required_failures' => 2,
            'affected_locations' => ['de-1', 'us-1'],
            'locations' => [
                ['code' => 'de-1', 'status' => 'down', 'checked_at' => '2026-10-29T12:00:00+00:00', 'availability' => 'reporting'],
                ['code' => 'us-1', 'status' => 'down', 'checked_at' => '2026-10-29T12:00:00+00:00', 'availability' => 'reporting'],
                ['code' => 'jp-1', 'status' => 'unknown', 'checked_at' => null, 'availability' => 'missing'],
            ],
        ];
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'metadata' => ['regional_consensus' => $snapshot],
            'read' => false,
            'sent' => false,
        ]);

        Http::fake([
            'https://hooks.slack.test/*' => Http::response(['ok' => true], 200),
        ]);

        Artisan::call('notifications:dispatch-status-changes');

        Http::assertSent(function ($request) use ($snapshot): bool {
            $payload = $request->data()['payload'] ?? [];

            return data_get($payload, 'event_type') === 'incident'
                && str_contains((string) data_get($payload, 'message'), 'Regional outage')
                && str_contains((string) data_get($payload, 'message'), '2 of 2 reporting locations failed')
                && str_contains((string) data_get($payload, 'message'), 'jp-1 (missing)')
                && data_get($payload, 'meta.regional_consensus') === $snapshot;
        });

        $this->assertTrue($monitoringNotification->refresh()->sent);
    }

    public function test_command_respects_per_monitoring_notification_flag(): void
    {
        Package::factory()->create();
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.test/services/test',
                ],
            ],
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => false,
            'notification_channels' => ['slack'],
        ]);

        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake();

        Artisan::call('notifications:dispatch-status-changes');

        $monitoringNotification->refresh();
        $this->assertTrue($monitoringNotification->sent);
        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_channel_deliveries', 0);
    }

    public function test_dispatches_a_degraded_performance_notification_without_notifying_status_page_subscribers(): void
    {
        Mail::fake();

        Package::factory()->create();
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.test/services/test',
                ],
            ],
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => true,
            'notification_channels' => ['slack'],
            'public_label_enabled' => true,
        ]);
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::PERFORMANCE,
            'message' => 'DEGRADED',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake([
            'https://hooks.slack.test/*' => Http::response(['ok' => true], 200),
        ]);

        Artisan::call('notifications:dispatch-status-changes');

        $this->assertTrue($monitoringNotification->refresh()->sent);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'payload.event_type') === NotificationEventType::PERFORMANCE_DEGRADED->value);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('notification_channel_deliveries', [
            'monitoring_notification_id' => $monitoringNotification->id,
            'event_type' => NotificationEventType::PERFORMANCE_DEGRADED->value,
        ]);
    }

    public function test_command_respects_per_monitoring_channel_selection(): void
    {
        Package::factory()->create();
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.test/services/test',
                ],
                'webhook' => [
                    'enabled' => true,
                    'url' => 'https://example.test/webhook',
                ],
            ],
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => true,
            'notification_channels' => ['webhook'],
        ]);

        MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        Artisan::call('notifications:dispatch-status-changes');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.test/webhook');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://hooks.slack.test/services/test');
    }

    public function test_dispatches_status_change_email_to_verified_public_status_subscribers(): void
    {
        Mail::fake();

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'notification_on_failure' => false,
            'public_label_enabled' => true,
        ]);

        $statusPageSubscriber = StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'verified@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'verified-token',
            'verified_at' => Date::now(),
        ]);
        StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'pending@example.com',
            'confirmation_token_hash' => StatusPageSubscriber::hashToken('pending-token'),
            'unsubscribe_token' => 'pending-token',
            'verified_at' => null,
        ]);

        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake();

        Artisan::call('notifications:dispatch-status-changes');

        $monitoringNotification->refresh();
        $this->assertTrue($monitoringNotification->sent);

        Mail::assertSent(StatusPageStatusUpdateMail::class, function (StatusPageStatusUpdateMail $statusPageStatusUpdateMail) use ($statusPageSubscriber): bool {
            return $statusPageStatusUpdateMail->hasTo('verified@example.com')
                && $statusPageStatusUpdateMail->subscriber->is($statusPageSubscriber)
                && $statusPageStatusUpdateMail->status === 'down';
        });
        Mail::assertNotSent(StatusPageStatusUpdateMail::class, fn (StatusPageStatusUpdateMail $statusPageStatusUpdateMail): bool => $statusPageStatusUpdateMail->hasTo('pending@example.com'));
        Http::assertNothingSent();
    }

    public function test_dispatches_status_change_email_to_verified_public_component_status_page_subscribers(): void
    {
        Mail::fake();

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Primary API',
            'notification_on_failure' => false,
            'public_label_enabled' => false,
        ]);
        $outsideMonitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Internal Worker',
        ]);

        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageComponent = $statusPage->components()->create(['name' => 'API', 'position' => 0]);
        $statusPageComponent->monitorings()->attach($monitoring->id, ['position' => 0]);

        $outsideStatusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Outside Status',
            'slug' => 'outside-status',
            'is_public' => true,
        ]);
        $outsideComponent = $outsideStatusPage->components()->create(['name' => 'Workers', 'position' => 0]);
        $outsideComponent->monitorings()->attach($outsideMonitoring->id, ['position' => 0]);

        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'verified@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'verified-token',
            'verified_at' => Date::now(),
        ]);
        StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'pending@example.com',
            'confirmation_token_hash' => StatusPageSubscription::hashToken('pending-token'),
            'unsubscribe_token' => 'pending-token',
            'verified_at' => null,
        ]);
        StatusPageSubscription::query()->create([
            'status_page_id' => $outsideStatusPage->id,
            'email' => 'outside@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'outside-token',
            'verified_at' => Date::now(),
        ]);

        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'read' => false,
            'sent' => false,
        ]);

        Http::fake();

        Artisan::call('notifications:dispatch-status-changes');

        $this->assertTrue($monitoringNotification->refresh()->sent);
        Mail::assertSent(PublicStatusPageStatusUpdateMail::class, function (PublicStatusPageStatusUpdateMail $publicStatusPageStatusUpdateMail) use ($statusPageSubscription, $monitoring): bool {
            return $publicStatusPageStatusUpdateMail->hasTo('verified@example.com')
                && $publicStatusPageStatusUpdateMail->subscription->is($statusPageSubscription)
                && $publicStatusPageStatusUpdateMail->monitoring->is($monitoring)
                && $publicStatusPageStatusUpdateMail->status === 'down';
        });
        Mail::assertNotSent(PublicStatusPageStatusUpdateMail::class, fn (PublicStatusPageStatusUpdateMail $publicStatusPageStatusUpdateMail): bool => $publicStatusPageStatusUpdateMail->hasTo('pending@example.com'));
        Mail::assertNotSent(PublicStatusPageStatusUpdateMail::class, fn (PublicStatusPageStatusUpdateMail $publicStatusPageStatusUpdateMail): bool => $publicStatusPageStatusUpdateMail->hasTo('outside@example.com'));
        Http::assertNothingSent();
    }
}
