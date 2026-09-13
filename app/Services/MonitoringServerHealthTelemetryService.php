<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Monitoring;
use App\Queries\MonitoringCheckHistoryQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

final class MonitoringServerHealthTelemetryService
{
    public function __construct(private readonly MonitoringCheckHistoryQuery $monitoringCheckHistoryQuery) {}

    /**
     * @return array{data: array<int, array{checked_at: string, cpu_usage_percent: float|null, ram_usage_percent: float|null, storage_usage_percent: float|null, normalized_load: float|null}>, thresholds: array{cpu_usage_percent: float, ram_usage_percent: float, storage_usage_percent: float, load_per_cpu: float|null}}
     */
    public function getTelemetry(Monitoring $monitoring, Carbon $startDate, Carbon $endDate): array
    {
        $grouping = $startDate->diffInHours($endDate) < 48 ? 'hour' : 'day';
        $rows = $this->monitoringCheckHistoryQuery->forRange((string) $monitoring->getKey(), $startDate, $endDate);

        $data = $rows
            ->map(function (object $row) use ($grouping): ?array {
                $metrics = json_decode((string) ($row->server_health_metrics ?? ''), true);

                if (! is_array($metrics) || $metrics === []) {
                    return null;
                }

                $checkedAt = Date::parse((string) $row->created_at);

                return [
                    'checked_at' => $grouping === 'hour' ? $checkedAt->startOfHour() : $checkedAt->startOfDay(),
                    'cpu_usage_percent' => $this->numericValue($metrics, 'cpu_usage_percent'),
                    'ram_usage_percent' => $this->numericValue($metrics, 'ram_usage_percent'),
                    'storage_usage_percent' => $this->numericValue($metrics, 'storage_usage_percent'),
                    'normalized_load' => $this->normalizedLoad($metrics),
                ];
            })
            ->filter()
            ->groupBy(fn (array $row): string => $row['checked_at']->toIso8601String())
            ->map(function (Collection $bucket): array {
                return [
                    'checked_at' => $bucket->first()['checked_at']->toIso8601String(),
                    'cpu_usage_percent' => $this->average($bucket, 'cpu_usage_percent'),
                    'ram_usage_percent' => $this->average($bucket, 'ram_usage_percent'),
                    'storage_usage_percent' => $this->average($bucket, 'storage_usage_percent'),
                    'normalized_load' => $this->average($bucket, 'normalized_load'),
                ];
            })
            ->values()
            ->all();

        return [
            'data' => $data,
            'thresholds' => [
                'cpu_usage_percent' => (float) ($monitoring->server_health_cpu_threshold_percent ?? 90),
                'ram_usage_percent' => (float) ($monitoring->server_health_ram_threshold_percent ?? 90),
                'storage_usage_percent' => (float) ($monitoring->server_health_storage_threshold_percent ?? 90),
                'load_per_cpu' => $monitoring->server_health_load_threshold_per_cpu !== null
                    ? (float) $monitoring->server_health_load_threshold_per_cpu
                    : null,
            ],
        ];
    }

    /** @param array<string, mixed> $metrics */
    private function numericValue(array $metrics, string $key): ?float
    {
        return isset($metrics[$key]) && is_numeric($metrics[$key]) ? (float) $metrics[$key] : null;
    }

    /** @param array<string, mixed> $metrics */
    private function normalizedLoad(array $metrics): ?float
    {
        $load = $this->numericValue($metrics, 'load_average_1m');
        $cpuCount = $this->numericValue($metrics, 'logical_cpu_count');

        return $load !== null && $cpuCount !== null && $cpuCount > 0 ? $load / $cpuCount : null;
    }

    /** @param Collection<int, array<string, mixed>> $bucket */
    private function average(Collection $bucket, string $key): ?float
    {
        $values = $bucket->pluck($key)->filter(static fn (mixed $value): bool => $value !== null);

        return $values->isEmpty() ? null : (float) $values->avg();
    }
}
