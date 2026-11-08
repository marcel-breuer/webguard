<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentDiagnosisTrigger;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Single authorization boundary for reading and recomputing incident
 * diagnoses. Only monitorings the user owns or shares through a team count.
 */
final class IncidentDiagnosisAccessService
{
    public function __construct(
        private readonly IncidentDiagnosisService $incidentDiagnosisService,
        private readonly IncidentDiagnosisPresenter $incidentDiagnosisPresenter,
    ) {}

    /**
     * @throws ModelNotFoundException<Incident>
     */
    public function findVisible(User $user, string $incidentId, ?string $monitoringId = null): Incident
    {
        return $this->visibleQuery($user)
            ->when($monitoringId !== null, static fn (Builder $builder): Builder => $builder->where('monitoring_id', $monitoringId))
            ->with('monitoring')
            ->findOrFail($incidentId);
    }

    /**
     * @return Collection<int, Incident>
     */
    public function listOpen(User $user, int $limit): Collection
    {
        return $this->visibleQuery($user)
            ->whereNull('up_at')
            ->with('monitoring')
            ->latest('down_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Incident $incident): array
    {
        $snapshot = $incident->diagnosis_snapshot;

        return [
            'incident' => $this->incidentDiagnosisPresenter->incident($incident),
            'diagnosis' => $this->incidentDiagnosisPresenter->present(is_array($snapshot) ? $snapshot : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recompute(Incident $incident): array
    {
        $this->incidentDiagnosisService->diagnoseAndStore($incident, IncidentDiagnosisTrigger::RECOMPUTED);

        return $this->payload($incident->refresh()->loadMissing('monitoring'));
    }

    /**
     * Diagnose an incident that has no stored snapshot yet, e.g. one opened
     * before this feature existed.
     *
     * @return array<string, mixed>
     */
    public function payloadWithBackfill(Incident $incident): array
    {
        if ($incident->diagnosis_snapshot === null) {
            $this->incidentDiagnosisService->diagnoseAndStore($incident, IncidentDiagnosisTrigger::ON_DEMAND);
            $incident->refresh()->loadMissing('monitoring');
        }

        return $this->payload($incident);
    }

    /**
     * @return Builder<Incident>
     */
    private function visibleQuery(User $user): Builder
    {
        return Incident::query()->whereHas('monitoring', static fn (Builder $builder): Builder => $builder->visibleTo($user));
    }
}
