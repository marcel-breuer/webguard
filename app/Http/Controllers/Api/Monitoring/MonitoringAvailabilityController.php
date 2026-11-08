<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Monitoring;

use App\Data\MonitoringAvailabilityPayload;
use App\Http\Requests\Api\MonitoringDaysRequest;
use App\Http\Requests\Api\MonitoringUptimeCalendarRequest;
use App\Http\Requests\Api\MonitoringUptimeSummaryRequest;
use App\Models\Monitoring;
use App\Services\MonitoringAvailabilityService;
use App\Services\MonitoringHeatmapService;
use App\Services\MonitoringUptimeCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * @group Monitoring API
 *
 * This controller is responsible for handling all API requests related to monitoring data.
 * It provides endpoints for retrieving uptime/downtime, response times, incidents, and other monitoring statistics.
 * The controller makes extensive use of caching to ensure optimal performance.
 */
class MonitoringAvailabilityController extends MonitoringDataController
{
    /**
     * Retrieves the uptime and downtime data for a given monitoring instance.
     *
     * @queryParam days integer The number of days to retrieve data for. Defaults to 30. Example: 30
     *
     * @response [
     * {
     * "date": "2021-01-01",
     * "uptime": 100,
     * "downtime": 0
     * }
     * ]
     */
    public function uptimeDowntime(
        Monitoring $monitoring,
        MonitoringDaysRequest $monitoringDaysRequest,
        MonitoringAvailabilityService $monitoringAvailabilityService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringDaysRequest->days();
        $monitoringDateRange = $monitoringDaysRequest->dateRange();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->uptimeKey($monitoring, $days, $monitoringDateRange),
            fn (): MonitoringAvailabilityPayload => $monitoringAvailabilityService->getUptimeDowntime(
                $monitoring,
                $monitoringDateRange->startDate,
                $monitoringDateRange->endDate,
                $monitoringDateRange->shouldUseUptimeAggregates($monitoring),
                $monitoringDateRange->shouldIncludeIntradayRawData()
            )
        );

        return response()->json($data);
    }

    /**
     * Retrieves uptime and downtime data for multiple day ranges in one request.
     *
     * @queryParam days[] integer[] The day ranges to retrieve. Example: [7, 30, 90]
     *
     * @response {
     *   "data": {
     *     "7": {
     *       "has_data": true
     *     },
     *     "30": {
     *       "has_data": true
     *     }
     *   }
     * }
     */
    public function uptimeDowntimeSummary(
        Monitoring $monitoring,
        MonitoringUptimeSummaryRequest $monitoringUptimeSummaryRequest,
        MonitoringAvailabilityService $monitoringAvailabilityService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringUptimeSummaryRequest->days();

        $endDate = now()->endOfDay();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->uptimeSummaryKey($monitoring, $days, $endDate),
            fn (): array => $monitoringAvailabilityService->getUptimeDowntimesForRanges($monitoring, $days->all())
        );

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Retrieves the uptime heatmap data for a given monitoring instance.
     *
     * @response [
     * {
     * "hour": "00:00",
     * "uptime": 100
     * }
     * ]
     */
    public function uptimeHeatmap(
        Monitoring $monitoring,
        MonitoringHeatmapService $monitoringHeatmapService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $start_date = now()->subHours(23);
        $end_date = now();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->heatmapKey($monitoring),
            fn (): Collection => $monitoringHeatmapService->getHeatmap($monitoring, $start_date, $end_date),
            $this->monitoringStatsCache->heatmapExpiresAt()
        );

        return response()->json($data);
    }

    /**
     * Retrieves the uptime calendar data for a given monitoring instance.
     *
     * @queryParam start_date date required The start date to retrieve data for. Example: 2021-01-01
     * @queryParam end_date date required The end date to retrieve data for. Example: 2021-01-31
     *
     * @response {
     * "2021-01": [
     * {
     * "date": "2021-01-01",
     * "uptime": "100.00"
     * }
     * ]
     * }
     */
    public function uptimeCalendar(
        Monitoring $monitoring,
        MonitoringUptimeCalendarRequest $monitoringUptimeCalendarRequest,
        MonitoringUptimeCalendarService $monitoringUptimeCalendarService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $startDate = $monitoringUptimeCalendarRequest->startDate();
        $endDate = $monitoringUptimeCalendarRequest->endDate();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->uptimeCalendarKey($monitoring, $startDate, $endDate),
            fn (): array => $monitoringUptimeCalendarService->getGroupedByDateAndMonth($monitoring, $startDate, $endDate)->toArray(),
            $this->monitoringStatsCache->calendarTtlSeconds()
        );

        return response()->json($data);
    }
}
