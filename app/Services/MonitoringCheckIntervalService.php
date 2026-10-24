<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MonitoringType;
use App\Models\Monitoring;

final class MonitoringCheckIntervalService
{
    public const array WEBSITE_INTERVAL_OPTIONS_MINUTES = [1, 5, 10, 15, 30, 60];

    public const int DEFAULT_WEBSITE_INTERVAL_MINUTES = 15;

    public const int INSTANCE_POLL_INTERVAL_SECONDS = 60;

    public function secondsFor(Monitoring $monitoring): int
    {
        if (in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            return $this->websiteSeconds($monitoring->check_interval_minutes);
        }

        return $this->secondsForType($monitoring->type);
    }

    public function secondsForType(MonitoringType|string|null $type): int
    {
        if (is_string($type)) {
            $type = MonitoringType::tryFrom($type);
        }

        return match ($type) {
            MonitoringType::HTTP, MonitoringType::KEYWORD => $this->websiteSeconds(null),
            default => $this->defaultSeconds(),
        };
    }

    public function defaultSeconds(): int
    {
        return max(60, (int) config('monitoring.default_interval_minutes', 5) * 60);
    }

    private function websiteSeconds(?int $intervalMinutes): int
    {
        $intervalMinutes ??= (int) config('monitoring.website_interval_minutes', self::DEFAULT_WEBSITE_INTERVAL_MINUTES);

        return max(60, $intervalMinutes * 60);
    }
}
