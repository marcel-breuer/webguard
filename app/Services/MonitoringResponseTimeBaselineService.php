<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MonitoringStatus;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use Illuminate\Support\Facades\DB;

final class MonitoringResponseTimeBaselineService
{
    public const int WINDOW_DAYS = 7;

    public const int MINIMUM_SAMPLES = 20;

    /**
     * @return array{median_ms: float, sample_count: int}|null
     */
    public function for(Monitoring $monitoring, MonitoringResponse $monitoringResponse): ?array
    {
        $locationCode = $monitoringResponse->location_code;
        $start = $monitoringResponse->created_at->copy()->subDays(self::WINDOW_DAYS);
        $end = $monitoringResponse->created_at;

        $builder = DB::table('monitoring_response_results')
            ->select('response_time')
            ->where('monitoring_id', $monitoring->id)
            ->where('status', MonitoringStatus::UP->value)
            ->whereNotNull('response_time')
            ->where('id', '!=', $monitoringResponse->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where(function ($query) use ($locationCode): void {
                $locationCode === null
                    ? $query->whereNull('location_code')
                    : $query->where('location_code', $locationCode);
            });

        $archived = DB::table('monitoring_response_archived')
            ->select('response_time')
            ->where('monitoring_id', $monitoring->id)
            ->where('status', MonitoringStatus::UP->value)
            ->whereNotNull('response_time')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where(function ($query) use ($locationCode): void {
                $locationCode === null
                    ? $query->whereNull('location_code')
                    : $query->where('location_code', $locationCode);
            });

        $responseTimes = $builder->unionAll($archived)
            ->pluck('response_time')
            ->map(static fn (mixed $responseTime): float => (float) $responseTime)
            ->sort()
            ->values();

        $sampleCount = $responseTimes->count();

        if ($sampleCount < self::MINIMUM_SAMPLES) {
            return null;
        }

        $middle = intdiv($sampleCount, 2);
        $median = $sampleCount % 2 === 0
            ? ($responseTimes[$middle - 1] + $responseTimes[$middle]) / 2
            : $responseTimes[$middle];

        return ['median_ms' => $median, 'sample_count' => $sampleCount];
    }
}
