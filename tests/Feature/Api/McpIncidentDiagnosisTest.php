<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiKeyAbility;
use App\Enums\MonitoringStatus;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\TestCase;

class McpIncidentDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_tools_are_listed_and_return_diagnoses_only_for_visible_incidents(): void
    {
        $owner = User::factory()->create();
        $mine = $this->openIncident($owner);
        $foreign = $this->openIncident(User::factory()->create(), 'Foreign checkout');
        $token = $owner->createToken(ApiKeyService::storedName('Analytics'), [ApiKeyAbility::ANALYTICS_READ->value])->plainTextToken;
        $headers = $this->initialize($token);

        $tools = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $token, $headers)->assertOk();
        $names = array_column($tools->json('result.tools'), 'name');
        $this->assertContains('list_open_incidents', $names);
        $this->assertContains('diagnose_incident', $names);

        $list = $this->callTool($token, $headers, 'list_open_incidents', ['limit' => 10]);
        $list->assertJsonPath('result.structuredContent.count', 1)
            ->assertJsonPath('result.structuredContent.incidents.0.incident.id', $mine->id)
            ->assertJsonPath('result.structuredContent.incidents.0.diagnosis.primary.category', 'server_error');

        $this->callTool($token, $headers, 'diagnose_incident', ['incident_id' => $mine->id])
            ->assertJsonPath('result.structuredContent.diagnosis.primary.category', 'server_error')
            ->assertJsonPath('result.structuredContent.diagnosis.hypotheses.0.rank', 1);

        $this->callTool($token, $headers, 'diagnose_incident', ['incident_id' => $mine->id, 'recompute' => true])
            ->assertJsonPath('result.structuredContent.diagnosis.trigger', 'recomputed');

        $denied = $this->callTool($token, $headers, 'diagnose_incident', ['incident_id' => $foreign->id]);
        $denied->assertJsonPath('result.isError', true);
        $this->assertStringNotContainsString('Foreign checkout', (string) $denied->getContent());
    }

    private function openIncident(User $user, string $name = 'Own site'): Incident
    {
        $monitoring = Monitoring::factory()->for($user)->create([
            'name' => $name,
            'type' => 'http',
            'target' => 'https://example.test/',
            'expected_http_statuses' => '200-299',
            'timeout' => 10,
            'failure_confirmation_threshold' => 1,
        ]);
        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'location_code' => 'de-1',
            'status' => MonitoringStatus::DOWN,
            'http_status_code' => 503,
            'response_time' => 90.0,
        ]);

        return Incident::query()->where('monitoring_id', $monitoring->id)->sole();
    }

    /**
     * @return array<string, string>
     */
    private function initialize(string $token): array
    {
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                'capabilities' => new stdClass(),
            ],
        ], $token)->assertOk();

        $headers = [
            'Mcp-Session-Id' => (string) $response->headers->get('Mcp-Session-Id'),
            'Mcp-Protocol-Version' => '2025-11-25',
        ];
        $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token, $headers)->assertAccepted();

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $token, array $headers, string $name, array $arguments): TestResponse
    {
        return $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $token, $headers)->assertOk();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function rpc(array $payload, string $token, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/mcp', server: $server, content: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
