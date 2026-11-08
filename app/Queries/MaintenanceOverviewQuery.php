<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

final class MaintenanceOverviewQuery
{
    /**
     * @return list<string>
     */
    public function manageableMonitoringIdsFor(User $user): array
    {
        return Monitoring::query()
            ->manageableBy($user)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Monitoring>
     */
    public function paginateWindowsFor(
        User $user,
        string $search,
        string $status,
        string $groupId,
        string $sort,
        string $direction,
        int $perPage,
    ): LengthAwarePaginator {
        $windows = Monitoring::query()
            ->visibleTo($user)
            ->select(['id', 'name', 'target', 'maintenance_from', 'maintenance_until'])
            ->with('groups:id,name');

        if ($search !== '') {
            $windows->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('target', 'like', '%' . $search . '%')
                    ->orWhereHas('groups', fn (Builder $builder): Builder => $builder->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($groupId !== '') {
            $windows->whereHas('groups', fn (Builder $builder): Builder => $builder->whereKey($groupId));
        }

        $this->applyStatusFilter($windows, $status);
        $this->applySort($windows, $sort, $direction);

        return $windows->paginate(min(max($perPage, 1), 100));
    }

    /**
     * @return Collection<int, Monitoring>
     */
    public function visibleMaintenanceStatesFor(User $user): Collection
    {
        return Monitoring::query()
            ->visibleTo($user)
            ->get(['id', 'maintenance_from', 'maintenance_until']);
    }

    /**
     * @return Collection<int, MaintenanceWindow>
     */
    public function recurringWindowsFor(User $user): Collection
    {
        return MaintenanceWindow::query()
            ->visibleTo($user)
            ->with([
                'monitoring:id,name',
                'monitoringGroup:id,name',
            ])
            ->latest('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, Monitoring>
     */
    public function manageableMonitoringOptionsFor(User $user): Collection
    {
        return Monitoring::query()
            ->manageableBy($user)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @param  Builder<Monitoring>  $builder
     */
    private function applyStatusFilter(Builder $builder, string $status): void
    {
        if ($status === '') {
            return;
        }

        $now = Date::now();

        match ($status) {
            'active' => $builder
                ->whereNotNull('maintenance_from')
                ->where('maintenance_from', '<=', $now)
                ->where(function (Builder $builder) use ($now): void {
                    $builder->whereNull('maintenance_until')
                        ->orWhere('maintenance_until', '>=', $now);
                }),
            'upcoming' => $builder
                ->whereNotNull('maintenance_from')
                ->where('maintenance_from', '>', $now),
            'expired' => $builder
                ->whereNotNull('maintenance_from')
                ->whereNotNull('maintenance_until')
                ->where('maintenance_until', '<', $now),
            'none' => $builder->whereNull('maintenance_from'),
            default => null,
        };
    }

    /**
     * @param  Builder<Monitoring>  $builder
     */
    private function applySort(Builder $builder, string $sort, string $direction): void
    {
        if ($sort === 'maintenance_status') {
            $now = Date::now();
            $builder
                ->orderByRaw(
                    "case
                        when maintenance_from is not null and maintenance_from <= ? and (maintenance_until is null or maintenance_until >= ?) then 0
                        when maintenance_from is not null and maintenance_from > ? then 1
                        when maintenance_from is not null then 2
                        else 3
                    end {$direction}",
                    [$now, $now, $now]
                )
                ->orderBy('name');

            return;
        }

        $builder->orderBy(in_array($sort, ['name', 'maintenance_from', 'maintenance_until'], true) ? $sort : 'name', $direction)
            ->orderBy('name')
            ->orderBy('id');
    }
}
