<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\MonitoringAvailabilityPayload;
use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Sums pre-aggregated daily results and adds raw data for the current day.
 */
class AggregatedAvailabilityCalculator
{
    public function __construct(
        private readonly RawAvailabilityCalculator $rawAvailabilityCalculator,
        private readonly AvailabilityPayloadFactory $availabilityPayloadFactory,
        private readonly AvailabilityIncidentCounter $availabilityIncidentCounter,
        private readonly AvailabilityTrackingStart $availabilityTrackingStart,
    ) {}

    public function calculate(
        Monitoring $monitoring,
        Carbon $startDate,
        Carbon $endDate,
        bool $includeIntradayRawData = true
    ): MonitoringAvailabilityPayload {
        $trackingStartedAt = $includeIntradayRawData
            ? $this->availabilityTrackingStart->fromResponses($monitoring)
            : $this->availabilityTrackingStart->fromDailyResults($monitoring);

        if (! $trackingStartedAt || $trackingStartedAt->gt($endDate)) {
            return $this->availabilityPayloadFactory->empty($startDate, $endDate, $trackingStartedAt);
        }

        $uptimeMinutes = 0;
        $downtimeMinutes = 0;
        $unknownMinutes = 0;
        $uptimeTotal = 0;
        $downtimeTotal = 0;
        $unknownTotal = 0;

        $today = Date::today();
        $historicalEndDate = $includeIntradayRawData
            ? $endDate->copy()->min($today->copy()->subDay()->endOfDay())
            : $endDate->copy();

        if ($startDate->lte($historicalEndDate)) {
            $aggregatedData = $monitoring->dailyResults()
                ->whereBetween('date', [$startDate->toDateString(), $historicalEndDate->toDateString()])
                ->selectRaw('
                    SUM(uptime_minutes) as uptime_minutes,
                    SUM(downtime_minutes) as downtime_minutes,
                    SUM(unknown_minutes) as unknown_minutes,
                    SUM(uptime_total) as uptime_total,
                    SUM(downtime_total) as downtime_total,
                    SUM(unknown_total) as unknown_total
                ')
                ->first();

            $uptimeMinutes += (int) ($aggregatedData->uptime_minutes ?? 0);
            $downtimeMinutes += (int) ($aggregatedData->downtime_minutes ?? 0);
            $unknownMinutes += (int) ($aggregatedData->unknown_minutes ?? 0);
            $uptimeTotal += (int) ($aggregatedData->uptime_total ?? 0);
            $downtimeTotal += (int) ($aggregatedData->downtime_total ?? 0);
            $unknownTotal += (int) ($aggregatedData->unknown_total ?? 0);
        }

        if ($includeIntradayRawData && $endDate->gte($today)) {
            $liveStartDate = $startDate->copy()->max($today);
            $liveUptimeDowntime = $this->rawAvailabilityCalculator->calculate($monitoring, $liveStartDate, $endDate);

            $uptimeMinutes += $liveUptimeDowntime->uptime->minutes;
            $downtimeMinutes += $liveUptimeDowntime->downtime->minutes;
            $unknownMinutes += $liveUptimeDowntime->unknown->minutes;
            $uptimeTotal += $liveUptimeDowntime->uptime->total;
            $downtimeTotal += $liveUptimeDowntime->downtime->total;
            $unknownTotal += $liveUptimeDowntime->unknown->total;
        }

        $incidentsCount = $this->availabilityIncidentCounter->countOverlapping($monitoring, $startDate, $endDate);

        return $this->availabilityPayloadFactory->build(
            $startDate,
            $endDate,
            $trackingStartedAt,
            $uptimeMinutes,
            $downtimeMinutes,
            $unknownMinutes,
            $uptimeTotal,
            $downtimeTotal,
            $unknownTotal,
            $incidentsCount
        );
    }
}
