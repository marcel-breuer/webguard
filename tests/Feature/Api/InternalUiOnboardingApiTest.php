<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\UserRole;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class InternalUiOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_guest_cannot_update_onboarding(): void
    {
        $this->patchJson(route('app.onboarding.update'), ['action' => 'dismiss'])
            ->assertUnauthorized();
    }

    public function test_user_cannot_complete_onboarding_without_an_active_monitoring_and_successful_test(): void
    {
        $user = User::factory()->create([
            'first_run_notification_tested_at' => now(),
        ]);
        $otherUser = User::factory()->create();
        Monitoring::factory()->for($otherUser)->create(['status' => MonitoringLifecycleStatus::ACTIVE]);

        $this->actingAs($user)
            ->patchJson(route('app.onboarding.update'), ['action' => 'complete'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        Monitoring::factory()->for($user)->create(['status' => MonitoringLifecycleStatus::PAUSED]);

        $userWithoutTest = User::factory()->create();
        Monitoring::factory()->for($userWithoutTest)->create(['status' => MonitoringLifecycleStatus::ACTIVE]);

        $this->actingAs($userWithoutTest)
            ->patchJson(route('app.onboarding.update'), ['action' => 'complete'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertNull($user->fresh()->first_run_setup_completed_at);
        $this->assertNull($userWithoutTest->fresh()->first_run_setup_completed_at);
    }

    public function test_regular_user_can_complete_onboarding_after_an_active_check_and_successful_test(): void
    {
        Date::setTestNow('2026-10-29 12:00:00');
        $user = User::factory()->create([
            'first_run_notification_tested_at' => Date::now()->subMinute(),
        ]);
        Monitoring::factory()->for($user)->create(['status' => MonitoringLifecycleStatus::ACTIVE]);

        $this->actingAs($user)
            ->patchJson(route('app.onboarding.update'), ['action' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', Date::now()->toIso8601String())
            ->assertJsonPath('data.dismissed_at', null);

        $this->actingAs($user)
            ->getJson(route('app.session.show'))
            ->assertOk()
            ->assertJsonPath('data.user.onboarding.completed_at', Date::now()->toIso8601String())
            ->assertJsonPath('data.user.onboarding.notification_tested_at', Date::now()->subMinute()->toIso8601String());

        $this->assertNotNull($user->fresh()->first_run_setup_completed_at);
    }

    public function test_regular_user_can_dismiss_onboarding_and_demo_user_cannot_update_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('app.onboarding.update'), ['action' => 'dismiss'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.dismissed_at', fn (?string $value): bool => $value !== null);

        $demoUser = User::factory()->create(['role' => UserRole::DEMO]);

        $this->actingAs($demoUser)
            ->patchJson(route('app.onboarding.update'), ['action' => 'dismiss'])
            ->assertForbidden();
    }

    public function test_onboarding_action_must_be_supported(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('app.onboarding.update'), ['action' => 'skip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }
}
