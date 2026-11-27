<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiKeyAbility;
use App\Http\Middleware\PreventRequestForgeryExceptBearer;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\TestCase;

class ManagementApiKeyScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create(['monitoring_limit' => 10]);
    }

    public function test_management_read_key_reads_but_cannot_write(): void
    {
        $user = User::factory()->create();
        Monitoring::factory()->for($user)->create(['name' => 'Scoped read']);
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);

        $this->withToken($token)->getJson('/api/monitorings')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Scoped read');
        $this->withToken($token)->getJson('/api/status-pages')->assertOk();
        $this->withToken($token)->postJson('/api/monitoring-groups', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_management_write_key_writes_but_cannot_read(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_WRITE]);

        $this->withToken($token)->postJson('/api/monitoring-groups', ['name' => 'Production'])->assertCreated();
        $this->assertDatabaseHas('monitoring_groups', ['name' => 'Production', 'user_id' => $user->id]);
        $this->withToken($token)->getJson('/api/monitoring-groups')->assertForbidden();
    }

    public function test_analytics_key_cannot_reach_management_routes(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $token = $this->key($user, [ApiKeyAbility::ANALYTICS_READ]);

        $this->withToken($token)->getJson('/api/monitorings/' . $monitoring->id . '/status')->assertOk();
        $this->withToken($token)->getJson('/api/monitorings')->assertForbidden();
        $this->withToken($token)->getJson('/api/status-pages')->assertForbidden();
    }

    public function test_scoped_keys_cannot_reach_account_admin_or_key_management_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $this->key($admin, [ApiKeyAbility::MANAGEMENT_READ, ApiKeyAbility::MANAGEMENT_WRITE]);

        foreach ([
            ['getJson', '/api/admin/users'],
            ['getJson', '/api/profile/api-keys'],
            ['postJson', '/api/profile/api-keys'],
            ['getJson', '/api/api-keys'],
            ['putJson', '/api/profile/password'],
            ['deleteJson', '/api/profile/account'],
            ['getJson', '/api/session'],
            ['getJson', '/api/mobile/overview'],
        ] as [$method, $uri]) {
            $response = $this->withToken($token)->{$method}($uri);
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri}");
        }
    }

    public function test_scoped_keys_manage_notification_settings_read_only_without_secrets_in_scope_gap(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);

        $this->withToken($token)->getJson('/api/profile/notification-settings')->assertOk();
        $this->withToken($token)->patchJson('/api/profile/notification-settings', [])->assertForbidden();
    }

    public function test_bearer_requests_skip_csrf_but_cookie_requests_keep_it(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_WRITE]);

        $this->app['env'] = 'production';
        $this->withMiddleware(PreventRequestForgeryExceptBearer::class);

        $this->withToken($token)->postJson('/api/monitoring-groups', ['name' => 'Bearer write'])->assertCreated();

        $this->flushHeaders()->actingAs($user, 'web')->postJson('/api/monitoring-groups', ['name' => 'Cookie write'])->assertStatus(419);
        $this->assertTrue(is_subclass_of(PreventRequestForgeryExceptBearer::class, PreventRequestForgery::class));
    }

    public function test_api_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/monitorings')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    public function test_rate_limit_is_configurable(): void
    {
        config(['external_api.rate_limit_per_minute' => 2]);
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);

        $this->withToken($token)->getJson('/api/monitorings')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->withToken($token)->getJson('/api/monitorings')->assertOk();
        $this->withToken($token)->getJson('/api/monitorings')->assertTooManyRequests();
    }

    /**
     * @param  list<ApiKeyAbility>  $abilities
     */
    private function key(User $user, array $abilities): string
    {
        return $user->createToken(
            ApiKeyService::storedName('scope-test'),
            array_map(static fn (ApiKeyAbility $ability): string => $ability->value, $abilities)
        )->plainTextToken;
    }
}
