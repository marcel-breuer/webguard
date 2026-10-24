<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $teams = $user->teamMemberships()
            ->with('team:id,name')->oldest()
            ->get()
            ->map(static fn (TeamMembership $teamMembership): array => [
                'id' => $teamMembership->team_id,
                'name' => $teamMembership->team->name,
                'role' => $teamMembership->role->value,
            ])
            ->values();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'locale' => $user->locale,
                    'format_locale' => $user->format_locale,
                    'theme' => $user->theme,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'is_verified' => $user->hasVerifiedEmail(),
                ],
                'teams' => $teams,
                'csrf_endpoint' => route('sanctum.csrf-cookie', absolute: false),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'data' => [
                'authenticated' => false,
            ],
        ]);
    }
}
