<?php

declare(strict_types=1);

namespace App\Services\Monitorings;

use App\Models\Monitoring;
use App\Services\MonitoringStatsCache;
use Illuminate\Support\Facades\DB;

final class ResetMonitoringResultsService
{
    public function __construct(private readonly MonitoringStatsCache $monitoringStatsCache) {}

    public function reset(Monitoring $monitoring): void
    {
        DB::transaction(function () use ($monitoring): void {
            $monitoring->responseResults()->delete();
            $monitoring->archivedResponseResults()->delete();
            $monitoring->dailyResults()->delete();
            $monitoring->incidents()->delete();
        });

        $this->monitoringStatsCache->flush($monitoring);
    }
}
