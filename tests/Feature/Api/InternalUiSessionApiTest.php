<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

class InternalUiSessionApiTest extends TestCase
{
    use AssertsApiContracts;
    use RefreshDatabase;

    public function test_guest_cannot_access_the_first_party_session_contract(): void
    {
        $this->getJson(route('app.session.show'))->assertUnauthorized();
        $this->postJson(route('app.session.destroy'))->assertUnauthorized();
        $this->patchJson(route('app.appearance.update'), ['theme' => 'dark'])->assertUnauthorized();
        $this->patchJson(route('app.locale.update'), ['locale' => 'de'])->assertUnauthorized();
    }

    public function test_authenticated_user_can_bootstrap_their_own_session_context(): void
    {
        Package::factory()->create();
        $user = User::factory()->create([
            'locale' => 'de',
            'theme' => 'dark',
            'role' => UserRole::ADMIN,
        ]);
        $team = Team::factory()->create();
        TeamMembership::factory()->for($team)->for($user)->admin()->create();
        $otherTeam = Team::factory()->create();
        TeamMembership::factory()->for($otherTeam)->for(User::factory())->create();

        $testResponse = $this->actingAs($user)->getJson(route('app.session.show'));

        $testResponse
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', UserRole::ADMIN->value)
            ->assertJsonPath('data.user.locale', 'de')
            ->assertJsonPath('data.user.theme', 'dark')
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonPath('data.teams.0.id', $team->id)
            ->assertJsonPath('data.teams.0.name', $team->name)
            ->assertJsonPath('data.teams.0.role', 'admin')
            ->assertJsonPath('data.csrf_endpoint', '/sanctum/csrf-cookie')
            ->assertJsonMissing(['id' => $otherTeam->id]);

        $this->assertInternalUiTelemetry($testResponse, 3, 131072);
    }

    public function test_unverified_member_can_bootstrap_and_update_their_appearance_without_accessing_verified_projections(): void
    {
        Package::factory()->create();
        $user = User::factory()->unverified()->create(['theme' => 'light']);

        $this->actingAs($user)
            ->getJson(route('app.session.show'))
            ->assertOk()
            ->assertJsonPath('data.user.is_verified', false)
            ->assertJsonPath('data.user.email_verified_at', null);

        $this->actingAs($user)
            ->getJson(route('app.dashboard'))
            ->assertForbidden();

        $testResponse = $this->actingAs($user)
            ->patchJson(route('app.appearance.update'), ['theme' => 'system']);

        $testResponse
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.theme', 'system');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'theme' => 'system',
        ]);
    }

    public function test_appearance_contract_validates_the_theme_and_rejects_demo_users(): void
    {
        Package::factory()->create();
        $member = User::factory()->create();
        $demoUser = User::factory()->create(['role' => UserRole::DEMO]);

        $this->actingAs($member)
            ->patchJson(route('app.appearance.update'), ['theme' => 'neon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');

        $this->actingAs($demoUser)
            ->patchJson(route('app.appearance.update'), ['theme' => 'dark'])
            ->assertForbidden();
    }

    public function test_authenticated_user_can_update_their_locale_for_the_sveltekit_sidebar(): void
    {
        Package::factory()->create();
        $user = User::factory()->create(['locale' => 'en']);

        $testResponse = $this->actingAs($user)
            ->patchJson(route('app.locale.update'), ['locale' => 'de']);

        $testResponse
            ->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertPlainCookie('webguard_locale', 'de');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'de',
        ]);
    }

    public function test_authenticated_user_can_end_their_first_party_session(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('app.session.destroy'))
            ->assertOk()
            ->assertJsonPath('data.authenticated', false);

        $this->getJson(route('app.session.show'))
            ->assertUnauthorized();
    }
}
