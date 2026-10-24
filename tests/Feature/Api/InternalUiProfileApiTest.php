<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

final class InternalUiProfileApiTest extends TestCase
{
    use AssertsApiContracts;
    use RefreshDatabase;

    public function test_authenticated_member_can_update_their_account_without_exposing_notification_configuration(): void
    {
        Package::factory()->create();
        $user = User::factory()->create(['notification_channels' => ['telegram' => ['bot_token' => 'secret']]]);

        $testResponse = $this->actingAs($user)->patchJson(route('app.profile.update'), [
            'name' => 'Updated member',
            'email' => 'updated@example.test',
        ]);

        $testResponse
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated member')
            ->assertJsonPath('data.email', 'updated@example.test')
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonMissing(['bot_token' => 'secret']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated member', 'email' => 'updated@example.test', 'email_verified_at' => null]);
        $this->assertInternalUiTelemetry($testResponse, 4, 131072);
    }

    public function test_profile_contract_requires_authentication_and_valid_data(): void
    {
        $this->patchJson(route('app.profile.update'), [])->assertUnauthorized();

        Package::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('app.profile.update'), ['name' => '', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_authenticated_member_can_save_and_clear_language_and_format_preferences(): void
    {
        Package::factory()->create();
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->patchJson(route('app.profile.preferences.update'), [
                'locale' => 'de',
                'format_locale' => 'en-GB',
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertJsonPath('data.format_locale', 'en-GB')
            ->assertPlainCookie('webguard_locale', 'de');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'de',
            'format_locale' => 'en-GB',
        ]);

        $this->actingAs($user)
            ->patchJson(route('app.profile.preferences.update'), [
                'locale' => 'de',
                'format_locale' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.format_locale', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'de',
            'format_locale' => null,
        ]);
    }

    public function test_profile_preferences_validate_supported_values_and_reject_demo_users(): void
    {
        Package::factory()->create();
        $member = User::factory()->create();
        $demoUser = User::factory()->create(['role' => UserRole::DEMO]);

        $this->patchJson(route('app.profile.preferences.update'), [
            'locale' => 'de',
            'format_locale' => 'en-US',
        ])->assertUnauthorized();

        $this->actingAs($member)
            ->patchJson(route('app.profile.preferences.update'), [
                'locale' => 'fr',
                'format_locale' => 'fr-FR',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale', 'format_locale']);

        $this->actingAs($demoUser)
            ->patchJson(route('app.profile.preferences.update'), [
                'locale' => 'en',
                'format_locale' => null,
            ])
            ->assertForbidden();
    }
}
