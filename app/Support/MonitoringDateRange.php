<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

final readonly class MonitoringDateRange
{
    private function __construct(
        public int $days,
        public Carbon $startDate,
        public Carbon $endDate,
        private Carbon $resolvedAt
    ) {}

    public static function pastDays(int $days): self
    {
        $resolvedAt = Date::now();

        return new self(
            $days,
            $resolvedAt->copy()->subDays($days)->startOfDay(),
            $resolvedAt->copy()->endOfDay(),
            $resolvedAt
        );
    }

    public function isIntraday(): bool
    {
        return $this->days <= 1;
    }

    public function shouldUseUptimeAggregates(Monitoring $monitoring): bool
    {
        if ($this->isIntraday()) {
            return false;
        }

        return ($monitoring->created_at?->diffInDays($this->resolvedAt) ?? 0) >= 1;
    }

    public function shouldUseResponseTimeAggregates(): bool
    {
        return $this->days > 1;
    }

    public function shouldIncludeIntradayRawData(): bool
    {
        return $this->isIntraday();
    }

    public function cacheDateSegment(): string
    {
        return $this->startDate->format('Ymd') . ':' . $this->endDate->format('Ymd');
    }
}
