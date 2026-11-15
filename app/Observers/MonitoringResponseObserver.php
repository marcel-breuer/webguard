<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\MonitoringStatus;
use App\Models\Incident;
use App\Models\MonitoringResponse;
use App\Services\MonitoringHealthEvaluator;
use App\Services\MonitoringPerformanceService;
use App\Services\MonitoringStatsCache;
use App\Services\OperationsOverviewCache;
use App\Services\RegionalConsensusService;

class MonitoringResponseObserver
{
    /**
     * Handle the MonitoringResponse "created" event.
     */
    public function created(MonitoringResponse $monitoringResponse): void
    {
        $monitoring = $monitoringResponse->monitoring;
        resolve(OperationsOverviewCache::class)->flushForMonitoring($monitoring);
        resolve(MonitoringStatsCache::class)->flush($monitoring);
        $monitoringHealthEvaluator = resolve(MonitoringHealthEvaluator::class);
        $monitoringStatus = $monitoringHealthEvaluator->availabilityFor($monitoring, $monitoringResponse);

        resolve(MonitoringPerformanceService::class)->reconcile($monitoring, $monitoringResponse, $monitoringStatus);

        if (count($monitoring->preferredLocationCodes()) > 1 && $monitoringResponse->location_code !== null) {
            resolve(RegionalConsensusService::class)->reconcile($monitoring);

            return;
        }

        $threshold = max(1, (int) ($monitoring->failure_confirmation_threshold ?? 1));
        $responses = $monitoring->responseResults()->latest()
            ->orderByDesc('id')
            ->take(max(2, $threshold))
            ->get();

        $latestResponse = $responses->first();

        if ($latestResponse && $monitoringHealthEvaluator->availabilityFor($monitoring, $latestResponse) === MonitoringStatus::DOWN) {
            if ($this->hasConfirmedFailure($monitoringResponse)) {
                $this->openIncident($monitoringResponse);
            }

            return;
        }

        if ($latestResponse && $monitoringHealthEvaluator->availabilityFor($monitoring, $latestResponse) === MonitoringStatus::UP) {
            // Status changed to UP, close the open incident
            $incident = $monitoring->incidents()->whereNull('up_at')->first();
            if ($incident) {
                $incident->update(['up_at' => now()]);
            }
        }
    }

    private function hasConfirmedFailure(MonitoringResponse $monitoringResponse): bool
    {
        $monitoring = $monitoringResponse->monitoring;
        $threshold = max(1, (int) ($monitoring->failure_confirmation_threshold ?? 1));

        $responses = $monitoring->responseResults()->latest()
            ->orderByDesc('id')
            ->take($threshold)
            ->get();

        return $responses->count() >= $threshold
            && $responses->every(fn (MonitoringResponse $monitoringResponse): bool => resolve(MonitoringHealthEvaluator::class)->availabilityFor($monitoring, $monitoringResponse) === MonitoringStatus::DOWN);
    }

    private function openIncident(MonitoringResponse $monitoringResponse): void
    {
        Incident::query()->firstOrCreate(
            ['monitoring_id' => $monitoringResponse->monitoring_id, 'up_at' => null],
            ['down_at' => now()]
        );
    }
}
