<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Collection;
use JsonSerializable;

final readonly class MonitoringUptimeCalendarMonthPayload implements JsonSerializable
{
    /**
     * @param  Collection<int, MonitoringUptimeCalendarDayPayload>  $days
     */
    public function __construct(
        public Collection $days,
        public ?float $monthlyAverageUptime
    ) {}

    /**
     * @return array{days: array<int, array{date: string, uptime_percentage: float|null}>, monthly_average_uptime: float|null}
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days
                ->map(static fn (MonitoringUptimeCalendarDayPayload $monitoringUptimeCalendarDayPayload): array => $monitoringUptimeCalendarDayPayload->toArray())
                ->values()
                ->all(),
            'monthly_average_uptime' => $this->monthlyAverageUptime,
        ];
    }

    /**
     * @return array{days: array<int, array{date: string, uptime_percentage: float|null}>, monthly_average_uptime: float|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
