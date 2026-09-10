<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\NotificationType;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InternalUiNotificationWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_member_can_view_update_and_test_their_notification_settings(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => [
                    'enabled' => false,
                    'webhook_url' => 'https://hooks.slack.com/services/T000/B000/OLD',
                ],
            ],
        ]);

        $this->actingAs($user)->getJson(route('app.profile.notification-settings.show'))
            ->assertOk()
            ->assertJsonPath('data.notification_channels.slack.webhook_url', 'https://hooks.slack.com/services/T000/B000/OLD')
            ->assertJsonPath('data.notification_channels.mobile_push.enabled', false);

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => [
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://hooks.slack.com/services/T000/B000/NEW',
                ],
                'telegram' => [
                    'enabled' => true,
                    'bot_token' => '12345:ABCDEF',
                    'chat_id' => '-1001234567',
                ],
            ],
            'monitoring_digest_enabled' => true,
            'monitoring_digest_frequency' => 'monthly',
            'unread_notifications_reminder_enabled' => true,
            'unread_notifications_reminder_frequency' => 'weekly',
        ])->assertOk()
            ->assertJsonPath('data.notification_channels.slack.enabled', true)
            ->assertJsonPath('data.notification_channels.telegram.chat_id', '-1001234567')
            ->assertJsonPath('data.monitoring_digest_frequency', 'monthly');

        $this->actingAs($user)->postJson(route('app.profile.notification-settings.test', ['channel' => 'slack']))
            ->assertOk()
            ->assertJsonPath('data.channel', 'slack')
            ->assertJsonPath('data.tested', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'monitoring_digest_enabled' => true,
            'monitoring_digest_frequency' => 'monthly',
        ]);
        Http::assertSentCount(1);
    }

    public function test_notification_settings_require_authenticated_publicly_routable_channel_configuration(): void
    {
        $this->patchJson(route('app.profile.notification-settings.update'), [])
            ->assertUnauthorized();

        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('app.profile.notification-settings.update'), [
            'notification_channels' => [
                'webhook' => [
                    'enabled' => true,
                    'url' => 'http://127.0.0.1:8080/notifications',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['notification_channels.webhook.url']);

        $this->actingAs($user)->postJson(route('app.profile.notification-settings.test', ['channel' => 'invalid']))
            ->assertNotFound();
    }

    public function test_member_notification_inbox_is_cursor_paginated_scoped_and_read_safe(): void
    {
        Date::setTestNow('2026-08-24 12:00:00');
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $monitoringNotification = $this->notification($monitoring, NotificationType::STATUS_CHANGE, 'Service down', Date::now()->subMinutes(2));
        $second = $this->notification($monitoring, NotificationType::SSL_EXPIRY, 'SSL_EXPIRING', Date::now()->subMinute());
        $hiddenMonitoring = Monitoring::factory()->for(User::factory()->create())->create();
        $hidden = $this->notification($hiddenMonitoring, NotificationType::DOMAIN_EXPIRY, 'DOMAIN_EXPIRED', Date::now());

        $testResponse = $this->actingAs($user)->getJson(route('app.notifications.index', ['limit' => 1]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.event_type', 'ssl_expiring')
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonMissing(['id' => $hidden->id]);

        $this->actingAs($user)->getJson(route('app.notifications.index', [
            'limit' => 1,
            'cursor' => $testResponse->json('meta.next_cursor'),
        ]))->assertOk()->assertJsonPath('data.0.id', $monitoringNotification->id);

        $this->actingAs($user)->patchJson(route('app.notifications.read', ['notification' => $second->id]))
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.read_notification_ids.0', $second->id)
            ->assertJsonPath('meta.unread_count', 1);

        $this->actingAs($user)->patchJson(route('app.notifications.read', ['notification' => $hidden->id]))
            ->assertNotFound();

        $this->actingAs($user)->patchJson(route('app.notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_member_notification_inbox_defaults_to_five_unread_entries(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach (range(1, 6) as $index) {
            $this->notification($monitoring, NotificationType::SSL_EXPIRY, "SSL_EXPIRING_{$index}", now()->subMinutes($index));
        }

        $this->actingAs($user)->getJson(route('app.notifications.index'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.unread_count', 6);
    }

    public function test_member_can_mark_recovery_notification_as_read(): void
    {
        Date::setTestNow('2026-08-24 12:00:00');
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $monitoringNotification = $this->notification($monitoring, NotificationType::STATUS_CHANGE, 'Service down', Date::now()->subMinute());
        $recovery = $this->notification($monitoring, NotificationType::STATUS_CHANGE, 'Monitoring is up', Date::now());

        $this->actingAs($user)->patchJson(route('app.notifications.read', ['notification' => $recovery->id]))
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.read_notification_ids', [$recovery->id])
            ->assertJsonPath('meta.unread_count', 1);

        $this->assertNotNull($recovery->states()->where('user_id', $user->id)->value('read_at'));
        $this->assertNull($monitoringNotification->states()->where('user_id', $user->id)->value('read_at'));

        $this->actingAs($user)->getJson(route('app.notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $monitoringNotification->id)
            ->assertJsonPath('meta.unread_count', 1);
    }

    private function notification(Monitoring $monitoring, NotificationType $notificationType, string $message, mixed $createdAt): MonitoringNotification
    {
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => $notificationType,
            'message' => $message,
        ]);
        $monitoringNotification->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $monitoringNotification->refresh();
    }
}
