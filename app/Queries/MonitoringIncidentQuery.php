<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Incident;
use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

final class MonitoringIncidentQuery
{
    /**
     * @return Collection<int, Incident>
     */
    public function for(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): Collection
    {
        return $monitoring->incidents()
            ->whereBetween('down_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->select('down_at', 'up_at')
            ->latest('down_at')
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function page(Monitoring $monitoring, Carbon $startDate, Carbon $endDate, int $limit, int $offset): Collection
    {
        return $monitoring->incidents()
            ->whereBetween('down_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->select(['id', 'down_at', 'up_at', 'consensus_snapshot'])
            ->latest('down_at')
            ->latest('id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();
    }
}
