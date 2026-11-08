<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Enums\NotificationChannel;
use App\Mail\MonitoringAlertMail;
use App\Models\User;
use App\Services\Notifications\NotificationPayload;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Delivers alerts only to the verified account email address, so alerts can never
 * be redirected to an unconfirmed third-party mailbox.
 */
class EmailChannelDriver implements NotificationChannelDriver
{
    public function channel(): string
    {
        return NotificationChannel::EMAIL->value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function isConfigured(array $config): bool
    {
        return $this->recipient($config) instanceof User;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function send(NotificationPayload $notificationPayload, array $config): void
    {
        $user = $this->recipient($config);

        throw_unless($user instanceof User, RuntimeException::class, 'Email notification requires a verified account email address.');

        Mail::to($user->email)
            ->locale($user->locale ?? config('app.locale'))
            ->queue(new MonitoringAlertMail($user, $notificationPayload->toArray()));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function recipient(array $config): ?User
    {
        $userId = $config['user_id'] ?? null;

        if (! is_string($userId) || $userId === '') {
            return null;
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User || blank($user->email) || ! $user->hasVerifiedEmail()) {
            return null;
        }

        return $user;
    }
}
