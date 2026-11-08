<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentDiagnosisTrigger;
use App\Models\Incident;
use Illuminate\Support\Facades\Date;

class IncidentDiagnosisService
{
    public const int SNAPSHOT_VERSION = 1;

    public function __construct(
        private readonly IncidentDiagnosisEvidenceCollector $incidentDiagnosisEvidenceCollector,
        private readonly IncidentDiagnosisRules $incidentDiagnosisRules,
    ) {}

    /**
     * Build a diagnosis snapshot without persisting it.
     *
     * @return array<string, mixed>|null
     */
    public function diagnose(Incident $incident, IncidentDiagnosisTrigger $incidentDiagnosisTrigger): ?array
    {
        $evidence = $this->incidentDiagnosisEvidenceCollector->collect($incident);

        if ($evidence === null) {
            return null;
        }

        $hypotheses = $this->incidentDiagnosisRules->evaluate($evidence);

        return [
            'version' => self::SNAPSHOT_VERSION,
            'generated_at' => Date::now()->toIso8601String(),
            'reference_at' => $evidence->referenceAt,
            'trigger' => $incidentDiagnosisTrigger->value,
            'primary_category' => $hypotheses[0]['category'] ?? null,
            'hypotheses' => $hypotheses,
            'signals' => $evidence->toArray(),
        ];
    }

    /**
     * Build and persist the diagnosis snapshot on the incident.
     *
     * @return array<string, mixed>|null
     */
    public function diagnoseAndStore(Incident $incident, IncidentDiagnosisTrigger $incidentDiagnosisTrigger): ?array
    {
        $snapshot = $this->diagnose($incident, $incidentDiagnosisTrigger);

        if ($snapshot === null) {
            return null;
        }

        // Diagnosis is derived data; skip observers so it never re-publishes incident updates.
        $incident->forceFill([
            'diagnosis_snapshot' => $snapshot,
            'diagnosed_at' => Date::now(),
        ])->saveQuietly();

        return $snapshot;
    }
}
