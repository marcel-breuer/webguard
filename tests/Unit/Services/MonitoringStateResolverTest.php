<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use App\Services\MonitoringStateResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class MonitoringStateResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_website_monitorings_become_unknown_after_three_fifteen_minute_intervals(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $monitoring = $this->createMonitoring(MonitoringType::HTTP);
        $this->createResponse($monitoring, '2026-04-12 11:14:00');

        $this->assertSame(MonitoringStatus::UNKNOWN->value, resolve(MonitoringStateResolver::class)->status($monitoring->fresh()));
    }

    public function test_website_stale_threshold_tracks_every_supported_interval(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');
        $monitoringStateResolver = resolve(MonitoringStateResolver::class);

        foreach ([1, 5, 10, 15, 30, 60] as $intervalMinutes) {
            $staleAfterSeconds = max(10, $intervalMinutes * 3) * 60;
            $staleMonitoring = $this->createMonitoring(MonitoringType::HTTP, ['check_interval_minutes' => $intervalMinutes]);
            $this->createResponse($staleMonitoring, Date::now()->copy()->subSeconds($staleAfterSeconds + 1));

            $this->assertSame(
                MonitoringStatus::UNKNOWN->value,
                $monitoringStateResolver->status($staleMonitoring->fresh()),
                "A {$intervalMinutes}-minute monitoring should be stale after its threshold."
            );

            $currentMonitoring = $this->createMonitoring(MonitoringType::HTTP, ['check_interval_minutes' => $intervalMinutes]);
            $this->createResponse($currentMonitoring, Date::now()->copy()->subSeconds($staleAfterSeconds - 1));

            $this->assertSame(
                MonitoringStatus::UP->value,
                $monitoringStateResolver->status($currentMonitoring->fresh()),
                "A {$intervalMinutes}-minute monitoring should remain current before its threshold."
            );
        }
    }

    public function test_non_website_monitorings_keep_the_existing_stale_threshold(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        $monitoring = $this->createMonitoring(MonitoringType::PING);
        $this->createResponse($monitoring, '2026-04-12 11:44:00');

        $this->assertSame(MonitoringStatus::UNKNOWN->value, resolve(MonitoringStateResolver::class)->status($monitoring->fresh()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMonitoring(MonitoringType $monitoringType, array $attributes = []): Monitoring
    {
        Package::factory()->create();
        $user = User::factory()->create();

        return Monitoring::factory()->for($user)->create(array_merge(['type' => $monitoringType], $attributes));
    }

    private function createResponse(Monitoring $monitoring, CarbonInterface|string $createdAt): void
    {
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 200,
            'response_time' => 120.0,
            'created_at' => Date::parse($createdAt),
            'updated_at' => Date::parse($createdAt),
        ]);
    }
}
