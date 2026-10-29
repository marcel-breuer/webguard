<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Enums\MonitoringLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OnboardingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['complete', 'dismiss'])],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($validated['action'] === 'complete') {
            $hasActiveMonitoring = Monitoring::query()
                ->visibleTo($user)
                ->where('status', MonitoringLifecycleStatus::ACTIVE->value)
                ->exists();

            if (! $hasActiveMonitoring) {
                throw ValidationException::withMessages([
                    'action' => __('onboarding.messages.active_monitoring_required'),
                ]);
            }

            if ($user->first_run_notification_tested_at === null) {
                throw ValidationException::withMessages([
                    'action' => __('onboarding.messages.successful_notification_test_required'),
                ]);
            }

            $user->forceFill(['first_run_setup_completed_at' => Date::now()])->save();
        } else {
            $user->forceFill(['first_run_setup_dismissed_at' => Date::now()])->save();
        }

        return response()->json([
            'data' => [
                'completed_at' => $user->first_run_setup_completed_at?->toIso8601String(),
                'dismissed_at' => $user->first_run_setup_dismissed_at?->toIso8601String(),
            ],
        ]);
    }
}
