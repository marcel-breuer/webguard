<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class InternalMonitoringCallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_instance_can_store_and_close_incidents_for_assigned_monitoring(): void
    {
        Date::setTestNow('2026-06-27 09:00:00');
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-1');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->postJson(route('instances.incidents.store'), [
                'monitoring_id' => $monitoring->id,
                'down_at' => Date::now()->subMinutes(10)->toDateTimeString(),
            ])->assertOk()
            ->assertJsonPath('message', 'Incident stored successfully.');

        $this->assertDatabaseHas('incidents', [
            'monitoring_id' => $monitoring->id,
            'up_at' => null,
        ]);

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->putJson(route('instances.incidents.update', $monitoring), [
                'up_at' => Date::now()->toDateTimeString(),
            ])->assertOk()
            ->assertJsonPath('message', 'Incident updated successfully.');

        $this->assertNotNull(Incident::query()->where('monitoring_id', $monitoring->id)->firstOrFail()->up_at);
    }

    public function test_instance_update_incident_returns_not_found_without_open_incident(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-2');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->putJson(route('instances.incidents.update', $monitoring), [
                'up_at' => now()->toDateTimeString(),
            ])->assertNotFound()
            ->assertJsonPath('message', 'No open incident found for this monitoring.');
    }

    public function test_instance_can_store_ssl_and_domain_results_for_assigned_monitoring(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-3');
        $httpMonitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);
        $domainMonitoring = Monitoring::factory()->domainExpiration()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
            'type' => MonitoringType::DOMAIN_EXPIRATION,
            'target' => 'example.test',
        ]);

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->postJson(route('instances.ssl-results.store'), [
                'monitoring_id' => $httpMonitoring->id,
                'is_valid' => true,
                'expires_at' => '2026-12-01 00:00:00',
                'issuer' => 'Example CA',
                'issued_at' => '2026-01-01 00:00:00',
            ])->assertOk()
            ->assertJsonPath('message', 'SSL result stored successfully.');

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->postJson(route('instances.domain-results.store'), [
                'monitoring_id' => $domainMonitoring->id,
                'is_valid' => true,
                'expires_at' => '2027-01-01 00:00:00',
                'registrar' => 'Example Registrar',
                'checked_at' => '2026-06-27 08:00:00',
            ])->assertOk()
            ->assertJsonPath('message', 'Domain expiration result stored successfully.');

        $this->assertDatabaseHas('monitoring_ssl_results', ['monitoring_id' => $httpMonitoring->id, 'issuer' => 'Example CA']);
        $this->assertDatabaseHas('monitoring_domain_results', ['monitoring_id' => $domainMonitoring->id, 'registrar' => 'Example Registrar']);
    }

    public function test_ssl_results_allow_only_one_current_result_per_monitoring(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-ssl-unique');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $payload = [
            'monitoring_id' => $monitoring->id,
            'is_valid' => true,
            'expires_at' => '2026-12-01 00:00:00',
            'issuer' => 'Example CA',
            'issued_at' => '2026-01-01 00:00:00',
        ];

        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->postJson(route('instances.ssl-results.store'), $payload)
            ->assertOk();
        $this->withHeaders($this->instanceHeaders($serverInstance))
            ->postJson(route('instances.ssl-results.store'), $payload)
            ->assertOk();

        $this->assertDatabaseCount('monitoring_ssl_results', 1);
        $this->assertSame(1, MonitoringSslResult::query()->where('monitoring_id', $monitoring->id)->count());
    }

    public function test_monitoring_response_callback_replays_a_duplicate_idempotency_key_without_repeating_the_write(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-idempotent');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);
        $payload = [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
            'response_time' => 100,
        ];
        $headers = [...$this->instanceHeaders($serverInstance), 'Idempotency-Key' => '6f3a8bb2-8a66-4c84-91db-5166c9c291c5'];

        $testResponse = $this->withHeaders($headers)
            ->postJson(route('instances.monitoring-responses.store'), $payload)
            ->assertOk();
        $this->withHeaders($headers)
            ->postJson(route('instances.monitoring-responses.store'), $payload)
            ->assertOk()
            ->assertExactJson($testResponse->json());

        $this->assertDatabaseCount('monitoring_response_results', 1);
    }

    public function test_reusing_an_idempotency_key_with_a_different_payload_returns_conflict(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-idempotent-conflict');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);
        $headers = [
            ...$this->instanceHeaders($serverInstance),
            'Idempotency-Key' => '39d4e4b3-2b2e-446c-9f79-58f6c7e35099',
        ];

        $this->withHeaders($headers)
            ->postJson(route('instances.monitoring-responses.store'), [
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP->value,
                'response_time' => 100,
            ])->assertOk();

        $this->withHeaders($headers)
            ->postJson(route('instances.monitoring-responses.store'), [
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::DOWN->value,
                'response_time' => 250,
            ])->assertConflict()
            ->assertJsonPath('message', 'Idempotency key was already used with a different request.');

        $this->assertSame(1, MonitoringResponse::query()->where('monitoring_id', $monitoring->id)->count());
    }

    public function test_invalid_instance_callback_idempotency_key_is_rejected(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-invalid-key');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->withHeaders([
            ...$this->instanceHeaders($serverInstance),
            'Idempotency-Key' => 'not-a-uuid',
        ])->postJson(route('instances.monitoring-responses.store'), [
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_unassigned_instance_cannot_store_callback_payloads(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $serverInstance = $this->serverInstance('internal-callback-assigned');
        $otherInstance = $this->serverInstance('internal-callback-other');
        $monitoring = Monitoring::factory()->for($user)->create([
            'preferred_location' => $serverInstance->code,
            'preferred_locations' => [$serverInstance->code],
        ]);

        $this->withHeaders($this->instanceHeaders($otherInstance))
            ->postJson(route('instances.monitoring-responses.store'), [
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP->value,
                'response_time' => 100,
            ])->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized monitoring');
    }

    private function serverInstance(string $code): ServerInstance
    {
        return ServerInstance::query()->create([
            'code' => $code,
            'ip_address' => '192.0.2.56',
            'api_key_hash' => 'test-token-1234567890',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function instanceHeaders(ServerInstance $serverInstance): array
    {
        return [
            'X-INSTANCE-CODE' => $serverInstance->code,
            'X-API-KEY' => 'test-token-1234567890',
        ];
    }
}
