<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiKeyAbility;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\TestCase;

class McpManagementToolsTest extends TestCase
{
    use RefreshDatabase;

    private const PROTOCOL = '2025-11-25';

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create(['monitoring_limit' => 10]);
        config(['external_api.rate_limit_per_minute' => 500]);
    }

    public function test_read_key_sees_read_tools_resources_and_prompts_but_no_write_tools(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);
        $session = $this->initialize($token);

        $names = $this->rpc($token, $session, 'tools/list')->json('result.tools.*.name');

        $this->assertContains('list_monitorings', $names);
        $this->assertContains('get_monitoring', $names);
        $this->assertContains('list_status_pages', $names);
        $this->assertNotContains('create_monitoring', $names);
        $this->assertNotContains('delete_monitoring', $names);
        $this->assertNotContains('post_incident_update', $names);

        $resources = $this->rpc($token, $session, 'resources/list')->json('result.resources.*.uri');
        $this->assertContains('webguard://monitorings', $resources);

        $prompts = $this->rpc($token, $session, 'prompts/list')->json('result.prompts.*.name');
        $this->assertSame(['investigate_incident', 'monitoring_health_review', 'draft_status_page_update'], $prompts);
    }

    public function test_every_write_tool_is_marked_and_annotated_and_requires_the_write_ability(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ, ApiKeyAbility::MANAGEMENT_WRITE]);
        $session = $this->initialize($token);

        $tools = $this->rpc($token, $session, 'tools/list')->json('result.tools');
        $write = array_filter($tools, static fn (array $tool): bool => ($tool['annotations']['readOnlyHint'] ?? true) === false);

        $this->assertNotEmpty($write);
        foreach ($write as $tool) {
            if ($tool['name'] === 'diagnose_incident') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^WRITE(, DESTRUCTIVE)?: /', $tool['description'], $tool['name']);
        }

        $destructive = array_filter($tools, static fn (array $tool): bool => ($tool['annotations']['destructiveHint'] ?? false) === true);
        $this->assertContains('delete_monitoring', array_column($destructive, 'name'));

        foreach ($tools as $tool) {
            $this->assertDoesNotMatchRegularExpression('/(password|bearer|https?:\/\/)/i', (string) $tool['description'], $tool['name']);
        }
    }

    public function test_write_only_key_gets_only_write_tools(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_WRITE]);
        $session = $this->initialize($token);

        $names = $this->rpc($token, $session, 'tools/list')->json('result.tools.*.name');

        $this->assertContains('create_monitoring_group', $names);
        $this->assertNotContains('get_monitoring', $names);
        $this->assertNotContains('list_monitorings', $names);
    }

    public function test_calling_a_write_tool_with_a_read_key_fails(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);
        $session = $this->initialize($token);

        $response = $this->callTool($token, $session, 'create_monitoring_group', ['name' => 'Should not exist']);

        $this->assertTrue($response->json('error') !== null || $response->json('result.isError') === true);
        $this->assertDatabaseMissing('monitoring_groups', ['name' => 'Should not exist']);
    }

    public function test_write_tools_reuse_rest_validation_and_ownership_rules(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Monitoring::factory()->for($other)->create();
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ, ApiKeyAbility::MANAGEMENT_WRITE]);
        $session = $this->initialize($token);

        $created = $this->callTool($token, $session, 'create_monitoring_group', ['name' => 'Production']);
        $this->assertNotTrue($created->json('result.isError'));
        $group = MonitoringGroup::query()->where('name', 'Production')->firstOrFail();
        $this->assertSame($user->id, $group->user_id);

        $invalid = $this->callTool($token, $session, 'create_monitoring_group', ['name' => 'Production']);
        $this->assertTrue($invalid->json('result.isError'));
        $this->assertStringContainsString('HTTP 422', (string) $invalid->json('result.content.0.text'));

        $denied = $this->callTool($token, $session, 'delete_monitoring', ['monitoring_id' => (string) $foreign->getKey()]);
        $this->assertTrue($denied->json('result.isError'));
        $this->assertDatabaseHas('monitorings', ['id' => $foreign->getKey(), 'deleted_at' => null]);

        $deleted = $this->callTool($token, $session, 'delete_monitoring_group', ['group_id' => (string) $group->getKey()]);
        $this->assertNotTrue($deleted->json('result.isError'));
        $this->assertDatabaseMissing('monitoring_groups', ['id' => $group->getKey()]);
    }

    public function test_monitoring_can_be_created_updated_and_deleted_through_mcp(): void
    {
        $user = User::factory()->create();
        ServerInstance::query()->create(['code' => 'mcp-loc-1', 'ip_address' => '192.0.2.77', 'api_key_hash' => 'mcp-test-token-1234567', 'is_active' => true]);
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ, ApiKeyAbility::MANAGEMENT_WRITE]);
        $session = $this->initialize($token);
        $payload = [
            'name' => 'MCP check',
            'type' => 'http',
            'target' => 'https://mcp.example.test',
            'status' => 'active',
            'timeout' => 5,
            'http_method' => 'get',
            'expected_http_statuses' => '200-299',
            'preferred_location' => 'mcp-loc-1',
            'preferred_locations' => ['mcp-loc-1'],
            'notification_on_failure' => true,
            'failure_confirmation_threshold' => 1,
            'ssl_expiry_warning_days' => 7,
        ];

        $created = $this->callTool($token, $session, 'create_monitoring', $payload);
        $this->assertNotTrue($created->json('result.isError'), (string) $created->getContent());
        $monitoring = Monitoring::query()->where('name', 'MCP check')->firstOrFail();

        $updated = $this->callTool($token, $session, 'update_monitoring', ['monitoring_id' => (string) $monitoring->getKey()] + array_replace($payload, ['status' => 'paused']));
        $this->assertNotTrue($updated->json('result.isError'), (string) $updated->getContent());
        $this->assertSame('paused', $monitoring->fresh()->status->value);

        $shown = $this->callTool($token, $session, 'get_monitoring', ['monitoring_id' => (string) $monitoring->getKey()]);
        $this->assertSame('MCP check', $shown->json('result.structuredContent.data.name'));

        $this->callTool($token, $session, 'delete_monitoring', ['monitoring_id' => (string) $monitoring->getKey()]);
        $this->assertSoftDeleted('monitorings', ['id' => $monitoring->getKey()]);
    }

    public function test_notification_settings_never_expose_channel_secrets(): void
    {
        $user = User::factory()->create([
            'notification_channels' => [
                'slack' => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.test/T000/B000/SECRETVALUE'],
                'telegram' => ['enabled' => true, 'bot_token' => '123456:SECRET-BOT-TOKEN', 'chat_id' => '42'],
            ],
        ]);
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);
        $session = $this->initialize($token);

        $response = $this->callTool($token, $session, 'get_notification_settings', []);

        $this->assertNotTrue($response->json('result.isError'), (string) $response->getContent());
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SECRETVALUE', $body);
        $this->assertStringNotContainsString('SECRET-BOT-TOKEN', $body);
        $this->assertTrue($response->json('result.structuredContent.data.notification_channels.slack.enabled'));
    }

    public function test_resources_are_scoped_to_the_key_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = Monitoring::factory()->for($user)->create(['name' => 'Mine']);
        $theirs = Monitoring::factory()->for($other)->create(['name' => 'Theirs']);
        $token = $this->key($user, [ApiKeyAbility::MANAGEMENT_READ]);
        $session = $this->initialize($token);

        $list = $this->rpc($token, $session, 'resources/read', ['uri' => 'webguard://monitorings']);
        $this->assertStringContainsString('Mine', (string) $list->getContent());
        $this->assertStringNotContainsString('Theirs', (string) $list->getContent());

        $one = $this->rpc($token, $session, 'resources/read', ['uri' => 'webguard://monitorings/' . $mine->getKey()]);
        $this->assertStringContainsString('Mine', (string) $one->getContent());

        $foreign = $this->rpc($token, $session, 'resources/read', ['uri' => 'webguard://monitorings/' . $theirs->getKey()]);
        $this->assertStringNotContainsString('Theirs', (string) $foreign->getContent());
    }

    public function test_analytics_key_keeps_the_original_read_only_tool_set(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::ANALYTICS_READ]);
        $session = $this->initialize($token);

        $this->assertSame(
            ['list_monitorings', 'get_monitoring_analytics', 'list_open_incidents', 'diagnose_incident'],
            $this->rpc($token, $session, 'tools/list')->json('result.tools.*.name')
        );
    }

    public function test_key_without_mcp_abilities_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->key($user, [ApiKeyAbility::SERVER_HEALTH_WRITE]);

        $this->post('/api/mcp', [], ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])->assertForbidden();
    }

    /**
     * @param  list<ApiKeyAbility>  $abilities
     */
    private function key(User $user, array $abilities): string
    {
        return $user->createToken(
            ApiKeyService::storedName('mcp-test'),
            array_map(static fn (ApiKeyAbility $ability): string => $ability->value, $abilities)
        )->plainTextToken;
    }

    private function initialize(string $token): string
    {
        $response = $this->postMcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL,
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                'capabilities' => new stdClass(),
            ],
        ], $token)->assertOk();
        $session = (string) $response->headers->get('Mcp-Session-Id');
        $this->postMcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token, $this->sessionHeaders($session))->assertAccepted();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $token, string $session, string $method, array $params = []): TestResponse
    {
        static $id = 100;
        $payload = ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method];

        if ($params !== []) {
            $payload['params'] = $params;
        }

        return $this->postMcp($payload, $token, $this->sessionHeaders($session))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $token, string $session, string $name, array $arguments): TestResponse
    {
        return $this->rpc($token, $session, 'tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new stdClass() : $arguments]);
    }

    /**
     * @return array<string, string>
     */
    private function sessionHeaders(string $session): array
    {
        return ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => self::PROTOCOL];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postMcp(array $payload, string $token, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/mcp', server: $server, content: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
