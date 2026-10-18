<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\StatusPage;
use App\Models\StatusPageMaintenanceDelivery;
use App\Models\StatusPageSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class PlannedMaintenanceNotificationService
{
    public function __construct(private readonly MaintenanceWindowService $maintenanceWindowService) {}

    /**
     * @param  Collection<int, Monitoring>  $monitorings
     */
    public function notifyForOneOff(Collection $monitorings, CarbonInterface $startsAt, ?CarbonInterface $endsAt): void
    {
        $this->notify(
            monitorings: $monitorings,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: (string) config('app.timezone'),
            recurring: false,
            source: 'one_off',
        );
    }

    public function notifyForRecurring(MaintenanceWindow $maintenanceWindow): void
    {
        $occurrence = $this->maintenanceWindowService->occurrence($maintenanceWindow);

        if ($occurrence === null) {
            return;
        }

        $maintenanceWindow->loadMissing([
            'monitoring:id,name,updated_at',
            'monitoringGroup.monitorings:id,name,updated_at',
        ]);
        $monitorings = $maintenanceWindow->monitoring === null
            ? $maintenanceWindow->monitoringGroup?->monitorings ?? collect()
            : collect([$maintenanceWindow->monitoring]);

        $this->notify(
            monitorings: $monitorings,
            startsAt: $occurrence['starts_at'],
            endsAt: $occurrence['ends_at'],
            timezone: $maintenanceWindow->timezone,
            recurring: true,
            source: 'recurring:' . $maintenanceWindow->id . ':' . $maintenanceWindow->updated_at?->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, Monitoring>  $monitorings
     */
    private function notify(
        Collection $monitorings,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        string $timezone,
        bool $recurring,
        string $source,
    ): void {
        $monitorings = $monitorings->unique('id')->sortBy('id')->values();

        if ($monitorings->isEmpty()) {
            return;
        }

        $monitoringIds = $monitorings->pluck('id')->all();

        StatusPage::query()
            ->where('is_public', true)
            ->whereHas('components', function ($query) use ($monitoringIds): void {
                $query
                    ->whereHas('monitorings', fn ($query) => $query->whereKey($monitoringIds))
                    ->orWhereHas('monitoringGroup.monitorings', fn ($query) => $query->whereKey($monitoringIds));
            })
            ->with([
                'components.monitorings:id,name',
                'components.monitoringGroup.monitorings:id,name',
                'subscriptions' => fn ($query) => $query->verified(),
            ])
            ->get()
            ->each(function (StatusPage $statusPage) use ($monitorings, $startsAt, $endsAt, $timezone, $recurring, $source): void {
                $affectedMonitoringIds = $statusPage->components
                    ->flatMap(function ($component): Collection {
                        return $component->monitorings
                            ->merge($component->monitoringGroup?->monitorings ?? collect())
                            ->pluck('id');
                    })
                    ->unique()
                    ->all();
                $affectedMonitorings = $monitorings
                    ->whereIn('id', $affectedMonitoringIds)
                    ->values();

                if ($affectedMonitorings->isEmpty()) {
                    return;
                }

                $fingerprint = $this->fingerprint($source, $affectedMonitorings, $startsAt, $endsAt, $timezone, $recurring);

                $statusPage->subscriptions->each(function (StatusPageSubscription $statusPageSubscription) use ($fingerprint, $affectedMonitorings, $startsAt, $endsAt, $timezone, $recurring): void {
                    StatusPageMaintenanceDelivery::query()->firstOrCreate([
                        'status_page_subscription_id' => $statusPageSubscription->id,
                        'fingerprint' => $fingerprint,
                    ], [
                        'payload' => [
                            'monitorings' => $affectedMonitorings->map(fn (Monitoring $monitoring): array => [
                                'id' => $monitoring->id,
                                'name' => $monitoring->name,
                            ])->all(),
                            'starts_at' => $startsAt->toIso8601String(),
                            'ends_at' => $endsAt?->toIso8601String(),
                            'timezone' => $timezone,
                            'recurring' => $recurring,
                        ],
                    ]);
                });
            });
    }

    /**
     * @param  Collection<int, Monitoring>  $monitorings
     */
    private function fingerprint(
        string $source,
        Collection $monitorings,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        string $timezone,
        bool $recurring,
    ): string {
        return hash('sha256', json_encode([
            'source' => $source,
            'monitoring_ids' => $monitorings->pluck('id')->all(),
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt?->toIso8601String(),
            'timezone' => $timezone,
            'recurring' => $recurring,
        ], JSON_THROW_ON_ERROR));
    }
}
