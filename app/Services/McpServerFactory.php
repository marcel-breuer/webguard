<?php

declare(strict_types=1);

namespace App\Services;

use App\Mcp\McpMonitoringTools;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;

final class McpServerFactory
{
    public function __construct(
        private readonly McpMonitoringQueryService $mcpMonitoringQueryService,
    ) {}

    public function make(User $user): Server
    {
        $mcpMonitoringTools = new McpMonitoringTools($user, $this->mcpMonitoringQueryService);
        $toolAnnotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        return Server::builder()
            ->setServerInfo('WebGuard', '1.0.0', 'Read-only monitoring queries for WebGuard.')
            ->setInstructions('Use list_monitorings to find monitorings visible to the authenticated user. Use get_monitoring_analytics with a monitoring ID for the same analytics exposed by the WebGuard API. All tools are read-only.')
            ->setSession(new Psr16SessionStore(
                cache: Cache::store(),
                prefix: 'webguard-mcp-session:',
                ttl: 3600,
            ))
            ->addTool(
                [$mcpMonitoringTools, 'listMonitorings'],
                name: 'list_monitorings',
                title: 'List monitorings',
                description: 'List monitorings visible to the authenticated WebGuard user. Returns IDs, names, types, and statuses without targets or credentials.',
                annotations: $toolAnnotations,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'monitorings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                    'type' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'name', 'type', 'status'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'count' => ['type' => 'integer'],
                    ],
                    'required' => ['monitorings', 'count'],
                    'additionalProperties' => false,
                ],
            )
            ->addTool(
                [$mcpMonitoringTools, 'getMonitoringAnalytics'],
                name: 'get_monitoring_analytics',
                title: 'Get monitoring analytics',
                description: 'Return the monitoring analytics payload exposed by the WebGuard API for a monitoring visible to the authenticated user. Days defaults to 30 and may be from 1 to 365.',
                annotations: $toolAnnotations,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'monitoring_id' => ['type' => 'string', 'minLength' => 1],
                        'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                    ],
                    'required' => ['monitoring_id'],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'monitoring' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'name'],
                            'additionalProperties' => false,
                        ],
                        'days' => ['type' => 'integer'],
                        'analytics' => ['type' => 'object'],
                    ],
                    'required' => ['monitoring', 'days', 'analytics'],
                    'additionalProperties' => false,
                ],
            )
            ->build();
    }
}
