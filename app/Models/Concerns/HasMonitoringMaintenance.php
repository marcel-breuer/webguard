<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Services\MaintenanceWindowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Maintenance state of a monitoring, from one-off maintenance fields and recurring windows.
 *
 * @mixin Monitoring
 */
trait HasMonitoringMaintenance
{
    /**
     * Determine if the monitoring is currently under maintenance.
     */
    public function isUnderMaintenance(): bool
    {
        if ($this->maintenance_from && is_null($this->maintenance_until)) {
            if ($this->maintenance_from->isPast()) {
                return true;
            }

            return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
        }

        if ($this->maintenance_from && $this->maintenance_until) {
            if (now()->between($this->maintenance_from, $this->maintenance_until)) {
                return true;
            }

            return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
        }

        return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
    }

    /**
     * @return array{starts_at: CarbonInterface, ends_at: CarbonInterface|null, active: bool, recurring: bool}|null
     */
    public function currentOrUpcomingMaintenanceWindow(): ?array
    {
        if ($this->maintenance_from && (! $this->maintenance_until || $this->maintenance_until->isFuture())) {
            return [
                'starts_at' => $this->maintenance_from,
                'ends_at' => $this->maintenance_until,
                'active' => $this->isUnderMaintenanceWithoutRecurringWindow(),
                'recurring' => false,
            ];
        }

        return resolve(MaintenanceWindowService::class)->currentOrUpcoming($this);
    }

    public function hasUpcomingMaintenance(): bool
    {
        $window = $this->currentOrUpcomingMaintenanceWindow();

        return $window !== null && ! $window['active'];
    }

    /**
     * Add the recurring maintenance presence flag used by high-throughput payloads.
     *
     * @param  Builder<Monitoring>  $builder
     * @return Builder<Monitoring>
     */
    #[Scope]
    protected function withMaintenanceWindowState(Builder $builder): Builder
    {
        $monitoringTable = $builder->getModel()->getTable();

        $maintenanceWindowQuery = MaintenanceWindow::query()
            ->selectRaw('1')
            ->where('enabled', true)
            ->where(function (Builder $builder) use ($monitoringTable): void {
                $builder
                    ->whereColumn('maintenance_windows.monitoring_id', $monitoringTable . '.id')
                    ->orWhereExists(function (\Illuminate\Database\Query\Builder $builder) use ($monitoringTable): void {
                        $builder
                            ->selectRaw('1')
                            ->from('monitoring_group_monitoring')
                            ->whereColumn('monitoring_group_monitoring.monitoring_id', $monitoringTable . '.id')
                            ->whereColumn(
                                'monitoring_group_monitoring.monitoring_group_id',
                                'maintenance_windows.monitoring_group_id'
                            );
                    });
            });

        return $builder->addSelect([
            'has_enabled_maintenance_windows' => $maintenanceWindowQuery,
        ]);
    }

    private function isUnderMaintenanceWithoutRecurringWindow(): bool
    {
        if (! $this->maintenance_from) {
            return false;
        }

        return $this->maintenance_until === null
            ? $this->maintenance_from->isPast()
            : now()->between($this->maintenance_from, $this->maintenance_until);
    }
}
