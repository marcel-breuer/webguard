<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Models\User;
use Illuminate\Http\Request;

final class NotificationSettingsService
{
    public function update(User $user, Request $request): void
    {
        $user->notification_channels = $this->normalizeNotificationChannels($request);
        $user->monitoring_digest_enabled = $request->boolean('monitoring_digest_enabled');
        $user->monitoring_digest_frequency = $this->normalizeFrequency(
            $request->input('monitoring_digest_frequency'),
            'weekly'
        );
        $user->unread_notifications_reminder_enabled = $request->boolean('unread_notifications_reminder_enabled');
        $user->unread_notifications_reminder_frequency = $this->normalizeFrequency(
            $request->input('unread_notifications_reminder_frequency'),
            'daily'
        );
        $user->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsFor(User $user): array
    {
        return [
            'notification_channels' => $this->normalizeStoredNotificationChannels($user),
            'monitoring_digest_enabled' => $user->monitoring_digest_enabled,
            'monitoring_digest_frequency' => $user->monitoring_digest_frequency,
            'unread_notifications_reminder_enabled' => $user->unread_notifications_reminder_enabled,
            'unread_notifications_reminder_frequency' => $user->unread_notifications_reminder_frequency,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNotificationChannels(Request $request): array
    {
        $normalized = [];

        foreach (NotificationChannel::values() as $channel) {
            if (
                in_array($channel, [NotificationChannel::MOBILE_PUSH->value, NotificationChannel::EMAIL->value], true)
                && ! $request->has('notification_channels.' . $channel)
            ) {
                $existingConfig = data_get($request->user()?->notification_channels, $channel, []);
                $normalized[$channel] = is_array($existingConfig) ? $existingConfig : ['enabled' => false];

                continue;
            }

            $channelConfig = [
                'enabled' => $request->boolean(sprintf('notification_channels.%s.enabled', $channel)),
            ];

            if (in_array($channel, [
                NotificationChannel::SLACK->value,
                NotificationChannel::DISCORD->value,
                NotificationChannel::TEAMS->value,
            ], true)) {
                $channelConfig['webhook_url'] = mb_trim((string) $request->input(sprintf('notification_channels.%s.webhook_url', $channel)));
            }

            if ($channel === NotificationChannel::WEBHOOK->value) {
                $channelConfig['url'] = mb_trim((string) $request->input('notification_channels.webhook.url'));
            }

            if ($channel === NotificationChannel::TELEGRAM->value) {
                $channelConfig['bot_token'] = mb_trim((string) $request->input('notification_channels.telegram.bot_token'));
                $channelConfig['chat_id'] = mb_trim((string) $request->input('notification_channels.telegram.chat_id'));
            }

            $normalized[$channel] = $channelConfig;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeStoredNotificationChannels(User $user): array
    {
        $channels = is_array($user->notification_channels) ? $user->notification_channels : [];

        foreach (NotificationChannel::values() as $channel) {
            $config = $channels[$channel] ?? [];
            $channels[$channel] = is_array($config) ? $config : [];
            $channels[$channel]['enabled'] = (bool) ($channels[$channel]['enabled'] ?? false);
        }

        // The recipient is always derived from the account, never stored, so a changed or
        // unverified account email cannot leave alerts pointing at a stale address.
        $channels[NotificationChannel::EMAIL->value] = [
            'enabled' => $channels[NotificationChannel::EMAIL->value]['enabled'],
            'address' => $user->email,
            'verified' => $user->hasVerifiedEmail(),
        ];

        return $channels;
    }

    private function normalizeFrequency(mixed $value, string $default): string
    {
        return blank($value) ? $default : (string) $value;
    }
}
