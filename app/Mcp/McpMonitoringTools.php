<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\User;
use App\Services\McpMonitoringQueryService;
use Mcp\Exception\ToolCallException;

final class McpMonitoringTools
{
    public function __construct(
        private readonly User $user,
        private readonly McpMonitoringQueryService $mcpMonitoringQueryService,
    ) {}

    /**
     * List monitorings visible to the authenticated WebGuard user.
     *
     * @return array{monitorings: list<array{id: string, name: string, type: string, status: string}>, count: int}
     */
    public function listMonitorings(int $limit = 50): array
    {
        throw_if($limit < 1 || $limit > 100, ToolCallException::class, 'The limit must be between 1 and 100.');

        return $this->mcpMonitoringQueryService->listAccessible($this->user, $limit);
    }

    /**
     * Retrieve the monitoring analytics payload used by the WebGuard monitoring API.
     *
     * @return array{monitoring: array{id: string, name: string}, days: int, analytics: array<string, mixed>}
     */
    public function getMonitoringAnalytics(string $monitoring_id, int $days = 30): array
    {
        throw_if($days < 1 || $days > 365, ToolCallException::class, 'The days value must be between 1 and 365.');

        return $this->mcpMonitoringQueryService->analytics($this->user, $monitoring_id, $days);
    }
}
