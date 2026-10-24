<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Services\MonitoringCheckIntervalService;
use Tests\TestCase;

class MonitoringCheckIntervalServiceTest extends TestCase
{
    public function test_http_and_keyword_intervals_use_the_persisted_selection(): void
    {
        $service = resolve(MonitoringCheckIntervalService::class);

        foreach ([MonitoringType::HTTP, MonitoringType::KEYWORD] as $type) {
            foreach ([1, 5, 10, 15, 30, 60] as $minutes) {
                $monitoring = new Monitoring([
                    'type' => $type,
                    'check_interval_minutes' => $minutes,
                ]);

                $this->assertSame($minutes * 60, $service->secondsFor($monitoring));
            }
        }
    }

    public function test_http_and_keyword_fallback_to_fifteen_minutes_without_a_selection(): void
    {
        $service = resolve(MonitoringCheckIntervalService::class);

        foreach ([MonitoringType::HTTP, MonitoringType::KEYWORD] as $type) {
            $monitoring = new Monitoring(['type' => $type]);

            $this->assertSame(900, $service->secondsFor($monitoring));
        }
    }

    public function test_other_check_types_keep_the_configured_default_interval(): void
    {
        config(['monitoring.default_interval_minutes' => 7]);

        $monitoring = new Monitoring(['type' => MonitoringType::PING]);

        $this->assertSame(420, resolve(MonitoringCheckIntervalService::class)->secondsFor($monitoring));
    }
}
