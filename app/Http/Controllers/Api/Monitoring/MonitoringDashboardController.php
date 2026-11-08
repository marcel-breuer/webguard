<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Requests\Api\MonitoringDashboardRequest;
use App\Http\Requests\Api\MonitoringDaysRequest;
use App\Models\Monitoring;
use App\Services\MonitoringDashboardPayloadService;
use App\Services\MonitoringIncidentService;
use App\Services\MonitoringStatusPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * @group Monitoring API
 *
 * This controller is responsible for handling all API requests related to monitoring data.
 * It provides endpoints for retrieving uptime/downtime, response times, incidents, and other monitoring statistics.
 * The controller makes extensive use of caching to ensure optimal performance.
 */
class MonitoringDashboardController extends MonitoringDataController
{
    /**
     * Retrieves all data for a given monitoring instance.
     *
     * @response {
     *  "status_since": {
     *  "status": "UP",
     *  "time": "2021-01-01 00:00:00"
     *  },
     *  "status_now": {
     *  "status": "UP"
     *  },
     *  "uptime_downtime": [
     *  {
     *  "date": "2021-01-01",
     *  "uptime": 100,
     *  "downtime": 0
     *  }
     *  ],
     *  "response_times": [
     *  {
     *  "datetime": "2021-01-01 00:00:00",
     *  "response_time": 123
     *  }
     *  ],
     *  "incidents": [
     *  {
     *  "started_at": "2021-01-01 00:00:00",
     *  "finished_at": "2021-01-01 00:05:00",
     *  "type": "DOWN",
     *  "reason": "HTTP status code 500"
     *  }
     *  ],
     *  "heatmap": [
     *  {
     *  "hour": "00:00",
     *  "uptime": 100
     *  }
     *  ],
     *  "ssl": {
     *  "valid": true,
     *  "expiration": "2022-01-01T00:00:00.000000Z",
     *  "issuer": "Let's Encrypt",
     *  "issue_date": "2021-10-01T00:00:00.000000Z"
     *  },
     *  "uptime_calendar": {
     *  "2021-01": [
     *  {
     *  "date": "2021-01-01",
     *  "uptime": "100.00"
     *  }
     *  ]
     *  }
     * }
     */
    public function all(
        Monitoring $monitoring,
        MonitoringDashboardRequest $monitoringDashboardRequest,
        MonitoringDashboardPayloadService $monitoringDashboardPayloadService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringDashboardRequest->days();
        $calendarStartDate = $monitoringDashboardRequest->calendarStartDate();
        $calendarEndDate = $monitoringDashboardRequest->calendarEndDate();
        $monitoringDateRange = $monitoringDashboardRequest->dateRange();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->dashboardKey($monitoring, $days, $monitoringDateRange, $calendarStartDate, $calendarEndDate),
            fn (): array => $monitoringDashboardPayloadService->getPayload(
                $monitoring,
                $days,
                $calendarStartDate,
                $calendarEndDate
            )->toArray()
        );

        return response()->json($data);
    }

    /**
     * Retrieves the combined status of a given monitoring instance.
     *
     * @response {
     * "status": "UP",
     * "since": "2021-01-01 00:00:00",
     * "checked_at": "2021-01-01 00:00:00",
     * "next": "2021-01-01 00:05:00",
     * "interval": 300
     * }
     */
    public function status(
        Monitoring $monitoring,
        MonitoringStatusPayloadService $monitoringStatusPayloadService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        return response()->json($monitoringStatusPayloadService->getPayload($monitoring)->toArray());
    }

    /**
     * Retrieves the incidents for a given monitoring instance.
     *
     * @queryParam days integer The number of days to retrieve data for. Defaults to 30. Example: 30
     *
     * @response [
     * {
     * "started_at": "2021-01-01 00:00:00",
     * "finished_at": "2021-01-01 00:05:00",
     * "type": "DOWN",
     * "reason": "HTTP status code 500"
     * }
     * ]
     */
    public function incidents(
        Monitoring $monitoring,
        MonitoringDaysRequest $monitoringDaysRequest,
        MonitoringIncidentService $monitoringIncidentService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $days = $monitoringDaysRequest->days();
        $monitoringDateRange = $monitoringDaysRequest->dateRange();

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->incidentsKey($monitoring, $days, $monitoringDateRange),
            fn (): Collection => $monitoringIncidentService->getIncidents($monitoring, $monitoringDateRange->startDate, $monitoringDateRange->endDate),
        );

        return response()->json($data);
    }

    /**
     * Retrieves the SSL status for a given monitoring instance.
     *
     * @response {
     * "valid": true,
     * "expiration": "2022-01-01T00:00:00.000000Z",
     * "issuer": "Let's Encrypt",
     * "issue_date": "2021-10-01T00:00:00.000000Z"
     * }
     */
    public function sslStatus(
        Monitoring $monitoring,
        MonitoringDashboardPayloadService $monitoringDashboardPayloadService
    ): JsonResponse {
        $monitoring = $this->accessibleMonitoring($monitoring);

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->sslStatusKey($monitoring),
            fn (): array => $monitoringDashboardPayloadService->getSslPayload($monitoring)->toArray()
        );

        return response()->json($data);
    }
}
