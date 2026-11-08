<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\MonitoringAvailabilityPayload;
use App\Data\MonitoringAvailabilitySegmentPayload;
use Carbon\Carbon;

/**
 * Turns summed uptime, downtime and unknown minutes into the availability payload with percentages.
 */
class AvailabilityPayloadFactory
{
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        ?Carbon $trackingStartedAt,
        int $uptimeMinutes,
        int $downtimeMinutes,
        int $unknownMinutes,
        int $uptimeTotal,
        int $downtimeTotal,
        int $unknownTotal,
        int $incidentsCount
    ): MonitoringAvailabilityPayload {
        $totalTrackedMinutes = $uptimeMinutes + $downtimeMinutes + $unknownMinutes;
        $hasData = $totalTrackedMinutes > 0;

        return new MonitoringAvailabilityPayload(
            from: $startDate,
            to: $endDate,
            hasData: $hasData,
            trackingStartedAt: $trackingStartedAt?->toIso8601String(),
            uptime: new MonitoringAvailabilitySegmentPayload(
                minutes: $uptimeMinutes,
                percentage: $hasData ? ($uptimeMinutes / $totalTrackedMinutes) * 100 : null,
                total: $uptimeTotal
            ),
            downtime: new MonitoringAvailabilitySegmentPayload(
                minutes: $downtimeMinutes,
                percentage: $hasData ? ($downtimeMinutes / $totalTrackedMinutes) * 100 : null,
                total: $downtimeTotal,
                incidentsCount: $incidentsCount
            ),
            unknown: new MonitoringAvailabilitySegmentPayload(
                minutes: $unknownMinutes,
                percentage: $hasData ? ($unknownMinutes / $totalTrackedMinutes) * 100 : null,
                total: $unknownTotal
            )
        );
    }

    public function empty(Carbon $startDate, Carbon $endDate, ?Carbon $trackingStartedAt): MonitoringAvailabilityPayload
    {
        return $this->build($startDate, $endDate, $trackingStartedAt, 0, 0, 0, 0, 0, 0, 0);
    }
}
