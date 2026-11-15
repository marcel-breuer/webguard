<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\MonitoringLifecycleStatus;
use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class MonitoringOverviewQuery
{
    /**
     * @return EloquentCollection<int, Monitoring>
     */
    public function monitoringsFor(User $user): EloquentCollection
    {
        return $this->query($user)->get($this->columns());
    }

    /**
     * @return LengthAwarePaginator<int, Monitoring>
     */
    public function paginateFor(
        User $user,
        int $page,
        int $perPage = 20,
        ?string $search = null,
        ?MonitoringLifecycleStatus $monitoringLifecycleStatus = null,
    ): LengthAwarePaginator {
        $query = $this->query($user);

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('target', 'like', '%' . $search . '%');
            });
        }

        if ($monitoringLifecycleStatus !== null) {
            $query->where('status', $monitoringLifecycleStatus);
        }

        return $this->paginate($query, $page, $perPage, 'page');
    }

    /**
     * @param  Builder<Monitoring>  $builder
     * @return LengthAwarePaginator<int, Monitoring>
     */
    private function paginate(Builder $builder, int $page, int $perPage, string $pageName): LengthAwarePaginator
    {
        return $builder->paginate(
            $perPage,
            $this->columns(),
            $pageName,
            max(1, $page),
        );
    }

    /**
     * @return Builder<Monitoring>
     */
    private function query(User $user): Builder
    {
        return Monitoring::query()
            ->select($this->columns())
            ->withMaintenanceWindowState()
            ->visibleTo($user)
            ->with([
                'latestResponseResult' => fn ($query) => $query->select([
                    'monitoring_response_results.id',
                    'monitoring_response_results.monitoring_id',
                    'monitoring_response_results.status',
                    'monitoring_response_results.http_status_code',
                    'monitoring_response_results.server_health_metrics',
                    'monitoring_response_results.vital_values',
                    'monitoring_response_results.response_time',
                    'monitoring_response_results.created_at',
                    'monitoring_response_results.updated_at',
                ]),
                'latestIncident' => fn ($query) => $query->select([
                    'incidents.id',
                    'incidents.monitoring_id',
                    'incidents.down_at',
                    'incidents.up_at',
                ]),
                'groups:id,name',
            ])
            ->orderBy('name');
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'id',
            'user_id',
            'team_id',
            'name',
            'target',
            'type',
            'keyword',
            'response_time_degradation_percent',
            'status',
            'expected_http_statuses',
            'dns_expected_values',
            'server_health_cpu_threshold_percent',
            'server_health_ram_threshold_percent',
            'server_health_storage_threshold_percent',
            'server_health_load_threshold_per_cpu',
            'maintenance_from',
            'maintenance_until',
            'check_interval_minutes',
            'heartbeat_interval_minutes',
            'heartbeat_grace_minutes',
        ];
    }
}
