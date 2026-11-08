<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\MonitoringAvailabilityPayload;
use App\Models\Monitoring;
use App\Services\Availability\AggregatedAvailabilityCalculator;
use App\Services\Availability\AvailabilityIncidentCounter;
use App\Services\Availability\AvailabilityPayloadFactory;
use App\Services\Availability\RawAvailabilityCalculator;
use App\Services\Availability\RollingRangeAvailabilityCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Entry point for uptime and downtime figures; delegates to the calculators in `App\Services\Availability`.
 */
class MonitoringAvailabilityService
{
    public function __construct(
        private readonly RawAvailabilityCalculator $rawAvailabilityCalculator,
        private readonly AggregatedAvailabilityCalculator $aggregatedAvailabilityCalculator,
        private readonly RollingRangeAvailabilityCalculator $rollingRangeAvailabilityCalculator,
        private readonly AvailabilityIncidentCounter $availabilityIncidentCounter,
        private readonly AvailabilityPayloadFactory $availabilityPayloadFactory,
    ) {}

    public function getUptimeDowntime(
        Monitoring $monitoring,
        Carbon $startDate,
        Carbon $endDate,
        bool $loadAggregatedData = false,
        bool $includeIntradayRawData = true,
        ?Carbon $trackingStartedAt = null
    ): MonitoringAvailabilityPayload {
        $startDate = $startDate->copy();
        $endDate = $endDate->copy();

        if ($endDate->isFuture()) {
            $endDate = Date::now();
        }

        if ($startDate->gt($endDate)) {
            return $this->availabilityPayloadFactory->empty($startDate, $endDate, null);
        }

        if ($loadAggregatedData) {
            return $this->aggregatedAvailabilityCalculator->calculate($monitoring, $startDate, $endDate, $includeIntradayRawData);
        }

        return $this->rawAvailabilityCalculator->calculate($monitoring, $startDate, $endDate, trackingStartedAt: $trackingStartedAt);
    }

    /**
     * @param  array<int, int>  $days
     * @return array<string, MonitoringAvailabilityPayload>
     */
    public function getUptimeDowntimesForRanges(
        Monitoring $monitoring,
        array $days,
        bool $includeIntradayRawData = true
    ): array {
        $normalizedDays = collect($days)
            ->map(static fn (mixed $day): ?int => is_numeric($day) ? $day : null)
            ->filter(static fn (?int $day): bool => $day !== null && $day > 0)
            ->unique()
            ->sort()
            ->values();

        if ($normalizedDays->isEmpty()) {
            return [];
        }

        $now = Date::now();
        $endDate = $now->copy()->endOfDay();
        $requiresRawFallback = $normalizedDays->contains(static fn (int $day): bool => $day <= 1)
            || $monitoring->created_at->diffInDays($now) < 1;

        if ($requiresRawFallback) {
            return $normalizedDays
                ->mapWithKeys(function (int $day) use ($monitoring, $now, $endDate): array {
                    $startDate = $now->copy()->subDays($day)->startOfDay();
                    $loadAggregatedData = $day > 1;
                    $includeIntradayRawData = $day <= 1;

                    if ($monitoring->created_at->diffInDays($now) < 1) {
                        $loadAggregatedData = false;
                    }

                    return [
                        (string) $day => $this->getUptimeDowntime(
                            $monitoring,
                            $startDate,
                            $endDate,
                            $loadAggregatedData,
                            $includeIntradayRawData
                        ),
                    ];
                })
                ->all();
        }

        return $this->rollingRangeAvailabilityCalculator->calculate($monitoring, $normalizedDays, $now, $includeIntradayRawData);
    }

    public function countIncidents(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): int
    {
        return $this->availabilityIncidentCounter->countStartedBetween($monitoring, $startDate, $endDate);
    }

    public function getAggregatedIncidentsCount(
        Monitoring $monitoring,
        Carbon $startDate,
        Carbon $endDate,
        bool $includeIntradayRawData = true
    ): int {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();

        if ($endDate->isFuture()) {
            $endDate = Date::now();
        }

        if ($startDate->gt($endDate)) {
            return 0;
        }

        if (! $includeIntradayRawData) {
            $endDate = $endDate->min(Date::today()->subDay()->endOfDay());
        }

        return $this->availabilityIncidentCounter->countOverlapping($monitoring, $startDate, $endDate);
    }
}
