<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

class ApiKeyService
{
    public const TOKEN_NAME_PREFIX = 'webguard-api-key:';

    public const LEGACY_TOKEN_NAME = 'api-access';

    public static function storedName(string $displayName): string
    {
        return self::TOKEN_NAME_PREFIX . $displayName;
    }

    public static function displayName(PersonalAccessToken $personalAccessToken): string
    {
        return Str::startsWith($personalAccessToken->name, self::TOKEN_NAME_PREFIX)
            ? Str::after($personalAccessToken->name, self::TOKEN_NAME_PREFIX)
            : $personalAccessToken->name;
    }

    public static function isManagedKey(PersonalAccessToken $personalAccessToken): bool
    {
        return Str::startsWith($personalAccessToken->name, self::TOKEN_NAME_PREFIX);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public function create(User $user, string $displayName, array $abilities): NewAccessToken
    {
        return $user->createToken(self::storedName($displayName), $abilities);
    }

    /**
     * @return LengthAwarePaginator<int, \Laravel\Sanctum\PersonalAccessToken>
     */
    public function paginate(User $user, int $perPage, ?string $state): LengthAwarePaginator
    {
        return $user->tokens()
            ->where(function ($query): void {
                $query->where('name', 'like', self::TOKEN_NAME_PREFIX . '%')
                    ->orWhere('name', self::LEGACY_TOKEN_NAME);
            })
            ->when($state === 'active', fn ($query) => $query->whereNull('revoked_at'))
            ->when($state === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))->latest()
            ->paginate($perPage);
    }

    public function findForUser(User $user, int $tokenId): ?PersonalAccessToken
    {
        $token = $user->tokens()
            ->whereKey($tokenId)
            ->where(function ($query): void {
                $query->where('name', 'like', self::TOKEN_NAME_PREFIX . '%')
                    ->orWhere('name', self::LEGACY_TOKEN_NAME);
            })
            ->first();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    public function revoke(PersonalAccessToken $personalAccessToken): bool
    {
        if ($personalAccessToken->revoked_at !== null) {
            return false;
        }

        $personalAccessToken->forceFill(['revoked_at' => now()])->save();

        return true;
    }
}
