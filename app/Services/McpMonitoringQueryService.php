<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Monitoring;
use App\Models\User;
use App\Queries\MonitoringDataQuery;
use App\Support\MonitoringDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class McpMonitoringQueryService
{
    public function __construct(
        private readonly MonitoringDataQuery $monitoringDataQuery,
        private readonly MonitoringDashboardPayloadService $monitoringDashboardPayloadService,
    ) {}

    /**
     * @return array{monitorings: list<array{id: string, name: string, type: string, status: string}>, count: int}
     */
    public function listAccessible(User $user, int $limit): array
    {
        $monitorings = Monitoring::query()
            ->where(function (Builder $builder) use ($user): void {
                $builder->visibleTo($user)->orWhere('public_label_enabled', true);
            })
            ->select(['id', 'name', 'type', 'status'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (Monitoring $monitoring): array => [
                'id' => (string) $monitoring->getKey(),
                'name' => (string) $monitoring->name,
                'type' => $monitoring->type->value,
                'status' => $monitoring->status->value,
            ])
            ->all();

        return [
            'monitorings' => $monitorings,
            'count' => count($monitorings),
        ];
    }

    /**
     * @return array{monitoring: array{id: string, name: string}, days: int, analytics: array<string, mixed>}
     */
    public function analytics(User $user, string $monitoringId, int $days): array
    {
        try {
            $monitoring = $this->monitoringDataQuery->findAccessible($user, $monitoringId);
        } catch (ModelNotFoundException) {
            abort(404, 'Monitoring not found or not accessible.');
        }

        $monitoringDateRange = MonitoringDateRange::pastDays($days);
        $monitoringDashboardPayload = $this->monitoringDashboardPayloadService->getPayload(
            $monitoring,
            $days,
            $monitoringDateRange->startDate,
            $monitoringDateRange->endDate
        );

        return [
            'monitoring' => [
                'id' => (string) $monitoring->getKey(),
                'name' => (string) $monitoring->name,
            ],
            'days' => $days,
            'analytics' => json_decode(json_encode($monitoringDashboardPayload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
