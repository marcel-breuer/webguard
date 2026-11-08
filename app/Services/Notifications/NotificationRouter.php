<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationChannelDelivery;
use App\Models\User;
use App\Services\Notifications\Channels\DiscordChannelDriver;
use App\Services\Notifications\Channels\EmailChannelDriver;
use App\Services\Notifications\Channels\MobilePushChannelDriver;
use App\Services\Notifications\Channels\NotificationChannelDriver;
use App\Services\Notifications\Channels\SlackChannelDriver;
use App\Services\Notifications\Channels\TeamsChannelDriver;
use App\Services\Notifications\Channels\TelegramChannelDriver;
use App\Services\Notifications\Channels\WebhookChannelDriver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationRouter
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
     * @param  list<string>|null  $selectedChannels
     */
    public function dispatch(User $user, NotificationPayload $notificationPayload, ?array $selectedChannels = null): bool
    {
        $channels = $this->resolveChannels($user, $selectedChannels);
        $monitoringNotificationId = $this->resolveMonitoringNotificationId($notificationPayload);

        $hasSuccess = false;

        foreach ($channels as $channel => $config) {
            $driver = $this->drivers[$channel] ?? null;

            if (! $driver) {
                continue;
            }

            if (! $driver->isConfigured($config)) {
                Log::warning('Skipping misconfigured notification channel.', [
                    'channel' => $channel,
                    'user_id' => $user->id,
                    'event_type' => $notificationPayload->eventType->value,
                ]);
                $this->logDelivery(
                    $user,
                    $notificationPayload,
                    $channel,
                    NotificationDeliveryStatus::SKIPPED,
                    $monitoringNotificationId,
                    'Channel is enabled but missing required credentials.'
                );

                continue;
            }

            try {
                $driver->send($notificationPayload, $config);
                $this->logDelivery(
                    $user,
                    $notificationPayload,
                    $channel,
                    NotificationDeliveryStatus::SENT,
                    $monitoringNotificationId,
                    sentAt: now()
                );
                $hasSuccess = true;
            } catch (Throwable $throwable) {
                Log::error('Notification delivery failed.', [
                    'channel' => $channel,
                    'user_id' => $user->id,
                    'event_type' => $notificationPayload->eventType->value,
                    'exception' => $throwable->getMessage(),
                ]);
                $this->logDelivery(
                    $user,
                    $notificationPayload,
                    $channel,
                    NotificationDeliveryStatus::FAILED,
                    $monitoringNotificationId,
                    $throwable->getMessage()
                );
            }
        }

        return $hasSuccess;
    }

    /**
     * @param  list<string>|null  $selectedChannels
     * @return array<string, array<string, mixed>>
     */
    private function resolveChannels(User $user, ?array $selectedChannels): array
    {
        $configuredChannels = is_array($user->notification_channels) ? $user->notification_channels : [];
        $selectedChannels = $selectedChannels === null ? null : array_flip($selectedChannels);
        $activeChannels = [];

        foreach ($this->drivers as $channel => $driver) {
            if (is_array($selectedChannels) && ! array_key_exists($channel, $selectedChannels)) {
                continue;
            }

            $channelConfig = $configuredChannels[$channel] ?? null;

            if (! is_array($channelConfig)) {
                continue;
            }

            $enabled = (bool) ($channelConfig['enabled'] ?? false);

            if ($enabled) {
                $channelConfig['user_id'] = $user->id;
                $activeChannels[$channel] = $channelConfig;
            }
        }

        return $activeChannels;
    }

    private function resolveMonitoringNotificationId(NotificationPayload $notificationPayload): ?string
    {
        $notificationId = data_get($notificationPayload->meta, 'notification_id');

        if (! is_string($notificationId) || $notificationId === '') {
            return null;
        }

        return $notificationId;
    }

    private function logDelivery(
        User $user,
        NotificationPayload $notificationPayload,
        string $channel,
        NotificationDeliveryStatus $notificationDeliveryStatus,
        ?string $monitoringNotificationId = null,
        ?string $errorMessage = null,
        ?CarbonInterface $sentAt = null
    ): void {
        NotificationChannelDelivery::query()->create([
            'user_id' => $user->id,
            'monitoring_notification_id' => $monitoringNotificationId,
            'channel' => $channel,
            'event_type' => $notificationPayload->eventType->value,
            'status' => $notificationDeliveryStatus,
            'payload' => $notificationPayload->toArray(),
            'error_message' => $errorMessage,
            'sent_at' => $sentAt,
        ]);
    }
}
