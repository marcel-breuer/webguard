<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Enums\SupportedLanguage;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileAccountRequest;
use App\Http\Requests\UpdateProfilePreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ProfileController extends Controller
{
    public function updatePreferences(UpdateProfilePreferencesRequest $updateProfilePreferencesRequest): JsonResponse
    {
        /** @var User $user */
        $user = $updateProfilePreferencesRequest->user();
        $preferences = $updateProfilePreferencesRequest->validated();

        $user->update($preferences);

        return response()
            ->json([
                'data' => [
                    'locale' => $user->locale,
                    'format_locale' => $user->format_locale,
                ],
            ])
            ->withCookie(cookie(
                SupportedLanguage::cookieName(),
                $user->locale,
                SupportedLanguage::cookieDurationMinutes(),
            ));
    }

    public function update(UpdateProfileAccountRequest $updateProfileAccountRequest): JsonResponse
    {
        /** @var User $user */
        $user = $updateProfileAccountRequest->user();
        $user->fill($updateProfileAccountRequest->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'is_verified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }
}
