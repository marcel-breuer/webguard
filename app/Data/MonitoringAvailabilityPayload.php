<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\Carbon;
use JsonSerializable;

final readonly class MonitoringAvailabilityPayload implements JsonSerializable
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public bool $hasData,
        public ?string $trackingStartedAt,
        public MonitoringAvailabilitySegmentPayload $uptime,
        public MonitoringAvailabilitySegmentPayload $downtime,
        public MonitoringAvailabilitySegmentPayload $unknown
    ) {}

    /**
     * @return array{
     *     data: array{from: Carbon, to: Carbon},
     *     has_data: bool,
     *     tracking_started_at: string|null,
     *     uptime: array{minutes: int, percentage: float|null, total: int, incidents_count?: int},
     *     downtime: array{minutes: int, percentage: float|null, total: int, incidents_count?: int},
     *     unknown: array{minutes: int, percentage: float|null, total: int, incidents_count?: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'data' => [
                'from' => $this->from,
                'to' => $this->to,
            ],
            'has_data' => $this->hasData,
            'tracking_started_at' => $this->trackingStartedAt,
            'uptime' => $this->uptime->toArray(),
            'downtime' => $this->downtime->toArray(),
            'unknown' => $this->unknown->toArray(),
        ];
    }

    /**
     * @return array{
     *     data: array{from: Carbon, to: Carbon},
     *     has_data: bool,
     *     tracking_started_at: string|null,
     *     uptime: array{minutes: int, percentage: float|null, total: int, incidents_count?: int},
     *     downtime: array{minutes: int, percentage: float|null, total: int, incidents_count?: int},
     *     unknown: array{minutes: int, percentage: float|null, total: int, incidents_count?: int}
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
