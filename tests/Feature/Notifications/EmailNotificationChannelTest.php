<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationType;
use App\Mail\MonitoringAlertMail;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\Package;
use App\Models\User;
use App\Services\Notifications\NotificationPayload;
use App\Services\Notifications\NotificationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class EmailNotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
        Mail::fake();
        Http::fake();
    }

    public function test_status_change_dispatch_queues_email_with_multi_location_evidence_and_tracks_delivery(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'locale' => 'en',
            'notification_channels' => ['email' => ['enabled' => true]],
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Checkout API',
            'notification_on_failure' => true,
            'notification_channels' => ['email'],
        ]);
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'metadata' => ['regional_consensus' => [
                'status' => 'global',
                'total_locations' => 3,
                'reporting_locations' => 2,
                'required_failures' => 2,
                'affected_locations' => ['de-1', 'us-1'],
                'locations' => [
                    ['code' => 'de-1', 'status' => 'down', 'checked_at' => '2026-10-29T12:00:00+00:00', 'availability' => 'reporting'],
                    ['code' => 'us-1', 'status' => 'down', 'checked_at' => '2026-10-29T12:00:00+00:00', 'availability' => 'reporting'],
                    ['code' => 'jp-1', 'status' => 'unknown', 'checked_at' => null, 'availability' => 'missing'],
                ],
            ]],
            'read' => false,
            'sent' => false,
        ]);

        Artisan::call('notifications:dispatch-status-changes');

        Mail::assertQueued(MonitoringAlertMail::class, function (MonitoringAlertMail $monitoringAlertMail) use ($monitoring): bool {
            $html = $monitoringAlertMail->render();

            return $monitoringAlertMail->hasTo('owner@example.test')
                && $monitoringAlertMail->locale === 'en'
                && $monitoringAlertMail->envelope()->subject === 'Incident: Checkout API'
                && str_contains($html, 'Global outage: 2 of 2 reporting locations failed. 2 failures were required from 3 selected locations.')
                && str_contains($html, 'jp-1')
                && str_contains($html, 'missing')
                && str_contains($html, '/monitorings/' . $monitoring->id);
        });
        $this->assertDatabaseHas('notification_channel_deliveries', [
            'user_id' => $user->id,
            'monitoring_notification_id' => $monitoringNotification->id,
            'channel' => 'email',
            'event_type' => NotificationEventType::INCIDENT->value,
            'status' => NotificationDeliveryStatus::SENT->value,
        ]);
    }

    public function test_email_channel_is_skipped_for_unverified_account_email(): void
    {
        $user = User::factory()->unverified()->create([
            'notification_channels' => ['email' => ['enabled' => true]],
        ]);

        $wasDelivered = resolve(NotificationRouter::class)->dispatch($user, $this->payload(NotificationEventType::INCIDENT), ['email']);

        $this->assertFalse($wasDelivered);
        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notification_channel_deliveries', [
            'user_id' => $user->id,
            'channel' => 'email',
            'status' => NotificationDeliveryStatus::SKIPPED->value,
        ]);
    }

    public function test_email_is_not_sent_when_monitoring_preference_excludes_it(): void
    {
        $user = User::factory()->create([
            'notification_channels' => ['email' => ['enabled' => true]],
        ]);

        resolve(NotificationRouter::class)->dispatch($user, $this->payload(NotificationEventType::INCIDENT), ['slack']);

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('notification_channel_deliveries', 0);
    }

    public function test_expiry_alert_email_is_localized_for_the_recipient(): void
    {
        $user = User::factory()->create([
            'email' => 'inhaber@example.test',
            'locale' => 'de',
            'notification_channels' => ['email' => ['enabled' => true]],
        ]);

        resolve(NotificationRouter::class)->dispatch($user, $this->payload(NotificationEventType::DOMAIN_EXPIRING, 'warning'), ['email']);

        Mail::assertQueued(MonitoringAlertMail::class, function (MonitoringAlertMail $monitoringAlertMail): bool {
            $html = $monitoringAlertMail->render();

            return $monitoringAlertMail->hasTo('inhaber@example.test')
                && $monitoringAlertMail->locale === 'de'
                && str_contains($html, 'Die Domainregistrierung Ihrer Überwachung &quot;Shop&quot; läuft bald ab.')
                && str_contains($html, 'Warnung')
                && str_contains($html, 'Domain example.test expires in 5 days.');
        });
    }

    public function test_profile_settings_expose_derived_email_recipient_and_persist_only_enabled_flag(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($user)->getJson(route('app.profile.notification-settings.show'))
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email.enabled', false)
            ->assertJsonPath('data.notification_channels.email.address', 'member@example.test')
            ->assertJsonPath('data.notification_channels.email.verified', true);

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => ['email' => ['enabled' => true, 'address' => 'attacker@example.test']],
        ])->assertOk()
            ->assertJsonPath('data.notification_channels.email.enabled', true)
            ->assertJsonPath('data.notification_channels.email.address', 'member@example.test');

        $this->assertSame(['enabled' => true], $user->fresh()->notification_channels['email']);
        $this->assertContains('email', $user->fresh()->enabledNotificationChannelKeys());

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => ['slack' => ['enabled' => false]],
        ])->assertOk()
            ->assertJsonPath('data.notification_channels.email.enabled', true);

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => ['email' => ['enabled' => '0']],
        ])->assertOk()
            ->assertJsonPath('data.notification_channels.email.enabled', false);
    }

    public function test_unverified_member_cannot_enable_or_test_email_alerts(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => ['email' => ['enabled' => true]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['notification_channels.email.enabled']);

        $this->actingAs($user)->postJson(route('app.profile.notification-settings.test', ['channel' => 'email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notification_channels.email']);

        Mail::assertNothingQueued();
        $this->assertNull($user->fresh()->first_run_notification_tested_at);
    }

    public function test_verified_member_can_send_an_email_test_notification(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($user)->postJson(route('app.profile.notification-settings.test', ['channel' => 'email']))
            ->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.tested', true);

        Mail::assertQueued(MonitoringAlertMail::class, function (MonitoringAlertMail $monitoringAlertMail): bool {
            return $monitoringAlertMail->hasTo('member@example.test')
                && $monitoringAlertMail->envelope()->subject === 'WebGuard test notification'
                && str_contains($monitoringAlertMail->render(), 'Your email notification channel is configured correctly.');
        });
        $this->assertNotNull($user->fresh()->first_run_notification_tested_at);
    }

    public function test_monitoring_notification_preferences_accept_email_only_when_enabled(): void
    {
        $user = User::factory()->create(['notification_channels' => ['email' => ['enabled' => true]]]);
        $monitoring = Monitoring::factory()->for($user)->create();

        $this->actingAs($user)->getJson(route('app.monitorings.notification-preferences.show', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.permitted_channels', ['email']);

        $this->actingAs($user)->patchJson(route('app.monitorings.notification-preferences.update', $monitoring), [
            'notification_on_failure' => true,
            'notification_channels' => ['email'],
            'ssl_expiry_warning_days' => 14,
        ])->assertOk()
            ->assertJsonPath('data.effective.notification_channels', ['email']);

        $otherUser = User::factory()->create();
        $otherMonitoring = Monitoring::factory()->for($otherUser)->create();

        $this->actingAs($otherUser)->patchJson(route('app.monitorings.notification-preferences.update', $otherMonitoring), [
            'notification_on_failure' => true,
            'notification_channels' => ['email'],
            'ssl_expiry_warning_days' => 14,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['notification_channels.0']);
    }

    private function payload(NotificationEventType $notificationEventType, string $severity = 'critical'): NotificationPayload
    {
        return new NotificationPayload(
            eventType: $notificationEventType,
            title: 'Monitoring alert',
            message: 'Domain example.test expires in 5 days.',
            severity: $severity,
            monitoringId: '01TESTMONITORING',
            monitoringName: 'Shop',
            monitoringTarget: 'https://example.test',
            occurredAt: now(),
        );
    }
}
