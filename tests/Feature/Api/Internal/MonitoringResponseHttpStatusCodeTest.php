<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Internal;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringResponseHttpStatusCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_monitoring_response_stores_http_status_code(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->firstOrCreate(
            ['code' => 'de-1'],
            ['api_key_hash' => 'test-token-1234567890', 'is_active' => true]
        );
        $serverInstance->update([
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'check_interval_minutes' => 15,
            'preferred_location' => $serverInstance->code,
        ]);

        $testResponse = $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ])->postJson(route('instances.monitoring-responses.store'), [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN->value,
            'http_status_code' => 503,
            'response_time' => 210.7,
        ]);

        $testResponse->assertOk();
        $this->assertDatabaseHas('monitoring_response_results', [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN->value,
            'http_status_code' => 503,
            'check_interval_seconds' => 900,
        ]);
    }

    public function test_internal_response_without_interval_uses_the_monitoring_selection(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->create([
            'code' => 'cadence-de-1',
            'ip_address' => '192.0.2.10',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::KEYWORD,
            'check_interval_minutes' => 1,
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ])->postJson(route('instances.monitoring-responses.store'), [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
            'http_status_code' => 200,
            'response_time' => 100.0,
        ])->assertOk();

        $this->assertDatabaseHas('monitoring_response_results', [
            'monitoring_id' => $monitoring->id,
            'check_interval_seconds' => 60,
        ]);
    }

    public function test_internal_monitoring_response_stores_the_interval_reported_by_a_compatible_instance(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->firstOrCreate(
            ['code' => 'de-1'],
            ['api_key_hash' => 'test-token-1234567890', 'is_active' => true]
        );
        $serverInstance->update([
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
        ]);

        $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ])->postJson(route('instances.monitoring-responses.store'), [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
            'http_status_code' => 200,
            'response_time' => 120.0,
            'check_interval_seconds' => 900,
        ])->assertOk();

        $this->assertDatabaseHas('monitoring_response_results', [
            'monitoring_id' => $monitoring->id,
            'check_interval_seconds' => 900,
        ]);
    }

    public function test_internal_monitoring_response_rejects_invalid_http_status_code(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->firstOrCreate(
            ['code' => 'de-1'],
            ['api_key_hash' => 'test-token-1234567890', 'is_active' => true]
        );
        $serverInstance->update([
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
        ]);

        $testResponse = $this->withHeaders([
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ])->postJson(route('instances.monitoring-responses.store'), [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
            'http_status_code' => 99,
            'response_time' => 90.3,
        ]);

        $testResponse->assertUnprocessable();
        $testResponse->assertJsonValidationErrors(['http_status_code']);
    }

    public function test_internal_instance_monitoring_responses_are_not_api_usage_rate_limited(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = ServerInstance::query()->firstOrCreate(
            ['code' => 'de-1'],
            ['api_key_hash' => 'test-token-1234567890', 'is_active' => true]
        );
        $serverInstance->update([
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);

        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
        ]);

        foreach (range(1, 6) as $attempt) {
            $this->withHeaders([
                'X-INSTANCE-CODE' => $serverInstance->code,
                'X-API-KEY' => 'test-token-1234567890',
            ])->postJson(route('instances.monitoring-responses.store'), [
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP->value,
                'http_status_code' => 200,
                'response_time' => 100 + $attempt,
            ])->assertOk();
        }
    }
}
