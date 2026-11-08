<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Monitoring;

use App\Data\MonitoringResponseTimesPayload;
use App\Http\Requests\Api\MonitoringChecksRequest;
use App\Http\Requests\Api\MonitoringDaysRequest;
use App\Models\Monitoring;
use App\Services\MonitoringCheckHistoryService;
use App\Services\MonitoringResponseTimeService;
use App\Services\MonitoringServerHealthTelemetryService;
use Illuminate\Http\JsonResponse;

/**
 * @group Monitoring API
 *
 * This controller is responsible for handling all API requests related to monitoring data.
 * It provides endpoints for retrieving uptime/downtime, response times, incidents, and other monitoring statistics.
 * The controller makes extensive use of caching to ensure optimal performance.
 */
class MonitoringPerformanceController extends MonitoringDataController
{
    /**
     * Retrieves the response times for a given monitoring instance.
     *
     * @queryParam days integer The number of days to retrieve data for. Defaults to 30. Example: 30
     *
     * @response [
     * {
     * "datetime": "2021-01-01 00:00:00",
     * "response_time": 123
     * }
     * ]
     */
    public function responseTimes(
        Monitoring $monitoring,
        MonitoringDaysRequest $monitoringDaysRequest,
        MonitoringResponseTimeService $monitoringResponseTimeService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringDaysRequest->days();
        $monitoringDateRange = $monitoringDaysRequest->dateRange();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->responseTimesKey($monitoring, $days, $monitoringDateRange),
            fn (): MonitoringResponseTimesPayload => $monitoringResponseTimeService->getResponseTimes(
                $monitoring,
                $monitoringDateRange->startDate,
                $monitoringDateRange->endDate,
                $monitoringDateRange->shouldUseResponseTimeAggregates()
            )
        );

        return response()->json($data);
    }

    /**
     * Retrieves aggregated server-health telemetry for a given monitoring instance.
     *
     * @queryParam days integer The number of days to retrieve data for. Defaults to 30. Example: 7
     */
    public function serverHealthTelemetry(
        Monitoring $monitoring,
        MonitoringDaysRequest $monitoringDaysRequest,
        MonitoringServerHealthTelemetryService $monitoringServerHealthTelemetryService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);
        $days = $monitoringDaysRequest->days();
        $monitoringDateRange = $monitoringDaysRequest->dateRange();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->serverHealthTelemetryKey($monitoring, $days, $monitoringDateRange),
            fn (): array => $monitoringServerHealthTelemetryService->getTelemetry(
                $monitoring,
                $monitoringDateRange->startDate,
                $monitoringDateRange->endDate,
            ),
        );

        return response()->json($data);
    }

    /**
     * Retrieves historical monitoring checks including status code details.
     *
     * @queryParam days integer Optional number of past days to include. If omitted, all available history is considered.
     * @queryParam limit integer Optional maximum number of entries returned. Defaults to 100.
     * @queryParam offset integer Optional number of entries to skip for pagination. Defaults to 0.
     *
     * @response {
     *   "data": [
     *     {
     *       "id": "01H...",
     *       "checked_at": "2026-03-24T12:00:00Z",
     *       "status": "down",
     *       "http_status_code": 503,
     *       "response_time": 210.5,
     *       "status_identifier": "status.server_error",
     *       "status_key": "notifications.status.server_error",
     *       "source": "live"
     *     }
     *   ],
     *   "meta": {
     *     "count": 1,
     *     "limit": 100,
     *     "offset": 0,
     *     "days": 7,
     *     "has_more": false,
     *     "next_offset": null
     *   }
     * }
     */
    public function checks(
        Monitoring $monitoring,
        MonitoringChecksRequest $monitoringChecksRequest,
        MonitoringCheckHistoryService $monitoringCheckHistoryService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringChecksRequest->days();
        $limit = $monitoringChecksRequest->limit();
        $offset = $monitoringChecksRequest->offset();
        $startDate = $monitoringChecksRequest->startDate();
        $endDate = $monitoringChecksRequest->endDate();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->checksKey($monitoring, $days, $limit, $offset),
            fn (): array => $monitoringCheckHistoryService->getHistory($monitoring, $startDate, $endDate, $limit, $offset)
        );

        return response()->json([
            'data' => $data['data'],
            'meta' => [
                'count' => count($data['data']),
                'limit' => $limit,
                'offset' => $offset,
                'days' => $days,
                'has_more' => $data['has_more'],
                'next_offset' => $data['next_offset'],
            ],
        ]);
    }
}
