<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MonitoringMaintenanceScheduleTest extends TestCase
{
    public function test_daily_aggregation_re_aggregates_recent_days_in_the_background(): void
    {
        $event = $this->scheduledEvent('monitoring:aggregate-daily');

        $this->assertStringEndsWith('monitoring:aggregate-daily 3', (string) $event->command);
        $this->assertSame('30 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
    }

    public function test_weekly_response_archiving_runs_in_the_background(): void
    {
        $event = $this->scheduledEvent('monitoring:archive-responses');

        $this->assertSame('0 0 * * 0', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
    }

    public function test_scheduler_container_uses_schedule_work(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertIsString($compose);
        $this->assertStringContainsString('"schedule:work"', $compose);
        $this->assertStringNotContainsString('sleep 60', $compose);
    }

    private function scheduledEvent(string $command): Event
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

        $this->assertNotNull($event);

        return $event;
    }
}
