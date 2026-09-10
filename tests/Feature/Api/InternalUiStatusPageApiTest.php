<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\IncidentUpdateStatus;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class InternalUiStatusPageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_ui_status_page_workspace_requires_an_authenticated_owner(): void
    {
        $this->getJson(route('app.status-pages.index'))->assertUnauthorized();

        $user = $this->user();
        $statusPage = StatusPage::query()->create(['user_id' => $user->id, 'name' => 'Owner page', 'is_public' => true]);
        $otherUser = $this->user();

        $this->actingAs($otherUser)
            ->getJson(route('app.status-pages.show', $statusPage))
            ->assertNotFound();
    }

    public function test_owner_can_create_update_and_delete_a_status_page_with_visible_monitorings(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $otherMonitoring = Monitoring::factory()->for($this->user())->create();

        $this->actingAs($user)
            ->getJson(route('app.status-pages.options'))
            ->assertOk()
            ->assertJsonPath('data.monitorings.0.id', $monitoring->id);

        $testResponse = $this->actingAs($user)
            ->postJson(route('app.status-pages.store'), [
                'name' => 'Acme Status',
                'description' => 'Current service availability.',
                'is_public' => true,
                'components' => [[
                    'name' => 'Checkout',
                    'description' => 'Payments and checkout.',
                    'source_type' => 'manual',
                    'monitoring_ids' => [$monitoring->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Status')
            ->assertJsonPath('data.components.0.monitorings.0.id', $monitoring->id);

        $statusPageId = $testResponse->json('data.id');
        $this->assertDatabaseHas('status_pages', ['id' => $statusPageId, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson(route('app.status-pages.update', $statusPageId), [
                'name' => 'Acme Service Status',
                'description' => null,
                'is_public' => false,
                'components' => [[
                    'name' => 'Checkout',
                    'source_type' => 'manual',
                    'monitoring_ids' => [$monitoring->id],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Service Status')
            ->assertJsonPath('data.publication.is_public', false);

        $this->actingAs($user)
            ->postJson(route('app.status-pages.store'), [
                'name' => 'Invalid page',
                'is_public' => true,
                'components' => [[
                    'name' => 'Private service',
                    'source_type' => 'manual',
                    'monitoring_ids' => [$otherMonitoring->id],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('components.0.monitoring_ids.0');

        $this->actingAs($user)
            ->deleteJson(route('app.status-pages.destroy', $statusPageId))
            ->assertNoContent();
        $this->assertDatabaseMissing('status_pages', ['id' => $statusPageId]);
    }

    public function test_owner_can_create_a_status_page_component_from_an_owned_monitoring_group(): void
    {
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $group = MonitoringGroup::factory()->for($user)->create(['name' => 'Checkout services']);
        $group->monitorings()->attach($monitoring);
        $foreignGroup = MonitoringGroup::factory()->for($this->user())->create();

        $this->actingAs($user)
            ->getJson(route('app.status-pages.options'))
            ->assertOk()
            ->assertJsonPath('data.monitoring_groups.0.id', $group->id)
            ->assertJsonPath('data.monitoring_groups.0.monitorings_count', 1)
            ->assertJsonMissing(['id' => $foreignGroup->id]);

        $testResponse = $this->actingAs($user)
            ->postJson(route('app.status-pages.store'), [
                'name' => 'Acme Status',
                'is_public' => true,
                'components' => [[
                    'name' => 'Checkout',
                    'source_type' => 'monitoring_group',
                    'monitoring_group_id' => $group->id,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.components.0.source_type', 'monitoring_group')
            ->assertJsonPath('data.components.0.monitoring_group.id', $group->id)
            ->assertJsonPath('data.components.0.monitorings.0.id', $monitoring->id);

        $componentId = $testResponse->json('data.components.0.id');
        $this->assertDatabaseHas('status_page_components', [
            'id' => $componentId,
            'monitoring_group_id' => $group->id,
            'source_type' => 'monitoring_group',
        ]);
        $this->assertDatabaseCount('status_page_component_monitoring', 0);

        $this->actingAs($user)
            ->postJson(route('app.status-pages.store'), [
                'name' => 'Invalid page',
                'is_public' => true,
                'components' => [[
                    'name' => 'Foreign group',
                    'source_type' => 'monitoring_group',
                    'monitoring_group_id' => $foreignGroup->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('components.0.monitoring_group_id');
    }

    public function test_owner_can_publish_an_idempotent_incident_update_for_a_status_page(): void
    {
        Date::setTestNow('2026-08-22 10:00:00');
        $user = $this->user();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create(['user_id' => $user->id, 'name' => 'Acme Status', 'is_public' => true]);
        $statusPage->components()->create(['name' => 'Checkout', 'position' => 0])->monitorings()->attach($monitoring->id, ['position' => 0]);
        $incident = Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => Date::now()->subMinutes(15)]);

        $base = '/api/status-pages/' . $statusPage->id . '/incidents/' . $incident->id;
        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'status-page-update-001'])
            ->postJson($base . '/updates', [
                'status' => IncidentUpdateStatus::INVESTIGATING->value,
                'message' => 'The team is investigating.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.updates.0.message', 'The team is investigating.');

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'status-page-update-001'])
            ->postJson($base . '/updates', [
                'status' => IncidentUpdateStatus::INVESTIGATING->value,
                'message' => 'The team is investigating.',
            ])
            ->assertOk();

        $this->assertDatabaseCount('incident_updates', 1);
    }

    private function user(): User
    {
        return User::factory()->create(['package_id' => Package::factory()->create(['monitoring_limit' => 20])->id]);
    }
}
