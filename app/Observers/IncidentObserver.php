<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\IncidentDiagnosisTrigger;
use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Services\IncidentDiagnosisService;
use App\Services\MonitoringStatsCache;
use App\Services\OperationsOverviewCache;
use App\Services\StatusPageIncidentUpdatePublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentObserver
{
    /**
     * Handle the Incident "created" event.
     */
    public function created(Incident $incident): void
    {
        resolve(OperationsOverviewCache::class)->flush();
        $this->flushMonitoringStats($incident);
        $this->storeDiagnosis($incident);

        MonitoringNotification::query()->create([
            'monitoring_id' => $incident->monitoring_id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'DOWN',
            'metadata' => $incident->consensus_snapshot !== null
                ? ['regional_consensus' => $incident->consensus_snapshot]
                : null,
            'read' => false,
            'sent' => false,
        ]);

        resolve(StatusPageIncidentUpdatePublisher::class)->publishInvestigating($incident);
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        resolve(OperationsOverviewCache::class)->flush();
        $this->flushMonitoringStats($incident);

        if ($incident->wasChanged('up_at') && $incident->up_at !== null) {
            MonitoringNotification::query()->create([
                'monitoring_id' => $incident->monitoring_id,
                'type' => NotificationType::STATUS_CHANGE,
                'message' => 'UP',
                'read' => false,
                'sent' => false,
            ]);

            resolve(StatusPageIncidentUpdatePublisher::class)->publishResolved($incident);
        }
    }

    /**
     * A failing diagnosis must never prevent an incident from opening.
     */
    private function storeDiagnosis(Incident $incident): void
    {
        try {
            resolve(IncidentDiagnosisService::class)->diagnoseAndStore($incident, IncidentDiagnosisTrigger::INCIDENT_OPENED);
        } catch (Throwable $throwable) {
            Log::warning('Incident diagnosis could not be stored.', [
                'incident_id' => $incident->getKey(),
                'exception' => $throwable::class,
            ]);
        }
    }

    private function flushMonitoringStats(Incident $incident): void
    {
        $monitoring = $incident->monitoring;

        if ($monitoring instanceof Monitoring) {
            resolve(MonitoringStatsCache::class)->flush($monitoring);
        }
    }
}
