<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendPlannedMaintenanceNotification;
use App\Mail\PublicStatusPageMaintenanceScheduledMail;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageMaintenanceDelivery;
use App\Models\User;
use App\Services\PlannedMaintenanceNotificationService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendPlannedMaintenanceNotificationTest extends TestCase
{
    public function test_delivery_uses_the_saved_notice_and_does_not_resend_completed_mail(): void
    {
        Mail::fake();
        $delivery = $this->delivery();
        Monitoring::query()->whereKey($delivery->payload['monitorings'][0]['id'])->update(['name' => 'Renamed service']);
        $sendPlannedMaintenanceNotification = new SendPlannedMaintenanceNotification($delivery->id);

        $sendPlannedMaintenanceNotification->handle();
        $sendPlannedMaintenanceNotification->handle();

        Mail::assertSent(PublicStatusPageMaintenanceScheduledMail::class, function (PublicStatusPageMaintenanceScheduledMail $publicStatusPageMaintenanceScheduledMail): bool {
            return $publicStatusPageMaintenanceScheduledMail->monitorings->first()->name === 'Checkout API'
                && $publicStatusPageMaintenanceScheduledMail->startsAt->toIso8601String() === '2026-10-20T10:00:00+00:00'
                && $publicStatusPageMaintenanceScheduledMail->timezone === 'Europe/Berlin'
                && ! $publicStatusPageMaintenanceScheduledMail->recurring;
        });
        Mail::assertSent(PublicStatusPageMaintenanceScheduledMail::class, 1);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_transport_failure_leaves_a_retryable_notice_without_blocking_another_recipient(): void
    {
        Date::setTestNow('2026-10-18 10:00:00 UTC');
        $statusPageMaintenanceDelivery = $this->delivery();
        $second = $this->delivery();
        $mailManager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        (new SendPlannedMaintenanceNotification($statusPageMaintenanceDelivery->id))->handle();

        $this->assertNull($statusPageMaintenanceDelivery->fresh()->sent_at);
        $this->assertSame(1, $statusPageMaintenanceDelivery->fresh()->attempts);
        $this->assertTrue($statusPageMaintenanceDelivery->fresh()->available_at->equalTo(Date::now()->addMinute()));

        Mail::swap($mailManager);
        Mail::fake();
        (new SendPlannedMaintenanceNotification($second->id))->handle();
        (new SendPlannedMaintenanceNotification($statusPageMaintenanceDelivery->id))->handle();
        Mail::assertSent(PublicStatusPageMaintenanceScheduledMail::class, 1);

        $this->travel(61)->seconds();
        (new SendPlannedMaintenanceNotification($statusPageMaintenanceDelivery->id))->handle();
        Mail::assertSent(PublicStatusPageMaintenanceScheduledMail::class, 2);
        $this->assertSame(2, $statusPageMaintenanceDelivery->fresh()->attempts);
    }

    public function test_overlapping_job_cannot_send_the_same_notice(): void
    {
        $delivery = $this->delivery();
        $sendPlannedMaintenanceNotification = new SendPlannedMaintenanceNotification($delivery->id);
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($sendPlannedMaintenanceNotification) {
            $sendPlannedMaintenanceNotification->handle();
            $mock = Mockery::mock(PendingMail::class);
            $mock->shouldReceive('send')->once();

            return $mock;
        });

        $sendPlannedMaintenanceNotification->handle();

        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_delivery_stops_after_five_failed_attempts(): void
    {
        $delivery = $this->delivery();
        $delivery->update(['attempts' => 4]);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $sendPlannedMaintenanceNotification = new SendPlannedMaintenanceNotification($delivery->id);
        $sendPlannedMaintenanceNotification->handle();
        $this->travel(2)->hours();
        $sendPlannedMaintenanceNotification->handle();

        $this->assertSame(5, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    public function test_ineligible_subscribers_and_private_pages_are_skipped(): void
    {
        Mail::fake();
        $statusPageMaintenanceDelivery = $this->delivery();
        $statusPageMaintenanceDelivery->subscription->update(['verified_at' => null]);
        $private = $this->delivery();
        $private->subscription->statusPage->update(['is_public' => false]);
        $unsubscribed = $this->delivery();
        $unsubscribed->subscription->delete();

        foreach ([$statusPageMaintenanceDelivery, $private, $unsubscribed] as $delivery) {
            (new SendPlannedMaintenanceNotification($delivery->id))->handle();
        }

        Mail::assertNothingSent();
        $this->assertNotNull($statusPageMaintenanceDelivery->fresh()->cancelled_at);
        $this->assertNotNull($private->fresh()->cancelled_at);
        $this->assertDatabaseMissing('status_page_maintenance_deliveries', ['id' => $unsubscribed->id]);
    }

    private function delivery(): StatusPageMaintenanceDelivery
    {
        $user = User::factory()->create(['package_id' => Package::factory()->create()->id]);
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $statusPageComponent = $statusPage->components()->create(['name' => 'Checkout', 'position' => 0]);
        $statusPageComponent->monitorings()->attach($monitoring->id, ['position' => 0]);
        $statusPageSubscription = $statusPage->subscriptions()->create([
            'email' => $user->email,
            'unsubscribe_token' => 'unsubscribe-' . $user->id,
            'verified_at' => Date::now(),
        ]);
        resolve(PlannedMaintenanceNotificationService::class)->notifyForOneOff(
            collect([$monitoring]), Date::parse('2026-10-20T10:00:00Z'), Date::parse('2026-10-20T11:00:00Z'),
        );

        return StatusPageMaintenanceDelivery::query()->where('status_page_subscription_id', $statusPageSubscription->id)->firstOrFail();
    }
}
