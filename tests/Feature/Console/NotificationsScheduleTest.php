<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class NotificationsScheduleTest extends TestCase
{
    public function test_planned_maintenance_delivery_is_scheduled_every_minute_without_overlap(): void
    {
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:dispatch-planned-maintenance'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_unread_notifications_reminder_is_scheduled_daily_at_eight_am(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:remind-unread-weekly'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_heartbeat_evaluation_is_scheduled_every_minute_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'monitoring:evaluate-heartbeats'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_monitoring_digest_is_scheduled_daily_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:send-weekly-monitoring-digest'));

        $this->assertNotNull($event);
        $this->assertSame('30 8 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_server_instance_health_alerts_are_scheduled_every_five_minutes_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:send-server-instance-health-alerts'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_expiry_warnings_are_scheduled_daily_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:send-ssl-expiry-warnings'));

        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_demo_notification_pruning_is_scheduled_daily_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:prune-demo'));

        $this->assertNotNull($event);
        $this->assertSame('30 1 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_instance_callback_idempotency_pruning_is_scheduled_daily_without_overlap(): void
    {
        /** @var Event|null $event */
        $event = collect(resolve(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'instances:prune-callback-idempotency'));

        $this->assertNotNull($event);
        $this->assertSame('45 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
