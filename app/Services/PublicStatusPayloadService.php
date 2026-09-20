<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\StatusPageComponentSource;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Support\PublicStatusResourceResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class PublicStatusPayloadService
{
    public function __construct(
        private readonly MonitoringAvailabilityService $monitoringAvailabilityService,
        private readonly MonitoringIncidentService $monitoringIncidentService,
        private readonly MonitoringStatusService $monitoringStatusService,
        private readonly PublicStatusResourceResolver $publicStatusResourceResolver,
        private readonly StatusPageUptimeCalendarService $statusPageUptimeCalendarService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(string $identifier): array
    {
        $resource = $this->publicStatusResourceResolver->resolve($identifier);

        return $resource instanceof StatusPage
            ? $this->statusPagePayload($resource)
            : $this->monitoringPayload($resource);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPagePayload(StatusPage $statusPage): array
    {
        abort_unless($statusPage->is_public, 404);

        $statusPage->loadMissing([
            'activeAnnouncement',
            'components.monitorings' => fn ($query) => $query->withoutGlobalScope('user')
                ->with(['latestIncident', 'latestResponseResult']),
            'components.monitoringGroup.monitorings' => fn ($query) => $query->withoutGlobalScope('user')
                ->with(['latestIncident', 'latestResponseResult'])
                ->orderBy('name'),
        ]);

        $components = $statusPage->components->map(function (StatusPageComponent $statusPageComponent): array {
            $monitorings = $this->componentMonitorings($statusPageComponent)->map(function (Monitoring $monitoring): array {
                $status = $this->monitoringStatus($monitoring);

                return [
                    'id' => $monitoring->id,
                    'name' => $monitoring->name,
                    'type' => $monitoring->type->value,
                    'status' => $status,
                    'is_under_maintenance' => $monitoring->isUnderMaintenance(),
                    'last_checked_at' => $monitoring->latestResponseResult?->updated_at?->toIso8601String(),
                ];
            })->values();

            return [
                'id' => $statusPageComponent->id,
                'name' => $statusPageComponent->name,
                'description' => $statusPageComponent->description,
                'status' => $this->aggregateStatus($monitorings->pluck('status')),
                'has_maintenance' => $monitorings->contains(
                    static fn (array $monitoring): bool => $monitoring['is_under_maintenance'] === true
                ),
                'monitorings' => $monitorings->all(),
            ];
        })->values();

        return [
            'kind' => 'status_page',
            'identifier' => $statusPage->id,
            'name' => $statusPage->name,
            'description' => $statusPage->description,
            'status' => $this->aggregateStatus($components->pluck('status')),
            'announcement' => $statusPage->activeAnnouncement === null ? null : [
                'title' => $statusPage->activeAnnouncement->title,
                'message' => $statusPage->activeAnnouncement->message,
                'published_at' => $statusPage->activeAnnouncement->created_at?->toIso8601String(),
            ],
            'components' => $components->all(),
            'incidents' => $this->statusPageIncidents($statusPage),
            'uptime_calendar' => $this->statusPageUptimeCalendarService->getLast30Days($statusPage)->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitoringPayload(Monitoring $monitoring): array
    {
        abort_unless($monitoring->public_label_enabled, 404);

        $monitoring->loadMissing([
            'domainResult',
            'latestIncident',
            'latestResponseResult',
            'sslResult',
        ]);
        $statusSince = $this->monitoringStatusService->getStatusSince($monitoring);
        $statusNow = $this->monitoringStatusService->getStatusNow($monitoring);
        $status = $this->normalizeStatus($statusSince['status'] ?? $statusNow['status'] ?? MonitoringStatus::UNKNOWN->value);
        $rangeSummaries = $this->monitoringAvailabilityService->getUptimeDowntimesForRanges($monitoring, [7, 30, 90]);

        return [
            'kind' => 'monitoring',
            'identifier' => $monitoring->id,
            'name' => $monitoring->name,
            'description' => null,
            'status' => $status,
            'monitoring' => [
                'type' => $monitoring->type->value,
                'target' => $monitoring->type === MonitoringType::HEARTBEAT ? null : $monitoring->target,
                'is_under_maintenance' => $monitoring->isUnderMaintenance(),
                'status_since' => $statusSince['since'] ?? null,
                'last_checked_at' => $statusNow['checked_at'] ?? null,
                'http_status_code' => $monitoring->latestResponseResult?->http_status_code,
                'maintenance_window' => $monitoring->currentOrUpcomingMaintenanceWindow(),
                'uptime' => collect($rangeSummaries)->mapWithKeys(
                    fn ($summary, string $days): array => [$days => $summary->toArray()]
                )->all(),
            ],
            'incidents' => $this->monitoringIncidentService
                ->getIncidents($monitoring, Date::now()->subDays(90), Date::now())
                ->take(10)
                ->map(fn ($incident): array => [
                    'monitoring_name' => $monitoring->name,
                    'down_at' => $incident->downAt,
                    'up_at' => $incident->upAt,
                    'updates' => [],
                ])
                ->values()
                ->all(),
        ];
    }

    private function monitoringStatus(Monitoring $monitoring): string
    {
        $statusSince = $this->monitoringStatusService->getStatusSince($monitoring);
        $statusNow = $this->monitoringStatusService->getStatusNow($monitoring);

        return $this->normalizeStatus($statusSince['status'] ?? $statusNow['status'] ?? MonitoringStatus::UNKNOWN->value);
    }

    /**
     * @param  Collection<int, string>  $statuses
     */
    private function aggregateStatus(Collection $statuses): string
    {
        if ($statuses->isEmpty()) {
            return MonitoringStatus::UNKNOWN->value;
        }

        if ($statuses->contains(MonitoringStatus::DOWN->value)) {
            return MonitoringStatus::DOWN->value;
        }

        if ($statuses->contains(MonitoringStatus::UNKNOWN->value)) {
            return MonitoringStatus::UNKNOWN->value;
        }

        return MonitoringStatus::UP->value;
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status instanceof MonitoringStatus) {
            return $status->value;
        }

        return MonitoringStatus::tryFrom(mb_strtolower((string) $status))?->value ?? MonitoringStatus::UNKNOWN->value;
    }

    /**
     * @return Collection<int, Monitoring>
     */
    private function componentMonitorings(StatusPageComponent $statusPageComponent): Collection
    {
        if ($statusPageComponent->source_type === StatusPageComponentSource::MONITORING_GROUP) {
            return $statusPageComponent->monitoringGroup?->monitorings ?? collect();
        }

        return $statusPageComponent->monitorings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusPageIncidents(StatusPage $statusPage): array
    {
        $monitoringIds = $statusPage->components
            ->flatMap(fn (StatusPageComponent $statusPageComponent): Collection => $this->componentMonitorings($statusPageComponent)->pluck('id'))
            ->unique()
            ->values();

        if ($monitoringIds->isEmpty()) {
            return [];
        }

        return Incident::query()
            ->with([
                'monitoring' => fn ($query) => $query->withoutGlobalScope('user'),
                'updates',
            ])
            ->whereIn('monitoring_id', $monitoringIds)
            ->whereBetween('down_at', [Date::now()->subDays(90)->startOfDay(), Date::now()->endOfDay()])
            ->latest('down_at')
            ->limit(10)
            ->get()
            ->map(fn (Incident $incident): array => [
                'monitoring_name' => $incident->monitoring->name,
                'down_at' => $incident->down_at?->toIso8601String(),
                'up_at' => $incident->up_at?->toIso8601String(),
                'updates' => $incident->updates->map(fn ($update): array => [
                    'status' => $update->status->value,
                    'message' => $update->message,
                    'published_at' => $update->created_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
