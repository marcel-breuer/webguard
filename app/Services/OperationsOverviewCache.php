<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Monitoring;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class OperationsOverviewCache
{
    private const TAG = 'operations-overview';

    private const TTL_SECONDS = 30;

    /**
     * @template T of array{data: array<string, mixed>, service_pagination: array{current_page:int,last_page:int,total:int,from:int|null,to:int|null}}
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remember(User $user, int $servicePage, callable $callback): array
    {
        if (! $this->supportsTaggedCache()) {
            return $callback();
        }

        try {
            return Cache::tags($this->tags($user))->remember(
                $this->key($user, $servicePage),
                self::TTL_SECONDS,
                $callback,
            );
        } catch (Throwable) {
            return $callback();
        }
    }

    public function flushForMonitoring(Monitoring $monitoring): void
    {
        if (! $this->supportsTaggedCache()) {
            return;
        }

        try {
            $userIds = $monitoring->team_id === null
                ? collect()
                : TeamMembership::query()->where('team_id', $monitoring->team_id)->pluck('user_id');

            if ($monitoring->user_id !== null) {
                $userIds->push($monitoring->user_id);
            }

            foreach ($userIds->unique() as $userId) {
                Cache::tags([self::TAG . ':user:' . $userId])->flush();
            }
        } catch (Throwable) {
            // The bounded TTL keeps the projection fresh when the cache is unavailable.
        }
    }

    public function flush(): void
    {
        if (! $this->supportsTaggedCache()) {
            return;
        }

        try {
            Cache::tags([self::TAG])->flush();
        } catch (Throwable) {
            // The bounded TTL keeps the projection fresh when the cache is unavailable.
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(User $user): array
    {
        return [self::TAG, self::TAG . ':user:' . $user->getKey()];
    }

    public function key(User $user, int $servicePage): string
    {
        return sprintf('%s:user:%s:page:%d', self::TAG, $user->getKey(), max(1, $servicePage));
    }

    private function supportsTaggedCache(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }
}
