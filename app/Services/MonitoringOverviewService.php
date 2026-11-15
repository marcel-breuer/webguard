<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\MonitoringServiceReadModel;
use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\StatusPageComponentSource;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringResponse;
use App\Models\NotificationChannelDelivery;
use App\Models\StatusPage;
use App\Models\User;
use App\Queries\MonitoringOverviewQuery;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class MonitoringOverviewService
{
    public function __construct(
        private readonly MonitoringOverviewQuery $monitoringOverviewQuery,
        private readonly MonitoringStateResolver $monitoringStateResolver,
        private readonly MonitoringHealthEvaluator $monitoringHealthEvaluator,
    ) {}

    /**
     * @return array{
     *     monitorings: EloquentCollection<int, Monitoring>,
     *     serviceReadModels: Collection<int, MonitoringServiceReadModel>,
     *     signalRoomPagination: array{current_page:int,last_page:int,total:int,from:int|null,to:int|null},
     *     summary: array{total:int,healthy:int,down:int,unknown:int,paused:int,maintenance:int},
     *     overallState: string,
     *     attentionItems: Collection<int, array{type:string,monitoring:Monitoring|null,count:int|null}>,
     *     recentIncidents: EloquentCollection<int, Incident>,
     *     maintenanceMonitorings: Collection<int, Monitoring>,
     *     statusPages: EloquentCollection<int, StatusPage>,
     *     trend: list<array{date:string,label:string,uptime_percentage:float|null,has_data:bool}>,
     *     failedDeliveryCount: int,
     *     recommendedAction: string,
     *     canCreateMonitoring: bool,
     *     canManageMaintenance: bool
     * }
     */
    public function overview(User $user, int $servicePage = 1): array
    {
        $user->loadMissing('package');

        $monitorings = $this->monitoringOverviewQuery->monitoringsFor($user);

        $statuses = $monitorings->mapWithKeys(
            fn (Monitoring $monitoring): array => [$monitoring->id => $this->monitoringStateResolver->status($monitoring)]
        );
        $summary = [
            'total' => $monitorings->count(),
            'healthy' => $statuses->filter(fn (string $status): bool => $status === MonitoringStatus::UP->value)->count(),
            'down' => $statuses->filter(fn (string $status): bool => $status === MonitoringStatus::DOWN->value)->count(),
            'unknown' => $statuses->filter(fn (string $status): bool => $status === MonitoringStatus::UNKNOWN->value)->count(),
            'paused' => $statuses->filter(fn (string $status): bool => $status === MonitoringLifecycleStatus::PAUSED->value)->count(),
            'maintenance' => $statuses->filter(fn (string $status): bool => $status === 'maintenance')->count(),
        ];

        $failedDeliveryCount = NotificationChannelDelivery::query()
            ->where('user_id', $user->id)
            ->where('status', NotificationDeliveryStatus::FAILED)
            ->where('created_at', '>=', Date::now()->subDays(7))
            ->count();

        $maintenanceMonitorings = $monitorings
            ->filter(fn (Monitoring $monitoring): bool => $monitoring->maintenance_from !== null
                && ($monitoring->isUnderMaintenance() || $monitoring->maintenance_from->isFuture()))
            ->sortBy('maintenance_from')
            ->values();

        $attentionItems = $this->attentionItems(
            $monitorings,
            $failedDeliveryCount,
            $this->statusPagesByMonitoringId($user)
        );
        $overallState = $this->overallState($summary);
        $serviceReadModels = $monitorings->map(
            fn (Monitoring $monitoring): MonitoringServiceReadModel => MonitoringServiceReadModel::fromMonitoring(
                $monitoring,
                (string) $statuses->get($monitoring->getKey(), MonitoringStatus::UNKNOWN->value),
            )
        )->values();
        $pagedServiceReadModels = $serviceReadModels->forPage(max(1, $servicePage), 10)->values();
        $serviceCount = $serviceReadModels->count();
        $lastPage = max(1, (int) ceil($serviceCount / 10));
        $firstItem = $pagedServiceReadModels->isEmpty() ? null : (($servicePage - 1) * 10) + 1;
        $lastItem = $pagedServiceReadModels->isEmpty() ? null : min($servicePage * 10, $serviceCount);

        return [
            'monitorings' => $monitorings,
            'serviceReadModels' => $pagedServiceReadModels,
            'signalRoomPagination' => [
                'current_page' => max(1, $servicePage),
                'last_page' => $lastPage,
                'total' => $serviceCount,
                'from' => $firstItem,
                'to' => $lastItem,
            ],
            'summary' => $summary,
            'overallState' => $overallState,
            'attentionItems' => $attentionItems,
            'recentIncidents' => Incident::query()
                ->with('monitoring')
                ->whereHas('monitoring', fn ($query) => $query->visibleTo($user))
                ->latest('down_at')
                ->limit(5)
                ->get(),
            'maintenanceMonitorings' => $maintenanceMonitorings,
            'statusPages' => $user->statusPages()
                ->withCount('components')
                ->latest()
                ->limit(4)
                ->get(),
            'trend' => $this->trend($monitorings),
            'failedDeliveryCount' => $failedDeliveryCount,
            'recommendedAction' => $this->recommendedAction($summary, $failedDeliveryCount, $maintenanceMonitorings->isNotEmpty()),
            'canCreateMonitoring' => ! $user->isDemo()
                && (($user->monitorings()->whereNull('team_id')->count() < (int) ($user->package?->monitoring_limit ?? 0))
                    || $user->administeredTeams()->exists()),
            'canManageMaintenance' => ! $user->isDemo()
                && Monitoring::query()->manageableBy($user)->exists(),
        ];
    }

    /**
     * @param  array{total:int,healthy:int,down:int,unknown:int,paused:int,maintenance:int}  $summary
     */
    private function overallState(array $summary): string
    {
        if ($summary['total'] === 0) {
            return 'new';
        }

        if ($summary['down'] > 0) {
            return 'degraded';
        }

        if ($summary['unknown'] > 0) {
            return 'attention';
        }

        return 'healthy';
    }

    /**
     * @param  array{total:int,healthy:int,down:int,unknown:int,paused:int,maintenance:int}  $summary
     */
    private function recommendedAction(array $summary, int $failedDeliveryCount, bool $hasMaintenance): string
    {
        if ($summary['total'] === 0) {
            return 'create';
        }

        if ($summary['down'] > 0) {
            return 'incidents';
        }

        if ($summary['unknown'] > 0) {
            return 'unknown';
        }

        if ($failedDeliveryCount > 0) {
            return 'notifications';
        }

        if ($hasMaintenance) {
            return 'maintenance';
        }

        return 'monitorings';
    }

    /**
     * @param  EloquentCollection<int, Monitoring>  $eloquentCollection
     * @param  Collection<string, StatusPage>  $statusPagesByMonitoringId
     * @return Collection<int, array{type:string,monitoring:Monitoring|null,count:int|null,statusPage:StatusPage|null}>
     */
    private function attentionItems(
        EloquentCollection $eloquentCollection,
        int $failedDeliveryCount,
        Collection $statusPagesByMonitoringId
    ): Collection {
        $items = collect();

        $eloquentCollection
            ->filter(fn (Monitoring $monitoring): bool => $this->monitoringStateResolver->status($monitoring) === MonitoringStatus::DOWN->value)
            ->take(5)
            ->each(function (Monitoring $monitoring) use ($items, $statusPagesByMonitoringId): void {
                $isOpenIncident = $monitoring->latestIncident?->up_at === null
                    && $monitoring->latestIncident !== null;

                $items->push([
                    'type' => $isOpenIncident ? 'incident' : 'down',
                    'monitoring' => $monitoring,
                    'count' => null,
                    'statusPage' => $isOpenIncident ? $statusPagesByMonitoringId->get($monitoring->id) : null,
                ]);
            });

        $eloquentCollection
            ->filter(fn (Monitoring $monitoring): bool => $this->monitoringStateResolver->status($monitoring) === MonitoringStatus::UNKNOWN->value
                && $monitoring->isActive())
            ->take(5)
            ->each(fn (Monitoring $monitoring) => $items->push([
                'type' => $monitoring->latestResponseResult === null ? 'unknown' : 'stale',
                'monitoring' => $monitoring,
                'count' => null,
                'statusPage' => null,
            ]));

        if ($failedDeliveryCount > 0) {
            $items->push([
                'type' => 'delivery',
                'monitoring' => null,
                'count' => $failedDeliveryCount,
                'statusPage' => null,
            ]);
        }

        return $items;
    }

    /**
     * @return Collection<string, StatusPage>
     */
    private function statusPagesByMonitoringId(User $user): Collection
    {
        $statusPages = $user->statusPages()->with([
            'components.monitorings',
            'components.monitoringGroup.monitorings',
        ])->get();
        $statusPagesByMonitoringId = collect();

        foreach ($statusPages as $statusPage) {
            foreach ($statusPage->components as $component) {
                $monitorings = $component->source_type === StatusPageComponentSource::MONITORING_GROUP
                    ? $component->monitoringGroup?->monitorings ?? new EloquentCollection()
                    : $component->monitorings;

                foreach ($monitorings as $monitoring) {
                    $statusPagesByMonitoringId->put($monitoring->id, $statusPage);
                }
            }
        }

        return $statusPagesByMonitoringId;
    }

    /**
     * @param  EloquentCollection<int, Monitoring>  $eloquentCollection
     * @return list<array{date:string,label:string,uptime_percentage:float|null,has_data:bool}>
     */
    private function trend(EloquentCollection $eloquentCollection): array
    {
        $dates = collect(range(6, 0))->map(fn (int $days): Carbon => Date::now()->subDays($days)->startOfDay());
        $dailyResults = $eloquentCollection->isEmpty()
            ? collect()
            : MonitoringDailyResult::query()
                ->whereIn('monitoring_id', $eloquentCollection->modelKeys())
                ->whereBetween('date', [$dates->first()->toDateString(), $dates->last()->toDateString()])
                ->get(['date', 'uptime_minutes', 'downtime_minutes', 'unknown_minutes'])
                ->groupBy(fn (MonitoringDailyResult $monitoringDailyResult): string => $monitoringDailyResult->date->toDateString());
        $today = Date::today();
        $todayHasAggregate = $eloquentCollection->isNotEmpty()
            && MonitoringDailyResult::query()
                ->whereIn('monitoring_id', $eloquentCollection->modelKeys())
                ->whereDate('date', $today->toDateString())
                ->whereRaw('(uptime_minutes + downtime_minutes + unknown_minutes) > 0')
                ->exists();
        $liveToday = $todayHasAggregate
            ? ['uptime_minutes' => 0, 'downtime_minutes' => 0, 'unknown_minutes' => 0]
            : $this->liveTodayTrend($eloquentCollection, $today, Date::now());

        return $dates->map(function (Carbon $date) use ($dailyResults, $liveToday, $today): array {
            $rows = $dailyResults->get($date->toDateString(), collect());
            $uptimeMinutes = (int) $rows->sum('uptime_minutes');
            $downtimeMinutes = (int) $rows->sum('downtime_minutes');
            $unknownMinutes = (int) $rows->sum('unknown_minutes');

            if ($date->isSameDay($today) && ($uptimeMinutes + $downtimeMinutes + $unknownMinutes) === 0) {
                $uptimeMinutes = $liveToday['uptime_minutes'];
                $downtimeMinutes = $liveToday['downtime_minutes'];
                $unknownMinutes = $liveToday['unknown_minutes'];
            }

            $trackedMinutes = $uptimeMinutes + $downtimeMinutes + $unknownMinutes;

            return [
                'date' => $date->toDateString(),
                'label' => $date->locale(app()->getLocale())->isoFormat('ddd'),
                'uptime_percentage' => $trackedMinutes > 0 ? round(($uptimeMinutes / $trackedMinutes) * 100, 2) : null,
                'has_data' => $trackedMinutes > 0,
            ];
        })->all();
    }

    /**
     * @param  EloquentCollection<int, Monitoring>  $eloquentCollection
     * @return array{uptime_minutes:int,downtime_minutes:int,unknown_minutes:int}
     */
    private function liveTodayTrend(EloquentCollection $eloquentCollection, Carbon $today, Carbon $now): array
    {
        $totals = [
            'uptime_minutes' => 0,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
        ];

        if ($eloquentCollection->isEmpty() || $now->lte($today)) {
            return $totals;
        }

        $monitoringsById = $eloquentCollection->keyBy('id');
        $responses = MonitoringResponse::query()
            ->whereIn('monitoring_id', $eloquentCollection->modelKeys())
            ->whereBetween('created_at', [$today, $now])
            ->orderBy('monitoring_id')
            ->oldest('created_at')
            ->orderBy('id')
            ->select([
                'id',
                'monitoring_id',
                'status',
                'http_status_code',
                'server_health_metrics',
                'vital_values',
                'created_at',
            ])
            ->cursor();

        $currentMonitoringId = null;
        $cursor = null;
        $status = null;

        foreach ($responses as $response) {
            if ($currentMonitoringId !== $response->monitoring_id) {
                if ($cursor instanceof Carbon && $cursor->lt($now)) {
                    $this->addTrendMinutes($status, (int) $cursor->diffInMinutes($now), $totals);
                }

                $currentMonitoringId = $response->monitoring_id;
                $cursor = null;
                $status = null;
            }

            $monitoring = $monitoringsById->get($currentMonitoringId);
            if (! $monitoring instanceof Monitoring) {
                continue;
            }

            $responseAt = Date::parse($response->created_at);

            if ($cursor instanceof Carbon && $responseAt->gt($cursor)) {
                $this->addTrendMinutes(
                    $status,
                    (int) $cursor->diffInMinutes($responseAt),
                    $totals,
                );
            }

            $cursor = $responseAt;
            $status = $this->monitoringHealthEvaluator->availabilityFor($monitoring, $response)->value;
        }

        if ($cursor instanceof Carbon && $cursor->lt($now)) {
            $this->addTrendMinutes($status, (int) $cursor->diffInMinutes($now), $totals);
        }

        return $totals;
    }

    /**
     * @param  array{uptime_minutes:int,downtime_minutes:int,unknown_minutes:int}  $totals
     */
    private function addTrendMinutes(?string $status, int $minutes, array &$totals): void
    {
        if ($minutes <= 0) {
            return;
        }

        match ($status) {
            MonitoringStatus::UP->value => $totals['uptime_minutes'] += $minutes,
            MonitoringStatus::DOWN->value => $totals['downtime_minutes'] += $minutes,
            default => $totals['unknown_minutes'] += $minutes,
        };
    }
}
