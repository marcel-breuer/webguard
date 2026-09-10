<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\NotificationChannelDelivery;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClearExistingMonitoringNotificationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_migration_removes_notifications_and_related_history(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'Monitoring is up',
        ]);
        NotificationChannelDelivery::query()->create([
            'user_id' => $user->id,
            'monitoring_notification_id' => $monitoringNotification->id,
            'channel' => 'mail',
            'event_type' => 'recovery',
            'status' => NotificationDeliveryStatus::SENT,
        ]);

        $migration = require base_path('database/migrations/2026_09_06_120000_clear_existing_monitoring_notifications.php');
        $migration->up();

        $this->assertDatabaseCount('monitoring_notifications', 0);
        $this->assertDatabaseCount('monitoring_notification_states', 0);
        $this->assertDatabaseCount('notification_channel_deliveries', 0);
    }
}
