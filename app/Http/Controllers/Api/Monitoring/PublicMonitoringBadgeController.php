<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Monitoring;
use App\Services\MonitoringBadgePayloadService;
use App\Services\MonitoringStatsCache;
use Illuminate\Http\JsonResponse;

/**
 * @group Monitoring API
 *
 * This controller is responsible for handling all API requests related to monitoring data.
 * It provides endpoints for retrieving uptime/downtime, response times, incidents, and other monitoring statistics.
 * The controller makes extensive use of caching to ensure optimal performance.
 */
class PublicMonitoringBadgeController extends Controller
{
    public function __construct(private readonly MonitoringStatsCache $monitoringStatsCache) {}

    /**
     * Retrieves the public SLA badge payload for a monitoring instance.
     *
     * @response {
     *   "name": "Primary API",
     *   "status": "up",
     *   "status_label": "UP",
     *   "status_code": 200,
     *   "status_identifier": "status.success",
     *   "status_key": "notifications.status.success",
     *   "checked_at": "2026-04-12T10:00:00Z",
     *   "checked_at_human": "04/12/2026 10:00 AM",
     *   "uptime": {
     *     "7_days": 100,
     *     "30_days": 99.9,
     *     "90_days": 99.5,
     *     "365_days": 99.1
     *   },
     *   "public_url": "https://example.com/status/01H...",
     *   "incidents": {
     *     "30_days": 0,
     *     "90_days": 1,
     *     "365_days": 2
     *   },
     *   "ssl": {
     *     "valid": true,
     *     "expires_at": "2026-08-01T00:00:00+00:00"
     *   },
     *   "domain": {
     *     "valid": true,
     *     "expires_at": "2027-02-01T00:00:00+00:00"
     *   },
     *   "maintenance": {
     *     "active": false,
     *     "starts_at": null,
     *     "ends_at": null
     *   }
     * }
     */
    public function __invoke(
        Monitoring $monitoring,
        MonitoringBadgePayloadService $monitoringBadgePayloadService
    ): JsonResponse {
        abort_unless($monitoring->public_label_enabled, 404);

        $data = $this->monitoringStatsCache->remember(
            $monitoring,
            $this->monitoringStatsCache->badgeKey($monitoring),
            fn (): array => $monitoringBadgePayloadService->getPayload($monitoring)->toArray()
        );

        return response()
            ->json($data)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }
}
