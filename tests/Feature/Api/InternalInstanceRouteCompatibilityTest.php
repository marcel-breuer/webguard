<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsApiContracts;
use Tests\TestCase;

class InternalInstanceRouteCompatibilityTest extends TestCase
{
    use AssertsApiContracts;

    public function test_instance_routes_use_the_dedicated_instances_namespace(): void
    {
        foreach ($this->routeNames() as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertStringStartsWith('api/instances/', $route->uri());
            $this->assertStringNotContainsString('/v1/', $route->uri());
            $this->assertStringNotContainsString('/internal/', $route->uri());
        }
    }

    public function test_instance_monitoring_list_enforces_the_existing_contract(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'target-route-instance',
            'ip_address' => '192.0.2.56',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        $assignedMonitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);
        Monitoring::factory()->for($user)->create([
            'preferred_location' => 'another-instance',
            'preferred_locations' => ['another-instance'],
        ]);

        $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ])->getJson(route('instances.monitorings.list', ['location' => $serverInstance->code]))
            ->assertOk()
            ->assertJsonPath('0.id', $assignedMonitoring->id)
            ->assertJsonPath('0.check_interval_seconds', 900)
            ->assertJsonPath('0.last_response_at', null)
            ->assertJsonCount(1);
    }

    public function test_instance_routes_enforce_the_authentication_and_location_contract(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'contract-instance',
            'ip_address' => '192.0.2.57',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->getJson(route('instances.monitorings.list', ['location' => $serverInstance->code]))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized');

        $this->withHeaders($this->instanceHeaders($serverInstance, 'test-token-1234567890'))
            ->getJson(route('instances.monitorings.list', ['location' => 'different-instance']))
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized location');
    }

    public function test_instance_monitoring_list_returns_the_latest_response_for_its_own_location(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'last-response-de-1',
            'ip_address' => '192.0.2.61',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        $secondLocation = ServerInstance::query()->create([
            'code' => 'last-response-us-1',
            'ip_address' => '192.0.2.62',
            'api_key_hash' => 'test-token-abcdefghij',
            'is_active' => true,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'check_interval_minutes' => 5,
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code, $secondLocation->code],
        ]);
        $firstResponseAt = now()->subMinutes(4)->startOfSecond();
        $secondResponseAt = now()->subMinute()->startOfSecond();

        foreach ([[$serverInstance, $firstResponseAt], [$secondLocation, $secondResponseAt]] as [$location, $responseAt]) {
            $response = MonitoringResponse::query()->create([
                'monitoring_id' => $monitoring->id,
                'location_code' => $location->code,
                'status' => 'up',
            ]);
            $response->forceFill(['created_at' => $responseAt, 'updated_at' => $responseAt])->save();
        }

        $this->withHeaders($this->instanceHeaders($serverInstance, 'test-token-1234567890'))
            ->getJson(route('instances.monitorings.list', ['location' => $serverInstance->code]))
            ->assertOk()
            ->assertJsonPath('0.last_response_at', $firstResponseAt->toIso8601String())
            ->assertJsonPath('0.check_interval_seconds', 300);

        $this->withHeaders($this->instanceHeaders($secondLocation, 'test-token-abcdefghij'))
            ->getJson(route('instances.monitorings.list', ['location' => $secondLocation->code]))
            ->assertOk()
            ->assertJsonPath('0.last_response_at', $secondResponseAt->toIso8601String());
    }

    /**
     * @return list<string>
     */
    private function routeNames(): array
    {
        return [
            'instances.monitorings.list',
            'instances.monitoring-responses.store',
            'instances.incidents.store',
            'instances.incidents.update',
            'instances.ssl-results.store',
            'instances.domain-results.store',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function instanceHeaders(ServerInstance $serverInstance, string $apiKey): array
    {
        return [
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => $apiKey,
        ];
    }
}
