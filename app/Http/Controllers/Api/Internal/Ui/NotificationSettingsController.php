<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Models\User;
use App\Services\Notifications\NotificationChannelTestService;
use App\Services\NotificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotificationSettingsController extends Controller
{
    public function show(Request $request, NotificationSettingsService $notificationSettingsService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $notificationSettingsService->settingsFor($user)]);
    }

    public function update(
        UpdateNotificationSettingsRequest $updateNotificationSettingsRequest,
        NotificationSettingsService $notificationSettingsService
    ): JsonResponse {
        /** @var User $user */
        $user = $updateNotificationSettingsRequest->user();
        $notificationSettingsService->update($user, $updateNotificationSettingsRequest);

        return response()->json(['data' => $notificationSettingsService->settingsFor($user)]);
    }

    public function test(
        Request $request,
        string $channel,
        NotificationChannelTestService $notificationChannelTestService
    ): JsonResponse {
        $notificationChannel = NotificationChannel::tryFrom($channel);
        abort_unless($notificationChannel instanceof NotificationChannel, 404);

        /** @var User $user */
        $user = $request->user();
        $config = data_get($user->notification_channels, $notificationChannel->value, []);
        $config = is_array($config) ? $config : [];
        $channelName = __('profile.notification_settings.channels.' . $notificationChannel->value . '.title');
        $errorKey = 'notification_channels.' . $notificationChannel->value;

        try {
            $notificationChannelTestService->send($user, $notificationChannel, $config);
            $user->forceFill(['first_run_notification_tested_at' => now()])->save();
        } catch (Throwable $throwable) {
            Log::warning('Notification channel test failed.', [
                'channel' => $notificationChannel->value,
                'user_id' => $user->id,
                'exception' => $throwable->getMessage(),
            ]);

            return response()->json([
                'message' => __('profile.notification_settings.test.messages.failed', ['channel' => $channelName]),
                'errors' => [$errorKey => [__('profile.notification_settings.test.messages.failed', ['channel' => $channelName])]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'channel' => $notificationChannel->value,
                'tested' => true,
            ],
        ]);
    }
}
