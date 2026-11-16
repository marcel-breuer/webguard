<?php

declare(strict_types=1);

namespace App\Services;

use App\Mcp\McpMonitoringTools;
use App\Models\User;
use App\Services\Mcp\McpAccess;
use App\Services\Mcp\McpPromptHandler;
use App\Services\Mcp\McpResourceHandler;
use App\Services\Mcp\McpRestBridge;
use App\Services\Mcp\McpRestToolHandler;
use App\Services\Mcp\McpToolCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\Psr16SessionStore;
use stdClass;

final class McpServerFactory
{
    public function __construct(
        private readonly McpMonitoringQueryService $mcpMonitoringQueryService,
        private readonly IncidentDiagnosisAccessService $incidentDiagnosisAccessService,
        private readonly McpRestBridge $mcpRestBridge,
    ) {}

    public function make(User $user, Request $request, ?McpAccess $mcpAccess = null): Server
    {
        $mcpAccess ??= McpAccess::fromToken($user->currentAccessToken());
        $mcpMonitoringTools = new McpMonitoringTools($user, $this->mcpMonitoringQueryService, $this->incidentDiagnosisAccessService);
        $toolAnnotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        $builder = Server::builder()
            ->setServerInfo('WebGuard', '2.0.0', 'Monitoring, status page, incident, maintenance and team management for WebGuard.')
            ->setInstructions('Start with list_monitorings to find monitorings and get_monitoring_analytics for their analytics. list_open_incidents and diagnose_incident explain current incidents. Tools that only read are annotated readOnlyHint=true. Tools whose description starts with WRITE change data, require a key with the management:write ability and act with the same permissions as the key owner; tools marked DESTRUCTIVE delete data and cannot be undone, so confirm with the user before calling them. Secrets such as webhook URLs, bot tokens and heartbeat tokens are never returned. Resources under webguard:// expose read-only snapshots; prompts describe common workflows.')
            ->setSession(new Psr16SessionStore(
                cache: Cache::store(),
                prefix: 'webguard-mcp-session:',
                ttl: 3600,
            ));

        if ($mcpAccess->analytics) {
            $this->addMonitoringTools($builder, $mcpMonitoringTools, $toolAnnotations);
        }

        $this->addCatalogueTools($builder, $request, $mcpAccess);
        $this->addResources($builder, $request, $mcpAccess);
        $this->addPrompts($builder);

        return $builder->build();
    }

    private function addMonitoringTools(Builder $builder, McpMonitoringTools $mcpMonitoringTools, ToolAnnotations $toolAnnotations): void
    {
        $builder
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
            ->addTool(
                [$mcpMonitoringTools, 'listOpenIncidents'],
                name: 'list_open_incidents',
                title: 'List open incidents',
                description: 'List currently open incidents on monitorings visible to the authenticated user, each with its ranked probable-cause diagnosis. Limit defaults to 20 and may be from 1 to 50.',
                annotations: $toolAnnotations,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'incidents' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'count' => ['type' => 'integer'],
                    ],
                    'required' => ['incidents', 'count'],
                    'additionalProperties' => false,
                ],
            )
            ->addTool(
                [$mcpMonitoringTools, 'diagnoseIncident'],
                name: 'diagnose_incident',
                title: 'Diagnose incident',
                description: 'Return the deterministic root-cause diagnosis for an incident visible to the authenticated user: ranked hypotheses with category, confidence, a human-readable summary, and the supporting evidence. Set recompute to true to refresh the stored diagnosis from current data.',
                annotations: new ToolAnnotations(
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'incident_id' => ['type' => 'string', 'minLength' => 1],
                        'recompute' => ['type' => 'boolean'],
                    ],
                    'required' => ['incident_id'],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'incident' => ['type' => 'object'],
                        'diagnosis' => ['type' => ['object', 'null']],
                    ],
                    'required' => ['incident', 'diagnosis'],
                    'additionalProperties' => false,
                ],
            );
    }

    private function addCatalogueTools(Builder $builder, Request $request, McpAccess $mcpAccess): void
    {
        foreach (McpToolCatalogue::definitions() as $definition) {
            $allowed = $definition['access'] === 'write' ? $mcpAccess->write : $mcpAccess->read;

            if (! $allowed) {
                continue;
            }

            $isWrite = $definition['access'] === 'write';
            $builder->add(
                new Tool(
                    name: (string) $definition['name'],
                    title: (string) $definition['title'],
                    inputSchema: [
                        'type' => 'object',
                        'properties' => $definition['schema'] === [] ? new stdClass() : $definition['schema'],
                        'required' => $definition['required'],
                        'additionalProperties' => $definition['additional'],
                    ],
                    description: (string) $definition['description'],
                    annotations: new ToolAnnotations(
                        readOnlyHint: ! $isWrite,
                        destructiveHint: $isWrite ? (bool) $definition['destructive'] : false,
                        idempotentHint: (bool) $definition['idempotent'],
                        openWorldHint: (bool) $definition['openWorld'],
                    ),
                    outputSchema: ['type' => 'object'],
                ),
                new McpRestToolHandler($definition, $this->mcpRestBridge, $request),
            );
        }
    }

    private function addResources(Builder $builder, Request $request, McpAccess $mcpAccess): void
    {
        if (! $mcpAccess->read) {
            return;
        }

        $static = [
            ['webguard://monitorings', 'monitorings', 'Monitorings', 'First 100 monitorings visible to the user.', 'app.monitorings.index', ['per_page' => 100]],
            ['webguard://status-pages', 'status-pages', 'Status pages', 'Status pages managed by the user.', 'app.status-pages.index', []],
            ['webguard://incidents/open', 'open-incidents', 'Open incidents', 'Open incidents with probable-cause diagnoses.', 'app.incidents.diagnoses.open', []],
            ['webguard://dashboard', 'dashboard', 'Dashboard', 'Dashboard summary of the user.', 'app.dashboard', []],
        ];

        foreach ($static as [$uri, $name, $title, $description, $route, $query]) {
            $builder->add(
                new ResourceDefinition(uri: $uri, name: $name, title: $title, description: $description, mimeType: 'application/json'),
                new McpResourceHandler($route, [], $query, $this->mcpRestBridge, $request),
            );
        }

        $templates = [
            ['webguard://monitorings/{id}', 'monitoring', 'Monitoring', 'One monitoring with its configuration and state.', 'app.monitorings.show', ['monitoring' => 'id']],
            ['webguard://status-pages/{id}', 'status-page', 'Status page', 'One status page with its components.', 'app.status-pages.show', ['statusPage' => 'id']],
        ];

        foreach ($templates as [$uriTemplate, $name, $title, $description, $route, $path]) {
            $builder->add(
                new ResourceTemplate(uriTemplate: $uriTemplate, name: $name, title: $title, description: $description, mimeType: 'application/json'),
                new McpResourceHandler($route, $path, [], $this->mcpRestBridge, $request),
            );
        }
    }

    private function addPrompts(Builder $builder): void
    {
        $prompts = [
            ['investigate_incident', 'Investigate an incident', 'Explain a WebGuard incident using its diagnosis and analytics, without changing anything.', [new PromptArgument('incident_id', 'Incident ID.', true)]],
            ['monitoring_health_review', 'Monitoring health review', 'Review uptime, response times and open incidents across monitorings.', [new PromptArgument('days', 'Days to review, 1 to 365 (default 30).', false)]],
            ['draft_status_page_update', 'Draft a status page update', 'Draft a customer-facing incident update and post it only after confirmation.', [
                new PromptArgument('status_page_id', 'Status page ID.', true),
                new PromptArgument('incident_id', 'Incident ID.', true),
            ]],
        ];

        foreach ($prompts as [$name, $title, $description, $arguments]) {
            $builder->add(
                new Prompt(name: $name, title: $title, description: $description, arguments: $arguments),
                new McpPromptHandler($name),
            );
        }
    }
}
