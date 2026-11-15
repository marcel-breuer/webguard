<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Monitoring;
use App\Models\Package;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Tests\TestCase;

class InternalUiMonitoringSearchApiTest extends TestCase
{
    public function test_search_requires_a_verified_session(): void
    {
        $this->getJson(route('app.monitorings.search', ['search' => 'site']))->assertUnauthorized();
        Package::factory()->create();
        $this->actingAs(User::factory()->unverified()->create())
            ->getJson(route('app.monitorings.search', ['search' => 'site']))->assertForbidden();
    }

    public function test_search_matches_names_and_targets_only_within_the_users_visibility(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        TeamMembership::factory()->for($team)->for($user)->create();
        $byName = Monitoring::factory()->for($user)->create(['name' => 'Needle API']);
        $byTarget = Monitoring::factory()->for($team)->create(['name' => 'Team site', 'target' => 'https://needle.example.test']);
        Monitoring::factory()->create(['name' => 'Needle private']);
        Monitoring::factory()->for($user)->create(['name' => 'Deleted Needle', 'deleted_at' => now()]);
        Monitoring::factory()->for($user)->create(['name' => 'Other', 'target' => 'https://other.example.test']);

        $response = $this->actingAs($user)->getJson(route('app.monitorings.search', ['search' => 'NEEDLE']));

        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $byName->id)
            ->assertJsonPath('data.1.id', $byTarget->id);
        $this->assertSame(['id', 'name', 'target'], array_keys($response->json('data.0')));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_search_is_bounded_and_treats_like_wildcards_as_literal_characters(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        Monitoring::factory()->count(15)->for($user)->create(['name' => 'Matching site']);
        $literal = Monitoring::factory()->for($user)->create(['name' => 'Special 50%_! site']);

        $this->actingAs($user)->getJson(route('app.monitorings.search', ['search' => 'Matching']))
            ->assertOk()->assertJsonCount(10, 'data');
        $this->getJson(route('app.monitorings.search', ['search' => '50%_!']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson(route('app.monitorings.search', ['search' => 'missing']))
            ->assertOk()->assertExactJson(['data' => []]);

        foreach ([null, '', 'a', str_repeat('a', 121), ['invalid']] as $search) {
            $this->getJson(route('app.monitorings.search', ['search' => $search]))
                ->assertUnprocessable()->assertJsonValidationErrors('search');
        }
    }
}
