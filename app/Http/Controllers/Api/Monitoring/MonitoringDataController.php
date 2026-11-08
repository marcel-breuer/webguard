<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Monitoring;
use App\Models\User;
use App\Queries\MonitoringDataQuery;
use App\Services\MonitoringStatsCache;

/**
 * Shared access and caching helpers for the monitoring data endpoints.
 */
abstract class MonitoringDataController extends Controller
{
    public function __construct(
        protected readonly MonitoringStatsCache $monitoringStatsCache,
        private readonly MonitoringDataQuery $monitoringDataQuery,
    ) {}

    protected function accessibleMonitoring(Monitoring $monitoring): Monitoring
    {
        /** @var User|null $user */
        $user = request()->user();

        return $this->monitoringDataQuery->findAccessible($user, (string) $monitoring->getKey());
    }
}
