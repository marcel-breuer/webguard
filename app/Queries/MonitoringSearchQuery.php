<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class MonitoringSearchQuery
{
    /** @return Collection<int, Monitoring> */
    public function for(User $user, string $search): Collection
    {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';

        return Monitoring::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(target) LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'target']);
    }
}
