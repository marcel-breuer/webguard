<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringStatus;
use App\Mail\PublicStatusPageSubscriptionConfirmationMail;
use App\Mail\StatusPageSubscriptionConfirmationMail;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscriber;
use App\Models\StatusPageSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicStatusPayloadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_page_payload_contains_only_public_status_data(): void
    {
        Date::setTestNow('2026-08-23 10:00:00');
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Checkout API',
            'target' => 'https://internal.example.test/private-path',
        ]);
        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'created_at' => Date::now()->subMinutes(2),
            'updated_at' => Date::now()->subMinutes(2),
        ]);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'description' => 'Customer-facing service health.',
            'is_public' => true,
        ]);
        $statusPage->components()->create(['name' => 'Core services', 'position' => 0])
            ->monitorings()
            ->attach($monitoring->id, ['position' => 0]);
        Incident::query()->create([
            'monitoring_id' => $monitoring->id,
            'down_at' => Date::now()->subDay(),
            'up_at' => Date::now()->subHours(23),
        ]);

        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.kind', 'status_page')
            ->assertJsonPath('data.identifier', $statusPage->id)
            ->assertJsonPath('data.components.0.monitorings.0.name', 'Checkout API')
            ->assertJsonPath('data.incidents.0.monitoring_name', 'Checkout API')
            ->assertJsonMissing(['target' => 'https://internal.example.test/private-path']);

        $this->getJson(route('public.status.show', ['status' => 'acme-status']))
            ->assertOk()
            ->assertJsonPath('data.identifier', $statusPage->id);
    }

    public function test_public_status_page_calendar_includes_current_intraday_uptime(): void
    {
        Date::setTestNow('2026-08-23 12:00:00');
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create();

        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 200,
            'created_at' => Date::parse('2026-08-23 00:00:00'),
            'updated_at' => Date::parse('2026-08-23 00:00:00'),
        ]);
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN,
            'http_status_code' => 503,
            'created_at' => Date::parse('2026-08-23 09:00:00'),
            'updated_at' => Date::parse('2026-08-23 09:00:00'),
        ]);

        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $statusPage->components()->create(['name' => 'Core services', 'position' => 0])
            ->monitorings()
            ->attach($monitoring->id, ['position' => 0]);

        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertJsonPath('data.uptime_calendar.2026-08.days.22.uptime_percentage', 75);
    }

    public function test_public_status_page_payload_includes_only_an_active_announcement(): void
    {
        $user = $this->user();
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $announcement = StatusPageAnnouncement::query()->create([
            'status_page_id' => $statusPage->id,
            'title' => 'Scheduled account changes',
            'message' => 'Account changes will be unavailable for a short period.',
        ]);

        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertJsonPath('data.announcement.title', $announcement->title)
            ->assertJsonPath('data.announcement.message', $announcement->message)
            ->assertJsonMissing(['notify_subscribers' => false]);

        $announcement->update(['dismissed_at' => Date::now()]);
        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertJsonPath('data.announcement', null);
    }

    public function test_private_public_status_resources_are_not_exposed(): void
    {
        $user = $this->user();
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Private status',
            'is_public' => false,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create(['public_label_enabled' => false]);

        $this->getJson(route('public.status.show', $statusPage))->assertNotFound();
        $this->getJson(route('public.status.show', $monitoring))->assertNotFound();
    }

    public function test_public_monitoring_payload_keeps_label_details_and_hides_heartbeat_targets(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create([
            'name' => 'Public API',
            'public_label_enabled' => true,
            'target' => 'https://status.example.test/api',
        ]);
        $heartbeat = Monitoring::factory()->heartbeat()->for($user)->create([
            'public_label_enabled' => true,
        ]);

        $this->getJson(route('public.status.show', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.kind', 'monitoring')
            ->assertJsonPath('data.monitoring.target', 'https://status.example.test/api');

        $this->getJson(route('public.status.show', $heartbeat))
            ->assertOk()
            ->assertJsonPath('data.monitoring.target', null);
    }

    public function test_public_status_page_subscription_endpoint_sends_confirmation_without_authentication(): void
    {
        Mail::fake();
        $user = $this->user();
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);

        $this->postJson(route('public.status.subscribers.store', $statusPage), ['email' => 'Customer@Example.test'])->assertAccepted()
            ->assertJsonPath('data.message', 'Check your inbox to confirm your subscription.');

        $this->assertDatabaseHas('status_page_subscriptions', [
            'status_page_id' => $statusPage->id,
            'email' => 'customer@example.test',
        ]);
        Mail::assertSent(PublicStatusPageSubscriptionConfirmationMail::class);
    }

    public function test_public_monitoring_subscription_endpoint_sends_confirmation_without_authentication(): void
    {
        Mail::fake();
        $monitoring = Monitoring::factory()->for($this->user())->create(['public_label_enabled' => true]);

        $this->postJson(route('public.status.subscribers.store', $monitoring), ['email' => 'Customer@Example.test'])->assertAccepted()
            ->assertJsonPath('data.message', 'Check your inbox to confirm your subscription.');

        $this->assertDatabaseHas('status_page_subscribers', [
            'monitoring_id' => $monitoring->id,
            'email' => 'customer@example.test',
        ]);
        Mail::assertSent(StatusPageSubscriptionConfirmationMail::class);
    }

    public function test_public_status_page_confirmation_endpoint_verifies_a_pending_subscription(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => $this->user()->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'customer@example.test',
            'confirmation_token_hash' => StatusPageSubscription::hashToken('confirmation-token'),
            'unsubscribe_token' => 'unsubscribe-token',
        ]);

        $this->postJson(route('public.status.subscribers.confirm', [
            'status' => $statusPage,
            'token' => 'confirmation-token',
        ]))
            ->assertOk()
            ->assertJsonPath('data.is_public', true);

        $statusPageSubscription->refresh();
        $this->assertTrue($statusPageSubscription->isVerified());
        $this->assertNull($statusPageSubscription->confirmation_token_hash);
    }

    public function test_public_monitoring_confirmation_endpoint_verifies_a_pending_subscription(): void
    {
        $monitoring = Monitoring::factory()->for($this->user())->create(['public_label_enabled' => true]);
        $statusPageSubscriber = StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'customer@example.test',
            'confirmation_token_hash' => StatusPageSubscriber::hashToken('confirmation-token'),
            'unsubscribe_token' => 'unsubscribe-token',
        ]);

        $this->postJson(route('public.status.subscribers.confirm', [
            'status' => $monitoring,
            'token' => 'confirmation-token',
        ]))
            ->assertOk()
            ->assertJsonPath('data.is_public', true);

        $statusPageSubscriber->refresh();
        $this->assertTrue($statusPageSubscriber->isVerified());
        $this->assertNull($statusPageSubscriber->confirmation_token_hash);
    }

    public function test_public_confirmation_endpoint_does_not_expose_private_resources_or_accept_unknown_tokens(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => $this->user()->id,
            'name' => 'Private status',
            'is_public' => false,
        ]);
        $publicStatusPage = StatusPage::query()->create([
            'user_id' => $statusPage->user_id,
            'name' => 'Public status',
            'is_public' => true,
        ]);

        $this->postJson(route('public.status.subscribers.confirm', [
            'status' => $statusPage,
            'token' => 'confirmation-token',
        ]))->assertNotFound();

        $this->postJson(route('public.status.subscribers.confirm', [
            'status' => $publicStatusPage,
            'token' => 'confirmation-token',
        ]))->assertNotFound();
    }

    public function test_public_status_page_subscription_endpoint_removes_a_matching_subscription(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => $this->user()->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'customer@example.test',
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => Date::now(),
        ]);

        $this->deleteJson(route('public.status.subscribers.destroy', [
            'status' => $statusPage,
            'token' => $statusPageSubscription->unsubscribe_token,
        ]), ['email' => 'Customer@Example.test'])
            ->assertOk()
            ->assertJsonPath('data.is_public', true);

        $this->assertDatabaseMissing('status_page_subscriptions', ['id' => $statusPageSubscription->id]);
    }

    public function test_public_unsubscribe_endpoint_removes_a_subscription_after_the_resource_is_unpublished(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['public_label_enabled' => false]);
        $statusPageSubscriber = StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'customer@example.test',
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => Date::now(),
        ]);

        $this->deleteJson(route('public.status.subscribers.destroy', [
            'status' => $monitoring,
            'token' => $statusPageSubscriber->unsubscribe_token,
        ]), ['email' => $statusPageSubscriber->email])
            ->assertOk()
            ->assertJsonPath('data.is_public', false);

        $this->assertDatabaseMissing('status_page_subscribers', ['id' => $statusPageSubscriber->id]);
    }

    public function test_public_unsubscribe_endpoint_requires_the_email_bound_to_the_token(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => $this->user()->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'customer@example.test',
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => Date::now(),
        ]);

        $this->deleteJson(route('public.status.subscribers.destroy', [
            'status' => $statusPage,
            'token' => $statusPageSubscription->unsubscribe_token,
        ]), ['email' => 'other@example.test'])->assertNotFound();

        $this->assertDatabaseHas('status_page_subscriptions', ['id' => $statusPageSubscription->id]);
    }

    private function user(): User
    {
        return User::factory()->create(['package_id' => Package::factory()->create(['monitoring_limit' => 20])->id]);
    }
}
