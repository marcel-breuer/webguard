<?php

declare(strict_types=1);

namespace App\Http\Resources\External;

use App\Models\Monitoring;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin StatusPage
 */
final class MobileStatusPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StatusPage $statusPage */
        $statusPage = $this->resource;

        return [
            'id' => $statusPage->id,
            'name' => $statusPage->name,
            'description' => $statusPage->description,
            'publication' => [
                'is_public' => $statusPage->is_public,
                'can_change' => true,
            ],
            'component_count' => (int) ($statusPage->components_count ?? 0),
            'verified_subscriber_count' => (int) ($statusPage->verified_subscriber_count ?? 0),
            'open_incident_count' => (int) ($statusPage->open_incident_count ?? 0),
            'announcement' => $statusPage->relationLoaded('activeAnnouncement') && $statusPage->activeAnnouncement instanceof StatusPageAnnouncement
                ? [
                    'id' => $statusPage->activeAnnouncement->id,
                    'title' => $statusPage->activeAnnouncement->title,
                    'message' => $statusPage->activeAnnouncement->message,
                    'notify_subscribers' => $statusPage->activeAnnouncement->notify_subscribers,
                    'notified_at' => $statusPage->activeAnnouncement->notified_at?->toIso8601String(),
                    'published_at' => $statusPage->activeAnnouncement->created_at?->toIso8601String(),
                ]
                : null,
            'components' => $statusPage->relationLoaded('components')
                ? $statusPage->components
                    ->map(fn (StatusPageComponent $statusPageComponent): array => $this->componentPayload($statusPageComponent))
                    ->values()
                    ->all()
                : [],
            'created_at' => $statusPage->created_at?->toIso8601String(),
            'updated_at' => $statusPage->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(StatusPageComponent $statusPageComponent): array
    {
        return [
            'id' => $statusPageComponent->id,
            'name' => $statusPageComponent->name,
            'description' => $statusPageComponent->description,
            'position' => $statusPageComponent->position,
            'source_type' => $statusPageComponent->source_type->value,
            'monitoring_group' => $statusPageComponent->relationLoaded('monitoringGroup') && $statusPageComponent->monitoringGroup !== null
                ? [
                    'id' => $statusPageComponent->monitoringGroup->id,
                    'name' => $statusPageComponent->monitoringGroup->name,
                    'monitoring_count' => (int) ($statusPageComponent->monitoringGroup->monitorings_count ?? 0),
                ]
                : null,
            'monitorings' => $this->componentMonitorings($statusPageComponent)
                ->map(fn (Monitoring $monitoring): array => [
                    'id' => $monitoring->id,
                    'name' => $monitoring->name,
                    'target' => $monitoring->target,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Monitoring>
     */
    private function componentMonitorings(StatusPageComponent $statusPageComponent): Collection
    {
        if ($statusPageComponent->source_type->value === 'monitoring_group') {
            return $statusPageComponent->monitoringGroup?->monitorings ?? collect();
        }

        return $statusPageComponent->monitorings;
    }
}
