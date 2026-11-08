<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use stdClass;

final class MonitoringCheckHistoryQuery
{
    private const LIVE_TABLE = 'monitoring_response_results';

    private const ARCHIVED_TABLE = 'monitoring_response_archived';

    /**
     * @return Collection<int, stdClass>
     */
    public function for(string $monitoringId, ?Carbon $startDate, Carbon $endDate, int $offset, int $limit): Collection
    {
        $archiveCutoffDate = Date::now()->subWeek()->startOfDay();

        if ($startDate !== null && $startDate->gte($archiveCutoffDate)) {
            return $this->sourceQuery(self::LIVE_TABLE, 'live', $monitoringId, $startDate, $endDate)
                ->latest()
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get();
        }

        if ($startDate === null) {
            $liveRows = $this->sourceQuery(self::LIVE_TABLE, 'live', $monitoringId, null, null)
                ->latest()
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get();
            $oldestLiveCheckedAt = $liveRows->last()?->created_at;

            if ($liveRows->count() === $limit && $oldestLiveCheckedAt !== null && Date::parse((string) $oldestLiveCheckedAt)->gte($archiveCutoffDate)) {
                return $liveRows;
            }
        }

        $builder = $this->sourceQuery(self::LIVE_TABLE, 'live', $monitoringId, $startDate, $startDate !== null ? $endDate : null);
        $archived = $this->sourceQuery(self::ARCHIVED_TABLE, 'archived', $monitoringId, $startDate, $startDate !== null ? $endDate : null);

        return DB::query()
            ->fromSub($builder->unionAll($archived), 'monitoring_results')
            ->latest()
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function forRange(string $monitoringId, Carbon $startDate, Carbon $endDate): Collection
    {
        $builder = $this->sourceQuery(self::LIVE_TABLE, 'live', $monitoringId, $startDate, $endDate);
        $archived = $this->sourceQuery(self::ARCHIVED_TABLE, 'archived', $monitoringId, $startDate, $endDate);

        return DB::query()
            ->fromSub($builder->unionAll($archived), 'monitoring_results')->oldest()
            ->get();
    }

    private function sourceQuery(
        string $table,
        string $source,
        string $monitoringId,
        ?Carbon $startDate,
        ?Carbon $endDate,
    ): Builder {
        $builder = DB::table($table)
            ->selectRaw("'{$source}' as source, id, status, http_status_code, response_time, server_health_metrics, vital_values, created_at")
            ->where('monitoring_id', $monitoringId);

        if ($startDate !== null && $endDate !== null) {
            $builder->whereBetween('created_at', [$startDate, $endDate]);
        }

        return $builder;
    }
}
