<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\MonitoringAvailabilityPayload;
use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Computes several rolling day ranges in a single pass over the daily results.
 */
class RollingRangeAvailabilityCalculator
{
    public function __construct(
        private readonly RawAvailabilityCalculator $rawAvailabilityCalculator,
        private readonly AvailabilityPayloadFactory $availabilityPayloadFactory,
        private readonly AvailabilityIncidentCounter $availabilityIncidentCounter,
    ) {}

    /**
     * @param  Collection<int, int<1, max>>  $normalizedDays  Ascending, unique, positive day counts.
     * @return array<string, MonitoringAvailabilityPayload>
     */
    public function calculate(
        Monitoring $monitoring,
        Collection $normalizedDays,
        Carbon $now,
        bool $includeIntradayRawData
    ): array {
        $maxRangeDays = $normalizedDays->max() ?? 0;
        $globalStartDate = $now->copy()->subDays($maxRangeDays)->startOfDay();
        $today = $now->copy()->startOfDay();
        $historicalEndDate = $today->copy()->subDay()->endOfDay();

        $dailyResults = $monitoring->dailyResults()
            ->whereBetween('date', [$globalStartDate->copy()->startOfDay(), $historicalEndDate->copy()->endOfDay()])
            ->orderByDesc('date')
            ->get([
                'date',
                'uptime_minutes',
                'downtime_minutes',
                'unknown_minutes',
                'uptime_total',
                'downtime_total',
                'unknown_total',
            ]);

        $liveUptimeDowntime = $includeIntradayRawData && $this->shouldLoadIntradayRawData($monitoring, $today)
            ? $this->rawAvailabilityCalculator->calculate($monitoring, $today, $now, false)
            : null;
        $trackingStartedAt = $dailyResults->last()?->date
            ? Date::parse($dailyResults->last()->date)->startOfDay()
            : ($liveUptimeDowntime?->trackingStartedAt
                ? Date::parse($liveUptimeDowntime->trackingStartedAt)
                : null);

        if (! $trackingStartedAt || $trackingStartedAt->gt($now)) {
            return $normalizedDays
                ->mapWithKeys(function (int $day) use ($now, $trackingStartedAt): array {
                    $startDate = $now->copy()->subDays($day)->startOfDay();

                    return [
                        (string) $day => $this->availabilityPayloadFactory->empty($startDate, $now, $trackingStartedAt),
                    ];
                })
                ->all();
        }

        $overlappingIncidents = $this->availabilityIncidentCounter->overlapping($monitoring, $globalStartDate, $now);

        $rollingTotals = [
            'uptime_minutes' => 0,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'uptime_total' => 0,
            'downtime_total' => 0,
            'unknown_total' => 0,
        ];
        $dailyResultIndex = 0;

        return $normalizedDays
            ->mapWithKeys(function (int $day) use ($dailyResults, &$dailyResultIndex, &$rollingTotals, $now, $today, $trackingStartedAt, $liveUptimeDowntime, $overlappingIncidents): array {
                $startDate = $now->copy()->subDays($day)->startOfDay();
                $startDateString = $startDate->toDateString();

                while ($dailyResult = $dailyResults[$dailyResultIndex] ?? null) {
                    $dailyResultDate = $dailyResult->date->toDateString();

                    if ($dailyResultDate < $startDateString) {
                        break;
                    }

                    $rollingTotals['uptime_minutes'] += (int) $dailyResult->uptime_minutes;
                    $rollingTotals['downtime_minutes'] += (int) $dailyResult->downtime_minutes;
                    $rollingTotals['unknown_minutes'] += (int) $dailyResult->unknown_minutes;
                    $rollingTotals['uptime_total'] += (int) $dailyResult->uptime_total;
                    $rollingTotals['downtime_total'] += (int) $dailyResult->downtime_total;
                    $rollingTotals['unknown_total'] += (int) $dailyResult->unknown_total;

                    $dailyResultIndex++;
                }

                $uptimeMinutes = $rollingTotals['uptime_minutes'];
                $downtimeMinutes = $rollingTotals['downtime_minutes'];
                $unknownMinutes = $rollingTotals['unknown_minutes'];
                $uptimeTotal = $rollingTotals['uptime_total'];
                $downtimeTotal = $rollingTotals['downtime_total'];
                $unknownTotal = $rollingTotals['unknown_total'];

                if ($liveUptimeDowntime && $startDate->lte($today)) {
                    $uptimeMinutes += $liveUptimeDowntime->uptime->minutes;
                    $downtimeMinutes += $liveUptimeDowntime->downtime->minutes;
                    $unknownMinutes += $liveUptimeDowntime->unknown->minutes;
                    $uptimeTotal += $liveUptimeDowntime->uptime->total;
                    $downtimeTotal += $liveUptimeDowntime->downtime->total;
                    $unknownTotal += $liveUptimeDowntime->unknown->total;
                }

                $incidentsCount = $this->availabilityIncidentCounter->countOverlappingIn(
                    $overlappingIncidents,
                    $startDate,
                    $now
                );

                return [
                    (string) $day => $this->availabilityPayloadFactory->build(
                        $startDate,
                        $now,
                        $trackingStartedAt,
                        $uptimeMinutes,
                        $downtimeMinutes,
                        $unknownMinutes,
                        $uptimeTotal,
                        $downtimeTotal,
                        $unknownTotal,
                        $incidentsCount
                    ),
                ];
            })
            ->all();
    }

    private function shouldLoadIntradayRawData(Monitoring $monitoring, Carbon $today): bool
    {
        return $monitoring->latestResponseResult?->created_at?->gte($today) ?? false;
    }
}
