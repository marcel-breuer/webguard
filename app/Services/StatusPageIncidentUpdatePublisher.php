<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentUpdateStatus;
use App\Jobs\SendStatusPageIncidentUpdateNotifications;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;

final class StatusPageIncidentUpdatePublisher
{
    public function publishInvestigating(Incident $incident): void
    {
        $pages = $this->eligiblePages($incident);
        if ($pages->isEmpty()) {
            return;
        }

        $monitoring = $incident->monitoring;
        $incidentUpdate = $incident->updates()->firstOrCreate(
            ['mobile_idempotency_key' => 'webguard-auto-status-open'],
            [
                'status' => IncidentUpdateStatus::INVESTIGATING,
                'message' => "We are investigating an issue affecting {$monitoring->name}.",
            ],
        );

        if ($incidentUpdate->wasRecentlyCreated) {
            $incident->timelineEvents()->firstOrCreate(
                ['mobile_idempotency_key' => 'webguard-auto-status-open'],
                [
                    'title' => 'Public incident update published',
                    'description' => 'An investigating update was automatically published to the configured public status page.',
                    'occurred_at' => now(),
                    'source_type' => 'status_page',
                ],
            );
        }

        foreach ($pages as $statusPage) {
            SendStatusPageIncidentUpdateNotifications::dispatch($statusPage->id, $incidentUpdate->id, 'investigating')->afterCommit();
        }
    }

    public function publishResolved(Incident $incident): void
    {
        $incidentUpdate = $incident->updates()
            ->where('mobile_idempotency_key', 'webguard-auto-status-open')
            ->first();

        if (! $incidentUpdate instanceof IncidentUpdate) {
            return;
        }

        $pages = $this->eligiblePages($incident);
        if ($pages->isEmpty()) {
            return;
        }

        $monitoring = $incident->monitoring;
        $incidentUpdate->update([
            'status' => IncidentUpdateStatus::RESOLVED,
            'message' => "The incident affecting {$monitoring->name} has been resolved.",
        ]);

        $incident->timelineEvents()->firstOrCreate(
            ['mobile_idempotency_key' => 'webguard-auto-status-resolved'],
            [
                'title' => 'Public incident update published',
                'description' => 'A resolved update was automatically published to the configured public status page.',
                'occurred_at' => now(),
                'source_type' => 'status_page',
            ],
        );

        foreach ($pages as $statusPage) {
            SendStatusPageIncidentUpdateNotifications::dispatch($statusPage->id, $incidentUpdate->id, 'resolved')->afterCommit();
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, StatusPage>
     */
    private function eligiblePages(Incident $incident): \Illuminate\Support\Collection
    {
        return StatusPage::query()
            ->where('is_public', true)
            ->where('auto_publish_incident_updates', true)
            ->whereHas('components', fn ($query) => $query
                ->whereHas('monitorings', fn ($monitorings) => $monitorings->whereKey($incident->monitoring_id))
                ->orWhereHas('monitoringGroup.monitorings', fn ($monitorings) => $monitorings->whereKey($incident->monitoring_id)))
            ->get();
    }
}
