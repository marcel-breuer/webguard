<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

final class MonitoringGroupSlaReportService
{
    public function __construct(
        private readonly MonitoringAvailabilityService $monitoringAvailabilityService,
        private readonly MaintenanceWindowService $maintenanceWindowService,
    ) {}

    /**
     * @return array{
     *     group_name: string,
     *     period_start: Carbon,
     *     period_end: Carbon,
     *     target_percentage: float,
     *     uptime_percentage: float|null,
     *     meets_target: bool|null,
     *     uptime_minutes: int,
     *     downtime_minutes: int,
     *     unknown_minutes: int,
     *     incidents_count: int,
     *     monitorings: list<array{name: string, target: string, uptime_percentage: float|null, uptime_minutes: int, downtime_minutes: int, unknown_minutes: int, incidents_count: int}>
     * }
     */
    public function build(MonitoringGroup $monitoringGroup, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $periodStart = Date::instance($periodStart)->startOfDay();
        $periodEnd = Date::instance($periodEnd)->endOfDay();
        $monitoringGroup->loadMissing(['monitorings', 'maintenanceWindows']);

        $totalUptimeMinutes = 0;
        $totalDowntimeMinutes = 0;
        $totalUnknownMinutes = 0;
        $totalIncidentCount = 0;
        $rows = [];

        foreach ($monitoringGroup->monitorings as $monitoring) {
            $availability = $this->monitoringAvailabilityService->getUptimeDowntime(
                $monitoring,
                $periodStart,
                $periodEnd,
                loadAggregatedData: true,
                includeIntradayRawData: false,
            );

            $uptimeMinutes = $availability->uptime->minutes;
            $downtimeMinutes = $availability->downtime->minutes;
            $unknownMinutes = $availability->unknown->minutes;
            $maintenanceMinutes = $this->maintenanceMinutesFor($monitoring, $monitoringGroup, $periodStart, $periodEnd);
            $this->excludeMaintenance($maintenanceMinutes, $uptimeMinutes, $downtimeMinutes, $unknownMinutes);

            $observedMinutes = $uptimeMinutes + $downtimeMinutes;
            $uptimePercentage = $observedMinutes > 0 ? ($uptimeMinutes / $observedMinutes) * 100 : null;
            $incidentCount = $monitoring->incidents()
                ->where('down_at', '<=', $periodEnd)
                ->where(fn ($query) => $query->where('up_at', '>=', $periodStart)->orWhereNull('up_at'))
                ->count();

            $totalUptimeMinutes += $uptimeMinutes;
            $totalDowntimeMinutes += $downtimeMinutes;
            $totalUnknownMinutes += $unknownMinutes;
            $totalIncidentCount += $incidentCount;
            $rows[] = [
                'name' => $monitoring->name,
                'target' => $monitoring->target,
                'uptime_percentage' => $uptimePercentage,
                'uptime_minutes' => $uptimeMinutes,
                'downtime_minutes' => $downtimeMinutes,
                'unknown_minutes' => $unknownMinutes,
                'incidents_count' => $incidentCount,
            ];
        }

        $observedMinutes = $totalUptimeMinutes + $totalDowntimeMinutes;
        $uptimePercentage = $observedMinutes > 0 ? ($totalUptimeMinutes / $observedMinutes) * 100 : null;
        $target = (float) $monitoringGroup->sla_target_percentage;

        return [
            'group_name' => $monitoringGroup->name,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'target_percentage' => $target,
            'uptime_percentage' => $uptimePercentage,
            'meets_target' => $uptimePercentage === null ? null : $uptimePercentage >= $target,
            'uptime_minutes' => $totalUptimeMinutes,
            'downtime_minutes' => $totalDowntimeMinutes,
            'unknown_minutes' => $totalUnknownMinutes,
            'incidents_count' => $totalIncidentCount,
            'monitorings' => $rows,
        ];
    }

    public function previousCalendarMonth(MonitoringGroup $monitoringGroup, ?CarbonInterface $asOf = null): array
    {
        $asOf = Date::instance($asOf ?? Date::now());
        $periodStart = $asOf->copy()->startOfMonth()->subMonthNoOverflow()->startOfMonth();

        return $this->build($monitoringGroup, $periodStart, $periodStart->copy()->endOfMonth());
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function csv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return '';
        }

        fputcsv($stream, ['Monitoring group', $report['group_name']]);
        fputcsv($stream, ['Period start', $report['period_start']->toDateString()]);
        fputcsv($stream, ['Period end', $report['period_end']->toDateString()]);
        fputcsv($stream, ['Target (%)', number_format((float) $report['target_percentage'], 2, '.', '')]);
        fputcsv($stream, ['Actual uptime (%)', $report['uptime_percentage'] === null ? 'No data' : number_format((float) $report['uptime_percentage'], 4, '.', '')]);
        fputcsv($stream, ['Target met', $report['meets_target'] === null ? 'No data' : ($report['meets_target'] ? 'Yes' : 'No')]);
        fputcsv($stream, ['Uptime minutes', $report['uptime_minutes']]);
        fputcsv($stream, ['Downtime minutes', $report['downtime_minutes']]);
        fputcsv($stream, ['Unknown minutes excluded', $report['unknown_minutes']]);
        fputcsv($stream, ['Incidents', $report['incidents_count']]);
        fputcsv($stream, []);
        fputcsv($stream, ['Monitoring', 'Target', 'Uptime (%)', 'Uptime minutes', 'Downtime minutes', 'Unknown minutes excluded', 'Incidents']);

        foreach ($report['monitorings'] as $monitoring) {
            fputcsv($stream, [
                $monitoring['name'],
                $monitoring['target'],
                $monitoring['uptime_percentage'] === null ? 'No data' : number_format((float) $monitoring['uptime_percentage'], 4, '.', ''),
                $monitoring['uptime_minutes'],
                $monitoring['downtime_minutes'],
                $monitoring['unknown_minutes'],
                $monitoring['incidents_count'],
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    private function maintenanceMinutesFor(
        Monitoring $monitoring,
        MonitoringGroup $monitoringGroup,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): int {
        $intervals = [];
        $legacyStart = $monitoring->maintenance_from;
        $legacyEnd = $monitoring->maintenance_until ?? $periodEnd;

        if ($legacyStart !== null && $legacyEnd->gte($periodStart) && $legacyStart->lte($periodEnd)) {
            $intervals[] = [
                $legacyStart->copy()->max($periodStart),
                $legacyEnd->copy()->min($periodEnd),
            ];
        }

        $windows = MaintenanceWindow::query()
            ->enabled()
            ->where(fn ($query) => $query
                ->where('monitoring_id', $monitoring->id)
                ->orWhere('monitoring_group_id', $monitoringGroup->id))
            ->get();

        foreach ($windows as $window) {
            $intervals = [...$intervals, ...$this->windowIntervals($window, $periodStart, $periodEnd)];
        }

        usort($intervals, static fn (array $left, array $right): int => $left[0]->getTimestamp() <=> $right[0]->getTimestamp());
        $merged = [];

        foreach ($intervals as [$from, $until]) {
            $lastIndex = array_key_last($merged);
            if ($lastIndex !== null && $from->lte($merged[$lastIndex][1])) {
                $merged[$lastIndex][1] = $merged[$lastIndex][1]->max($until);

                continue;
            }

            $merged[] = [$from, $until];
        }

        return (int) collect($merged)->sum(
            static fn (array $interval): int => max(0, (int) floor(($interval[1]->getTimestamp() - $interval[0]->getTimestamp()) / 60))
        );
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function windowIntervals(MaintenanceWindow $maintenanceWindow, Carbon $periodStart, Carbon $periodEnd): array
    {
        $intervals = [];
        $cursor = $periodStart->copy();

        while ($cursor->lte($periodEnd)) {
            $occurrence = $this->maintenanceWindowService->occurrence($maintenanceWindow, $cursor);

            if ($occurrence === null) {
                break;
            }

            $startsAt = Date::instance($occurrence['starts_at']);
            $endsAt = Date::instance($occurrence['ends_at']);

            if ($startsAt->gt($periodEnd)) {
                break;
            }

            $overlapStart = $startsAt->copy()->max($periodStart);
            $overlapEnd = $endsAt->copy()->min($periodEnd);
            if ($overlapStart->lt($overlapEnd)) {
                $intervals[] = [$overlapStart, $overlapEnd];
            }

            $cursor = $endsAt->copy()->addSecond();
        }

        return $intervals;
    }

    private function excludeMaintenance(int $minutes, int &$uptimeMinutes, int &$downtimeMinutes, int &$unknownMinutes): void
    {
        $removed = min($unknownMinutes, $minutes);
        $unknownMinutes -= $removed;
        $minutes -= $removed;

        $removed = min($downtimeMinutes, $minutes);
        $downtimeMinutes -= $removed;
        $minutes -= $removed;

        $uptimeMinutes -= min($uptimeMinutes, $minutes);
    }
}
