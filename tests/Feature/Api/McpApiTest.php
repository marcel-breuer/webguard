<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiKeyAbility;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\TestCase;

class McpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_mcp_endpoint_requires_authentication(): void
    {
        $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                'capabilities' => new stdClass(),
            ],
        ])->assertUnauthorized();
    }

    public function test_analytics_key_can_list_only_monitorings_visible_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $visible = Monitoring::factory()->for($owner)->create(['name' => 'Visible monitor']);
        Monitoring::factory()->for($otherUser)->create(['name' => 'Private monitor']);
        $token = $owner->createToken(
            ApiKeyService::storedName('Analytics'),
            [ApiKeyAbility::ANALYTICS_READ->value]
        )->plainTextToken;

        $testResponse = $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                'capabilities' => new stdClass(),
            ],
        ], $token)->assertOk();

        $sessionId = (string) $testResponse->headers->get('Mcp-Session-Id');
        $this->assertNotSame('', $sessionId);

        $this->postMcp([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $token, [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ])->assertAccepted();

        $tools = $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], $token, [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ])->assertOk();

        $tools->assertJsonPath('result.tools.0.name', 'list_monitorings')
            ->assertJsonPath('result.tools.1.name', 'get_monitoring_analytics');

        $result = $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_monitorings',
                'arguments' => ['limit' => 100],
            ],
        ], $token, [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ])->assertOk();

        $result->assertJsonPath('result.structuredContent.count', 1)
            ->assertJsonPath('result.structuredContent.monitorings.0.id', (string) $visible->getKey())
            ->assertJsonMissing(['name' => 'Private monitor']);

        $analytics = $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_monitoring_analytics',
                'arguments' => [
                    'monitoring_id' => (string) $visible->getKey(),
                    'days' => 7,
                ],
            ],
        ], $token, [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ])->assertOk();

        $analytics->assertJsonPath('result.structuredContent.monitoring.id', (string) $visible->getKey())
            ->assertJsonPath('result.structuredContent.days', 7)
            ->assertJsonStructure([
                'result' => [
                    'structuredContent' => [
                        'analytics' => [
                            'status_since',
                            'status_now',
                            'uptime_downtime' => ['uptime', 'downtime', 'unknown'],
                            'response_times' => ['data', 'aggregated'],
                            'incidents',
                            'heatmap',
                            'ssl',
                            'uptime_calendar',
                        ],
                    ],
                ],
            ]);
    }

    public function test_mcp_rejects_managed_keys_without_analytics_read_scope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken(
            ApiKeyService::storedName('Server health'),
            ['server-health:write']
        )->plainTextToken;

        $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                'capabilities' => new stdClass(),
            ],
        ], $token)->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postMcp(array $payload, ?string $token = null, array $headers = []): TestResponse
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            'POST',
            '/api/mcp',
            server: $server,
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
