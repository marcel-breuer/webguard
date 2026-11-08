<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Models\Incident;
use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Counts incidents of a monitoring that overlap a date range.
 */
class AvailabilityIncidentCounter
{
    public function countStartedBetween(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): int
    {
        return (int) $monitoring->incidents()
            ->whereBetween('down_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->count();
    }

    public function countOverlapping(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): int
    {
        return (int) $monitoring->incidents()
            ->where('down_at', '<=', $endDate)
            ->where(function (Builder $builder) use ($startDate) {
                $builder->where('up_at', '>=', $startDate)
                    ->orWhereNull('up_at');
            })
            ->count();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function overlapping(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): Collection
    {
        return $monitoring->incidents()
            ->where('down_at', '<=', $endDate)
            ->where(function (Builder $builder) use ($startDate): void {
                $builder->where('up_at', '>=', $startDate)
                    ->orWhereNull('up_at');
            })
            ->get(['down_at', 'up_at']);
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    public function countOverlappingIn(Collection $incidents, Carbon $startDate, Carbon $endDate): int
    {
        return $incidents
            ->filter(function (Incident $incident) use ($startDate, $endDate): bool {
                return $incident->down_at->lte($endDate)
                    && ($incident->up_at === null || $incident->up_at->gte($startDate));
            })
            ->count();
    }
}
