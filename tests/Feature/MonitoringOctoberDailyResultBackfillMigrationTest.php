<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Tests\TestCase;

class MonitoringOctoberDailyResultBackfillMigrationTest extends TestCase
{
    public function test_it_copies_preceding_day_aggregates_for_the_requested_dates(): void
    {
        Package::factory()->create();
        $monitoring = Monitoring::factory()->for(User::factory()->create())->create();
        $sourceDates = ['2026-10-01', '2026-10-06', '2026-10-22'];

        foreach ($sourceDates as $index => $date) {
            $this->createDailyResult($monitoring, $date, $index + 1);
        }

        $this->runMigration();

        foreach ([
            ['2026-10-01', '2026-10-02'],
            ['2026-10-02', '2026-10-03'],
            ['2026-10-03', '2026-10-04'],
            ['2026-10-06', '2026-10-07'],
            ['2026-10-22', '2026-10-23'],
        ] as [$sourceDate, $targetDate]) {
            $this->assertDailyResultMatches($monitoring, $sourceDate, $targetDate);
        }

        $this->runMigration();

        $this->assertDatabaseCount('monitoring_daily_results', 8);
    }

    public function test_it_preserves_existing_target_rows_and_skips_days_without_source_rows(): void
    {
        Package::factory()->create();
        $monitoring = Monitoring::factory()->for(User::factory()->create())->create();
        $this->createDailyResult($monitoring, '2026-10-01', 1);
        $this->createDailyResult($monitoring, '2026-10-02', 9);

        $this->runMigration();

        $existingTarget = MonitoringDailyResult::query()
            ->where('monitoring_id', $monitoring->id)
            ->whereDate('date', '2026-10-02')
            ->firstOrFail();

        $this->assertSame(9, $existingTarget->uptime_total);
        $this->assertDailyResultMatches($monitoring, '2026-10-02', '2026-10-03');
        $this->assertDatabaseMissing('monitoring_daily_results', [
            'monitoring_id' => $monitoring->id,
            'date' => '2026-10-07',
        ]);
    }

    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_10_30_090000_backfill_missing_october_monitoring_daily_results.php');

        $migration->up();
    }

    private function createDailyResult(Monitoring $monitoring, string $date, int $value): MonitoringDailyResult
    {
        return MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => $date,
            'uptime_total' => $value,
            'downtime_total' => $value + 1,
            'unknown_total' => $value + 2,
            'uptime_percentage' => $value + 3.0,
            'downtime_percentage' => $value + 4.0,
            'unknown_percentage' => $value + 5.0,
            'uptime_minutes' => $value + 6,
            'downtime_minutes' => $value + 7,
            'unknown_minutes' => $value + 8,
            'avg_response_time' => $value + 9.0,
            'min_response_time' => $value + 10,
            'max_response_time' => $value + 11,
            'incidents_count' => $value + 12,
        ]);
    }

    private function assertDailyResultMatches(Monitoring $monitoring, string $sourceDate, string $targetDate): void
    {
        $columns = [
            'uptime_total',
            'downtime_total',
            'unknown_total',
            'uptime_percentage',
            'downtime_percentage',
            'unknown_percentage',
            'uptime_minutes',
            'downtime_minutes',
            'unknown_minutes',
            'avg_response_time',
            'min_response_time',
            'max_response_time',
            'incidents_count',
        ];
        $source = MonitoringDailyResult::query()
            ->where('monitoring_id', $monitoring->id)
            ->whereDate('date', $sourceDate)
            ->firstOrFail();
        $target = MonitoringDailyResult::query()
            ->where('monitoring_id', $monitoring->id)
            ->whereDate('date', $targetDate)
            ->firstOrFail();

        $this->assertSame($source->only($columns), $target->only($columns));
    }
}
