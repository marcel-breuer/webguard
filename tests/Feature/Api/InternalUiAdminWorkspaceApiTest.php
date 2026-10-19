<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\ApiLog;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

class InternalUiAdminWorkspaceApiTest extends TestCase
{
    use AssertsApiContracts;
    use RefreshDatabase;

    public function test_member_cannot_access_administration_api_contracts(): void
    {
        Package::factory()->create();
        $member = User::factory()->create();

        foreach (['dashboard', 'users.index', 'packages.index', 'server-instances.index', 'api-logs.index', 'activity-logs.index'] as $route) {
            $this->actingAs($member)->getJson(route("app.admin.{$route}"))->assertForbidden();
        }
    }

    public function test_admin_can_manage_users_packages_and_server_instances(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 25, 'is_selectable' => true]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)->postJson(route('app.admin.users.store'), [
            'name' => 'Managed user', 'email' => 'managed@example.test', 'password' => 'new-password', 'role' => UserRole::REGULAR->value, 'package_id' => $package->id,
        ])->assertCreated()->assertJsonPath('data.email', 'managed@example.test');

        $createdPackage = $this->actingAs($admin)->postJson(route('app.admin.packages.store'), [
            'monitoring_limit' => 50, 'price' => 19.99, 'is_selectable' => true,
        ])->assertCreated()->json('data');

        $this->actingAs($admin)->postJson(route('app.admin.server-instances.store'), [
            'code' => 'de-admin-1', 'display_name' => 'Admin Germany', 'country_code' => 'DE', 'region' => 'Frankfurt', 'ip_address' => '192.0.2.42', 'api_key' => 'a-secure-instance-key', 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.health', 'never_seen');

        $this->actingAs($admin)->getJson(route('app.admin.users.index'))
            ->assertOk()->assertJsonFragment(['email' => 'managed@example.test']);
        $this->actingAs($admin)->getJson(route('app.admin.packages.index'))
            ->assertOk()->assertJsonFragment(['id' => $createdPackage['id']]);
        $this->actingAs($admin)->getJson(route('app.admin.server-instances.index'))
            ->assertOk()->assertJsonFragment(['code' => 'de-admin-1']);

        $this->assertDatabaseHas('server_instances', ['code' => 'de-admin-1']);
        $this->assertInternalUiTelemetry($this->actingAs($admin)->getJson(route('app.admin.dashboard')), 6, 131072);
    }

    public function test_admin_can_rotate_an_instance_key_and_the_previous_key_stops_authenticating(): void
    {
        Package::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'de-admin-rotation',
            'display_name' => 'Admin Germany',
            'country_code' => 'DE',
            'region' => 'Frankfurt',
            'ip_address' => '192.0.2.43',
            'api_key_hash' => 'original-instance-secret-123',
            'is_active' => true,
        ]);
        $newKey = 'replacement-instance-secret-456';

        $this->actingAs($admin)->patchJson(route('app.admin.server-instances.update', $serverInstance), [
            'code' => $serverInstance->code,
            'display_name' => $serverInstance->display_name,
            'country_code' => $serverInstance->country_code,
            'region' => $serverInstance->region,
            'ip_address' => $serverInstance->ip_address,
            'api_key' => $newKey,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonMissing(['api_key' => $newKey])
            ->assertJsonMissing(['api_key_hash' => $serverInstance->api_key_hash]);

        $this->assertTrue($serverInstance->fresh()->verifyApiKey($newKey));
        $this->assertFalse($serverInstance->fresh()->verifyApiKey('original-instance-secret-123'));

        $route = route('instances.monitorings.list', ['location' => $serverInstance->code]);
        $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'original-instance-secret-123',
        ])->getJson($route)->assertUnauthorized();

        $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => $newKey,
        ])->getJson($route)->assertOk();
    }

    public function test_admin_contract_validates_destructive_and_log_workflows(): void
    {
        $assignedPackage = Package::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $assignedUser = User::factory()->create(['package_id' => $assignedPackage->id]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'de-test-1',
            'display_name' => 'Test Germany',
            'country_code' => 'DE',
            'ip_address' => '192.0.2.41',
            'api_key_hash' => 'a-secure-instance-key',
        ]);
        ApiLog::query()->create(['user_id' => $admin->id, 'route' => '/api/monitorings']);

        $this->actingAs($admin)->deleteJson(route('app.admin.packages.destroy', $assignedPackage))
            ->assertUnprocessable()->assertJsonValidationErrors('package');
        $this->actingAs($admin)->deleteJson(route('app.admin.users.destroy', $admin))
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->actingAs($admin)->deleteJson(route('app.admin.server-instances.destroy', $serverInstance))
            ->assertNoContent();
        $this->actingAs($admin)->getJson(route('app.admin.api-logs.index'))
            ->assertOk()->assertJsonPath('data.items.0.user_email', $admin->email);
        $this->actingAs($admin)->getJson(route('app.admin.activity-logs.index'))->assertOk();

        $this->assertDatabaseHas('users', ['id' => $assignedUser->id]);
    }

    public function test_admin_can_search_filter_sort_and_paginate_api_logs(): void
    {
        Package::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $firstUser = User::factory()->create(['email' => 'alpha@example.test']);
        $secondUser = User::factory()->create(['email' => 'zeta@example.test']);

        ApiLog::query()->create(['user_id' => $firstUser->id, 'route' => '/api/monitorings']);
        ApiLog::query()->create(['user_id' => $secondUser->id, 'route' => '/api/status-pages']);

        $this->actingAs($admin)->getJson(route('app.admin.api-logs.index', [
            'search' => '/api/',
            'sort' => 'user_email',
            'direction' => 'asc',
            'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.user_email', 'alpha@example.test')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonFragment(['email' => 'zeta@example.test']);

        $this->actingAs($admin)->getJson(route('app.admin.api-logs.index', ['user_id' => $secondUser->id]))
            ->assertOk()
            ->assertJsonPath('data.items.0.user_email', 'zeta@example.test')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_admin_can_search_filter_sort_and_paginate_every_admin_table(): void
    {
        Package::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $regularUser = User::factory()->create(['name' => 'Alpha member', 'role' => UserRole::REGULAR]);
        $hiddenPackage = Package::factory()->create(['monitoring_limit' => 10, 'price' => 9.99, 'is_selectable' => false]);
        $selectablePackage = Package::factory()->create(['monitoring_limit' => 50, 'price' => 29.99, 'is_selectable' => true]);
        $serverInstance = ServerInstance::query()->create([
            'code' => 'de-inactive-1',
            'display_name' => 'Inactive Germany',
            'country_code' => 'DE',
            'ip_address' => '192.0.2.50',
            'api_key_hash' => 'a-secure-instance-key',
            'is_active' => false,
        ]);
        ServerInstance::query()->create([
            'code' => 'de-active-1',
            'display_name' => 'Active Germany',
            'country_code' => 'DE',
            'ip_address' => '192.0.2.51',
            'api_key_hash' => 'a-secure-instance-key',
            'is_active' => true,
        ]);
        Activity::query()->create([
            'log_name' => 'user',
            'description' => 'alpha_member_updated',
            'event' => 'updated',
            'causer_type' => User::class,
            'causer_id' => $admin->id,
            'attribute_changes' => ['attributes' => ['name' => 'Alpha member', 'password' => '[redacted]']],
            'properties' => ['source' => 'admin'],
        ]);
        activity('monitoring')->event('created')->log('monitoring_created');

        $this->actingAs($admin)->getJson(route('app.admin.users.index', [
            'search' => 'Alpha', 'role' => UserRole::REGULAR->value, 'sort' => 'name', 'direction' => 'asc', 'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $regularUser->id)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($admin)->getJson(route('app.admin.packages.index', [
            'search' => '50', 'selectable' => 'yes', 'sort' => 'price', 'direction' => 'desc', 'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $selectablePackage->id)
            ->assertJsonMissing(['id' => $hiddenPackage->id]);

        $this->actingAs($admin)->getJson(route('app.admin.server-instances.index', [
            'active' => 'no', 'sort' => 'display_name', 'direction' => 'asc', 'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $serverInstance->id)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($admin)->getJson(route('app.admin.activity-logs.index', [
            'search' => 'alpha_member', 'event' => 'updated', 'sort' => 'description', 'direction' => 'asc', 'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.description', 'alpha_member_updated')
            ->assertJsonPath('data.items.0.changes.attributes.attributes.name', 'Alpha member')
            ->assertJsonPath('data.items.0.changes.attributes.attributes.password', '[redacted]')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonFragment(['events' => ['created', 'updated']]);
    }

    public function test_admin_can_update_users_and_verify_an_email_address(): void
    {
        $package = Package::factory()->create(['monitoring_limit' => 50]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $user = User::factory()->unverified()->create(['email' => 'original@example.test']);

        $this->actingAs($admin)->patchJson(route('app.admin.users.update', $user), [
            'name' => 'Updated user',
            'email' => 'updated@example.test',
            'password' => 'updated-password',
            'role' => UserRole::ADMIN->value,
            'package_id' => $package->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'updated@example.test')
            ->assertJsonPath('data.package_limit', 50);

        $this->assertTrue(Hash::check('updated-password', $user->refresh()->password));

        $this->actingAs($admin)->postJson(route('app.admin.users.verify', $user))
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', $user->refresh()->email_verified_at?->toIso8601String());
    }
}
