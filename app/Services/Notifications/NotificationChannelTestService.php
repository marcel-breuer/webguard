<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Models\User;
use App\Services\Notifications\Channels\DiscordChannelDriver;
use App\Services\Notifications\Channels\EmailChannelDriver;
use App\Services\Notifications\Channels\MobilePushChannelDriver;
use App\Services\Notifications\Channels\NotificationChannelDriver;
use App\Services\Notifications\Channels\SlackChannelDriver;
use App\Services\Notifications\Channels\TeamsChannelDriver;
use App\Services\Notifications\Channels\TelegramChannelDriver;
use App\Services\Notifications\Channels\WebhookChannelDriver;
use InvalidArgumentException;

class NotificationChannelTestService
{
    /**
     * @var array<string, NotificationChannelDriver>
     */
    private array $drivers;

    public function __construct(
        SlackChannelDriver $slackChannelDriver,
        TelegramChannelDriver $telegramChannelDriver,
        DiscordChannelDriver $discordChannelDriver,
        TeamsChannelDriver $teamsChannelDriver,
        WebhookChannelDriver $webhookChannelDriver,
        MobilePushChannelDriver $mobilePushChannelDriver,
        EmailChannelDriver $emailChannelDriver
    ) {
        $this->drivers = [
            $slackChannelDriver->channel() => $slackChannelDriver,
            $telegramChannelDriver->channel() => $telegramChannelDriver,
            $discordChannelDriver->channel() => $discordChannelDriver,
            $teamsChannelDriver->channel() => $teamsChannelDriver,
            $webhookChannelDriver->channel() => $webhookChannelDriver,
            $mobilePushChannelDriver->channel() => $mobilePushChannelDriver,
            $emailChannelDriver->channel() => $emailChannelDriver,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function send(User $user, NotificationChannel $notificationChannel, array $config): void
    {
        $config['user_id'] = $user->id;
        $driver = $this->drivers[$notificationChannel->value] ?? null;

        throw_unless($driver, InvalidArgumentException::class, 'Unsupported notification channel.');
        throw_unless($driver->isConfigured($config), InvalidArgumentException::class, 'Notification channel is not configured.');

        $driver->send($this->payload($user, $notificationChannel), $config);
    }

    private function payload(User $user, NotificationChannel $notificationChannel): NotificationPayload
    {
        return new NotificationPayload(
            eventType: NotificationEventType::INCIDENT,
            title: __('profile.notification_settings.test.payload.title'),
            message: __('profile.notification_settings.test.payload.message', [
                'channel' => __('profile.notification_settings.channels.' . $notificationChannel->value . '.title'),
            ]),
            severity: 'info',
            monitoringId: null,
            monitoringName: null,
            monitoringTarget: null,
            occurredAt: now(),
            meta: [
                'test' => true,
                'channel' => $notificationChannel->value,
                'user_id' => $user->id,
            ]
        );
    }
}
