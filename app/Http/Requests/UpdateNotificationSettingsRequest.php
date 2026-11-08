<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Models\User;
use App\Rules\PubliclyRoutableUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'notification_channels' => ['nullable', 'array'],
            'notification_channels.slack.webhook_url' => ['nullable', 'url', 'max:2048', new PubliclyRoutableUrl()],
            'notification_channels.telegram.bot_token' => ['nullable', 'string', 'max:255'],
            'notification_channels.telegram.chat_id' => ['nullable', 'string', 'max:255'],
            'notification_channels.discord.webhook_url' => ['nullable', 'url', 'max:2048', new PubliclyRoutableUrl()],
            'notification_channels.teams.webhook_url' => ['nullable', 'url', 'max:2048', new PubliclyRoutableUrl()],
            'notification_channels.webhook.url' => ['nullable', 'url', 'max:2048', new PubliclyRoutableUrl()],
            'monitoring_digest_enabled' => ['nullable', 'boolean'],
            'monitoring_digest_frequency' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'unread_notifications_reminder_enabled' => ['nullable', 'boolean'],
            'unread_notifications_reminder_frequency' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
        ];

        foreach (NotificationChannel::values() as $channel) {
            $prefix = sprintf('notification_channels.%s', $channel);
            $rules[$prefix] = ['nullable', 'array'];
            $rules[$prefix . '.enabled'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ([NotificationChannel::SLACK, NotificationChannel::DISCORD, NotificationChannel::TEAMS] as $channel) {
                $key = 'notification_channels.' . $channel->value . '.webhook_url';

                if ($this->boolean('notification_channels.' . $channel->value . '.enabled') && blank($this->input($key))) {
                    $validator->errors()->add($key, __('validation.required'));
                }
            }

            if ($this->boolean('notification_channels.webhook.enabled') && blank($this->input('notification_channels.webhook.url'))) {
                $validator->errors()->add('notification_channels.webhook.url', __('validation.required'));
            }

            $user = $this->user();

            if (
                $this->boolean('notification_channels.email.enabled')
                && (! $user instanceof User || ! $user->hasVerifiedEmail())
            ) {
                $validator->errors()->add('notification_channels.email.enabled', __('profile.notification_settings.channels.email.unverified'));
            }

            if ($this->boolean('notification_channels.telegram.enabled')) {
                foreach (['bot_token', 'chat_id'] as $field) {
                    $key = 'notification_channels.telegram.' . $field;

                    if (blank($this->input($key))) {
                        $validator->errors()->add($key, __('validation.required'));
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'monitoring_digest_frequency' => $this->normalizeNullableFrequency('monitoring_digest_frequency'),
            'unread_notifications_reminder_frequency' => $this->normalizeNullableFrequency('unread_notifications_reminder_frequency'),
        ]);
    }

    private function normalizeNullableFrequency(string $key): ?string
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }
}
