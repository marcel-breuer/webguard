<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageMaintenanceDelivery;
use App\Models\User;
use App\Services\MobileMaintenanceWorkspaceService;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Tests\TestCase;

class PlannedMaintenancePersistenceTest extends TestCase
{
    public function test_notice_persistence_failure_rolls_back_the_schedule_and_idempotency_record(): void
    {
        $monitoring = $this->subscribedMonitoring();
        StatusPageMaintenanceDelivery::creating(fn () => throw new RuntimeException('Notice persistence failed'));

        try {
            resolve(MobileMaintenanceWorkspaceService::class)->schedule($monitoring->user, [
                'mode' => 'one_off',
                'scope' => 'monitoring',
                'monitoring_id' => $monitoring->id,
                'maintenance_from' => '2026-10-20T10:00:00Z',
                'idempotency_key' => 'rollback',
            ]);
            $this->fail('Expected notice persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notice persistence failed', $exception->getMessage());
        }

        $this->assertNull($monitoring->fresh()->maintenance_from);
        $this->assertDatabaseCount('mobile_maintenance_operations', 0);
        $this->assertDatabaseCount('status_page_maintenance_deliveries', 0);
    }

    public function test_recurring_notices_survive_request_replay_and_reenabling_is_atomic(): void
    {
        Date::setTestNow('2026-10-18 10:00:00 UTC');
        $monitoring = $this->subscribedMonitoring();
        $mobileMaintenanceWorkspaceService = resolve(MobileMaintenanceWorkspaceService::class);
        $attributes = [
            'mode' => 'recurring',
            'scope' => 'monitoring',
            'monitoring_id' => $monitoring->id,
            'recurring_starts_at' => '2026-10-20T10:00:00',
            'recurring_timezone' => 'Europe/Berlin',
            'recurring_duration_minutes' => 60,
            'recurrence' => 'weekly',
            'idempotency_key' => 'recurring-notice',
        ];

        $mobileMaintenanceWorkspaceService->schedule($monitoring->user, $attributes);
        $mobileMaintenanceWorkspaceService->schedule($monitoring->user, $attributes);

        $this->assertDatabaseCount('status_page_maintenance_deliveries', 1);
        $payload = StatusPageMaintenanceDelivery::query()->firstOrFail()->payload;
        $this->assertTrue($payload['recurring']);
        $this->assertSame('Europe/Berlin', $payload['timezone']);
        $maintenanceWindow = MaintenanceWindow::query()->firstOrFail();
        $this->assertTrue(Date::parse($payload['starts_at'])->equalTo($maintenanceWindow->starts_at));
        $mobileMaintenanceWorkspaceService->updateRecurringEnabled($maintenanceWindow, $monitoring->user, false);
        $this->travel(1)->minutes();
        StatusPageMaintenanceDelivery::creating(fn () => throw new RuntimeException('Notice persistence failed'));

        try {
            $mobileMaintenanceWorkspaceService->updateRecurringEnabled($maintenanceWindow->fresh(), $monitoring->user, true);
            $this->fail('Expected notice persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notice persistence failed', $exception->getMessage());
        }

        $this->assertFalse($maintenanceWindow->fresh()->enabled);
        $this->assertDatabaseCount('status_page_maintenance_deliveries', 1);
    }

    private function subscribedMonitoring(): Monitoring
    {
        $user = User::factory()->create(['package_id' => Package::factory()->create()->id]);
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create(['user_id' => $user->id, 'name' => 'Acme', 'is_public' => true]);
        $statusPageComponent = $statusPage->components()->create(['name' => 'Checkout', 'position' => 0]);
        $statusPageComponent->monitorings()->attach($monitoring->id, ['position' => 0]);
        $statusPage->subscriptions()->create([
            'email' => $user->email,
            'unsubscribe_token' => 'unsubscribe-' . $user->id,
            'verified_at' => Date::now(),
        ]);

        return $monitoring;
    }
}
