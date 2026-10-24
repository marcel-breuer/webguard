<?php

declare(strict_types=1);

namespace App\Http\Resources\External;

use App\Models\Incident;
use App\Models\IncidentFollowUp;
use App\Models\IncidentTimelineEvent;
use App\Models\IncidentUpdate;
use App\Services\IncidentTimelineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Incident
 */
final class MobileIncidentWorkspaceResource extends JsonResource
{
    public function __construct(Incident $resource, private readonly IncidentTimelineService $incidentTimelineService)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Incident $incident */
        $incident = $this->resource;

        return [
            'id' => $incident->id,
            'monitoring' => [
                'id' => $incident->monitoring->id,
                'name' => $incident->monitoring->name,
                'target' => $incident->monitoring->target,
            ],
            'lifecycle' => [
                'state' => $incident->up_at === null ? 'open' : 'resolved',
                'opened_at' => $incident->down_at?->toIso8601String(),
                'resolved_at' => $incident->up_at?->toIso8601String(),
            ],
            'metadata' => [
                'incident_type' => $incident->incident_type?->value,
                'severity' => $incident->severity?->value,
                'affected_service' => $incident->affected_service,
                'customer_impact' => $incident->customer_impact?->value,
                'contributing_category' => $incident->contributing_category?->value,
                'problem_description' => $incident->problem_description,
                'resolution_description' => $incident->resolution_description,
            ],
            'readiness' => [
                'can_publish_update' => true,
                'requires_public_update' => $incident->up_at === null && $incident->updates->whereNull('suppressed_at')->isEmpty(),
                'update_count' => $incident->updates->whereNull('suppressed_at')->count(),
            ],
            'updates' => $incident->updates
                ->map(fn (IncidentUpdate $incidentUpdate): array => [
                    'id' => $incidentUpdate->id,
                    'status' => $incidentUpdate->status->value,
                    'message' => $incidentUpdate->message,
                    'is_automatic' => str_starts_with((string) $incidentUpdate->mobile_idempotency_key, 'webguard-auto-status-'),
                    'suppressed_at' => $incidentUpdate->suppressed_at?->toIso8601String(),
                    'published_at' => $incidentUpdate->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'follow_ups' => $incident->followUps
                ->map(fn (IncidentFollowUp $incidentFollowUp): array => [
                    'id' => $incidentFollowUp->id,
                    'title' => $incidentFollowUp->title,
                    'description' => $incidentFollowUp->description,
                    'status' => $incidentFollowUp->status->value,
                    'assigned_user' => $incidentFollowUp->assignedUser === null ? null : [
                        'id' => $incidentFollowUp->assignedUser->id,
                        'name' => $incidentFollowUp->assignedUser->name,
                    ],
                    'due_at' => $incidentFollowUp->due_at?->toDateString(),
                    'completed_at' => $incidentFollowUp->completed_at?->toIso8601String(),
                    'external_url' => $incidentFollowUp->external_url,
                    'updated_at' => $incidentFollowUp->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'timeline' => $this->incidentTimelineService->events($incident)
                ->map(fn (array $event): array => [
                    'id' => $event['id'],
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'occurred_at' => $event['occurred_at']->toIso8601String(),
                    'source_type' => $event['source_type'],
                    'can_edit' => $event['source_type'] === 'custom',
                ])
                ->values()
                ->all(),
            'custom_timeline_events' => $incident->timelineEvents
                ->map(fn (IncidentTimelineEvent $incidentTimelineEvent): array => [
                    'id' => $incidentTimelineEvent->id,
                    'title' => $incidentTimelineEvent->title,
                    'description' => $incidentTimelineEvent->description,
                    'occurred_at' => $incidentTimelineEvent->occurred_at->toIso8601String(),
                    'updated_at' => $incidentTimelineEvent->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
        ];
    }
}
