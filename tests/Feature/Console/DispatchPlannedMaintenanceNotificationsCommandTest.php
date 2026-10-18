<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SendPlannedMaintenanceNotification;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageMaintenanceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Tests\TestCase;

class DispatchPlannedMaintenanceNotificationsCommandTest extends TestCase
{
    public function test_dispatch_recovers_lost_messages_and_does_not_repeat_recent_dispatches(): void
    {
        Bus::fake();
        $delivery = $this->delivery();

        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();
        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();
        Bus::assertDispatched(SendPlannedMaintenanceNotification::class, 1);
        Bus::assertDispatched(fn (SendPlannedMaintenanceNotification $sendPlannedMaintenanceNotification): bool => $sendPlannedMaintenanceNotification->deliveryId === $delivery->id && $sendPlannedMaintenanceNotification->queue === 'default');

        $this->travel(6)->minutes();
        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();
        Bus::assertDispatched(SendPlannedMaintenanceNotification::class, 2);
    }

    public function test_queue_failure_preserves_the_notice_and_does_not_block_later_recipients(): void
    {
        $statusPageMaintenanceDelivery = $this->delivery();
        $second = $this->delivery();
        Bus::shouldReceive('dispatch')->once()->ordered()->andThrow(new RuntimeException('Queue unavailable'));
        Bus::shouldReceive('dispatch')->once()->ordered()->andReturnNull();

        $this->artisan('notifications:dispatch-planned-maintenance')->assertFailed();

        $this->assertNull($statusPageMaintenanceDelivery->fresh()->queued_at);
        $this->assertNotNull($second->fresh()->queued_at);
        $this->assertSame(0, $statusPageMaintenanceDelivery->fresh()->attempts);
        Bus::fake();
        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();
        Bus::assertDispatched(SendPlannedMaintenanceNotification::class, 1);
    }

    public function test_dispatch_ignores_sent_cancelled_exhausted_deferred_and_legacy_notices(): void
    {
        Bus::fake();
        $this->delivery()->update(['sent_at' => Date::now()]);
        $this->delivery()->update(['cancelled_at' => Date::now()]);
        $this->delivery()->update(['attempts' => 5]);
        $this->delivery()->update(['available_at' => Date::now()->addHour()]);
        $this->delivery()->update(['payload' => null]);

        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_expired_worker_claim_is_recovered(): void
    {
        Bus::fake();
        $delivery = $this->delivery();
        $delivery->update([
            'attempts' => 1,
            'available_at' => Date::now()->subMinute(),
            'queued_at' => Date::now()->subMinutes(6),
        ]);

        $this->artisan('notifications:dispatch-planned-maintenance')->assertSuccessful();

        Bus::assertDispatched(SendPlannedMaintenanceNotification::class, 1);
    }

    private function delivery(): StatusPageMaintenanceDelivery
    {
        $user = User::factory()->create(['package_id' => Package::factory()->create()->id]);
        $statusPage = StatusPage::query()->create(['user_id' => $user->id, 'name' => 'Acme', 'is_public' => true]);
        $statusPageSubscription = $statusPage->subscriptions()->create([
            'email' => $user->email,
            'unsubscribe_token' => 'unsubscribe-' . $user->id,
            'verified_at' => Date::now(),
        ]);

        return StatusPageMaintenanceDelivery::query()->create([
            'status_page_subscription_id' => $statusPageSubscription->id,
            'fingerprint' => hash('sha256', $statusPageSubscription->id),
            'payload' => [
                'monitorings' => [['id' => 'checkout', 'name' => 'Checkout']],
                'starts_at' => '2026-10-20T10:00:00+00:00',
                'ends_at' => null,
                'timezone' => 'UTC',
                'recurring' => false,
            ],
        ]);
    }
}
