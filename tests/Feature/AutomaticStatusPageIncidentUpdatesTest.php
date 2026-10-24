<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentUpdateStatus;
use App\Jobs\SendStatusPageIncidentUpdateNotifications;
use App\Mail\PublicStatusPageIncidentUpdateMail;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Models\User;
use App\Services\StatusPageIncidentUpdatePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomaticStatusPageIncidentUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_mapped_incidents_publish_neutral_updates_and_recovery_updates(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $this->statusPageFor($user, $monitoring, true);

        $incident = Incident::query()->create([
            'monitoring_id' => $monitoring->id,
            'problem_description' => 'private diagnostic detail',
            'down_at' => now(),
        ]);

        $incidentUpdate = IncidentUpdate::query()->where('incident_id', $incident->id)->firstOrFail();
        $this->assertSame(IncidentUpdateStatus::INVESTIGATING, $incidentUpdate->status);
        $this->assertSame('We are investigating an issue affecting Checkout API.', $incidentUpdate->message);
        $this->assertStringNotContainsString('private diagnostic detail', $incidentUpdate->message);
        $this->assertDatabaseHas('incident_timeline_events', [
            'incident_id' => $incident->id,
            'mobile_idempotency_key' => 'webguard-auto-status-open',
        ]);
        resolve(StatusPageIncidentUpdatePublisher::class)->publishInvestigating($incident);
        $this->assertDatabaseCount('incident_updates', 1);
        $this->assertDatabaseCount('incident_timeline_events', 1);

        $incident->update(['up_at' => now()]);
        $incidentUpdate->refresh();
        $this->assertSame(IncidentUpdateStatus::RESOLVED, $incidentUpdate->status);
        $this->assertSame('The incident affecting Checkout API has been resolved.', $incidentUpdate->message);
        $this->assertDatabaseHas('incident_timeline_events', [
            'incident_id' => $incident->id,
            'mobile_idempotency_key' => 'webguard-auto-status-resolved',
        ]);
    }

    public function test_private_or_non_opted_in_status_pages_do_not_publish_updates(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create();
        $this->statusPageFor($user, $monitoring, true, false);
        $this->statusPageFor($user, $monitoring, false, true);

        $incident = Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => now()]);

        $this->assertDatabaseMissing('incident_updates', ['incident_id' => $incident->id]);
    }

    public function test_automatic_update_emails_are_sent_only_to_verified_subscribers(): void
    {
        Mail::fake();
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create();
        $statusPage = $this->statusPageFor($user, $monitoring, true);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'verified@example.test',
            'unsubscribe_token' => 'verified-unsubscribe-token',
            'verified_at' => now(),
        ]);
        StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'pending@example.test',
            'unsubscribe_token' => 'pending-unsubscribe-token',
        ]);
        $incident = Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => now()]);
        $incidentUpdate = IncidentUpdate::query()->where('incident_id', $incident->id)->firstOrFail();

        (new SendStatusPageIncidentUpdateNotifications($statusPage->id, $incidentUpdate->id, 'investigating'))->handle();
        (new SendStatusPageIncidentUpdateNotifications($statusPage->id, $incidentUpdate->id, 'investigating'))->handle();

        Mail::assertSent(PublicStatusPageIncidentUpdateMail::class, fn (PublicStatusPageIncidentUpdateMail $publicStatusPageIncidentUpdateMail): bool => $publicStatusPageIncidentUpdateMail->hasTo($statusPageSubscription->email));
        Mail::assertNotSent(PublicStatusPageIncidentUpdateMail::class, fn (PublicStatusPageIncidentUpdateMail $publicStatusPageIncidentUpdateMail): bool => $publicStatusPageIncidentUpdateMail->hasTo('pending@example.test'));
        Mail::assertSentCount(1);
    }

    public function test_owner_can_edit_and_suppress_an_automatic_public_update(): void
    {
        Mail::fake();
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create();
        $statusPage = $this->statusPageFor($user, $monitoring, true);
        $incident = Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => now()]);
        $incidentUpdate = IncidentUpdate::query()->where('incident_id', $incident->id)->firstOrFail();
        $base = '/api/status-pages/' . $statusPage->id . '/incidents/' . $incident->id . '/updates/' . $incidentUpdate->id;

        $this->actingAs($user)
            ->patchJson($base, [
                'status' => IncidentUpdateStatus::IDENTIFIED->value,
                'message' => 'We identified the affected dependency.',
            ])
            ->assertOk()
            ->assertJsonPath('data.updates.0.message', 'We identified the affected dependency.');

        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertJsonPath('data.incidents.0.updates.0.message', 'We identified the affected dependency.');

        $this->actingAs($user)->deleteJson($base)->assertNoContent();
        $this->getJson(route('public.status.show', $statusPage))
            ->assertOk()
            ->assertJsonPath('data.incidents.0.updates', []);

        $this->assertNotNull($incidentUpdate->refresh()->suppressed_at);
        $this->assertDatabaseHas('incident_timeline_events', [
            'incident_id' => $incident->id,
            'title' => 'Public incident update suppressed',
            'source_type' => 'status_page',
        ]);
        (new SendStatusPageIncidentUpdateNotifications($statusPage->id, $incidentUpdate->id, 'investigating'))->handle();
        Mail::assertNotSent(PublicStatusPageIncidentUpdateMail::class);
    }

    private function statusPageFor(User $user, Monitoring $monitoring, bool $isPublic, bool $autoPublish = true): StatusPage
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Service status',
            'is_public' => $isPublic,
            'auto_publish_incident_updates' => $autoPublish,
        ]);
        $statusPageComponent = $statusPage->components()->create([
            'name' => 'Checkout',
            'position' => 0,
            'source_type' => 'manual',
        ]);
        $statusPageComponent->monitorings()->attach($monitoring, ['position' => 0]);

        return $statusPage;
    }

    private function user(): User
    {
        Package::factory()->create();

        return User::factory()->create();
    }
}
