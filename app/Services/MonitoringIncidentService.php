<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\MonitoringIncidentPayload;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Queries\MonitoringIncidentQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class MonitoringIncidentService
{
    public function __construct(private readonly MonitoringIncidentQuery $monitoringIncidentQuery) {}

    /**
     * @return Collection<int, MonitoringIncidentPayload>
     */
    public function getIncidents(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->monitoringIncidentQuery
            ->for($monitoring, $startDate, $endDate)
            ->map(function ($incident): MonitoringIncidentPayload {
                $downAt = Date::parse($incident->down_at);
                $upAt = $incident->up_at ? Date::parse($incident->up_at) : null;

                return new MonitoringIncidentPayload(
                    downAt: $downAt->toIso8601String(),
                    upAt: $upAt?->toIso8601String()
                );
            });
    }

    /**
     * @return array{data: list<array{down_at: string, up_at: string|null}>, has_more: bool, next_offset: int|null}
     */
    public function getIncidentPage(
        Monitoring $monitoring,
        Carbon $startDate,
        Carbon $endDate,
        int $limit,
        int $offset,
    ): array {
        $incidents = $this->monitoringIncidentQuery->page($monitoring, $startDate, $endDate, $limit, $offset);
        $hasMore = $incidents->count() > $limit;

        return [
            'data' => $incidents
                ->take($limit)
                ->map(fn (Incident $incident): array => $this->incidentPayload($incident))
                ->values()
                ->all(),
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $limit : null,
        ];
    }

    /**
     * @return array{down_at: string, up_at: string|null, consensus_snapshot: array<string, mixed>|null}
     */
    private function incidentPayload(Incident $incident): array
    {
        $downAt = Date::parse($incident->down_at);
        $upAt = $incident->up_at ? Date::parse($incident->up_at) : null;

        return [
            'down_at' => $downAt->toIso8601String(),
            'up_at' => $upAt?->toIso8601String(),
            'consensus_snapshot' => $incident->consensus_snapshot,
        ];
    }
}
