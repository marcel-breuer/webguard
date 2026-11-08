<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\User;
use App\Services\IncidentDiagnosisAccessService;
use App\Services\McpMonitoringQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mcp\Exception\ToolCallException;

final class McpMonitoringTools
{
    public function __construct(
        private readonly User $user,
        private readonly McpMonitoringQueryService $mcpMonitoringQueryService,
        private readonly IncidentDiagnosisAccessService $incidentDiagnosisAccessService,
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

    /**
     * List open incidents on monitorings visible to the authenticated user, each with its probable-cause diagnosis.
     *
     * @return array{incidents: list<array<string, mixed>>, count: int}
     */
    public function listOpenIncidents(int $limit = 20): array
    {
        throw_if($limit < 1 || $limit > 50, ToolCallException::class, 'The limit must be between 1 and 50.');

        $incidents = array_values($this->incidentDiagnosisAccessService
            ->listOpen($this->user, $limit)
            ->map(fn ($incident): array => $this->incidentDiagnosisAccessService->payload($incident))
            ->all());

        return ['incidents' => $incidents, 'count' => count($incidents)];
    }

    /**
     * Return the ranked probable-cause diagnosis for one incident visible to the authenticated user.
     *
     * @return array<string, mixed>
     */
    public function diagnoseIncident(string $incident_id, bool $recompute = false): array
    {
        try {
            $incident = $this->incidentDiagnosisAccessService->findVisible($this->user, $incident_id);
        } catch (ModelNotFoundException) {
            throw new ToolCallException('Incident not found or not accessible.');
        }

        return $recompute
            ? $this->incidentDiagnosisAccessService->recompute($incident)
            : $this->incidentDiagnosisAccessService->payloadWithBackfill($incident);
    }
}
