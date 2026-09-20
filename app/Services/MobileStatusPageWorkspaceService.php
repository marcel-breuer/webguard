<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentFollowUpStatus;
use App\Models\Incident;
use App\Models\IncidentFollowUp;
use App\Models\IncidentTimelineEvent;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class MobileStatusPageWorkspaceService
{
    public function statusPageFor(User $user, string $statusPageId): StatusPage
    {
        return StatusPage::query()
            ->where('user_id', $user->id)
            ->whereKey($statusPageId)
            ->firstOrFail();
    }

    /**
     * @return Builder<StatusPage>
     */
    public function statusPagesFor(User $user): Builder
    {
        return StatusPage::query()
            ->where('user_id', $user->id)
            ->withCount([
                'components',
                'subscriptions as verified_subscriber_count' => fn (Builder $builder) => $builder->whereNotNull('verified_at'),
            ]);
    }

    public function loadWorkspace(StatusPage $statusPage, User $user): StatusPage
    {
        return $statusPage->load([
            'activeAnnouncement',
            'components.monitoringGroup' => fn ($query) => $query->withCount('monitorings'),
            'components.monitorings' => fn ($query) => $query->manageableBy($user)->orderBy('name')->orderBy('id'),
            'components.monitoringGroup.monitorings' => fn ($query) => $query->manageableBy($user)->orderBy('name')->orderBy('id'),
        ])->loadCount([
            'components',
            'subscriptions as verified_subscriber_count' => fn (Builder $builder) => $builder->whereNotNull('verified_at'),
        ]);
    }

    /**
     * @return Builder<Incident>
     */
    public function incidentsFor(StatusPage $statusPage, User $user): Builder
    {
        return Incident::query()
            ->whereIn('monitoring_id', $this->statusPageMonitoringIds($statusPage))
            ->whereHas('monitoring', fn (Builder $builder) => $builder->manageableBy($user))
            ->with([
                'monitoring',
                'updates',
                'followUps.assignedUser',
                'timelineEvents',
            ])
            ->latest('down_at');
    }

    public function incidentFor(StatusPage $statusPage, User $user, string $incidentId): Incident
    {
        return $this->incidentsFor($statusPage, $user)
            ->whereKey($incidentId)
            ->firstOrFail();
    }

    public function openIncidentCount(StatusPage $statusPage, User $user): int
    {
        return $this->incidentsFor($statusPage, $user)
            ->whereNull('up_at')
            ->count();
    }

    public function updatePublication(StatusPage $statusPage, User $user, bool $isPublic): StatusPage
    {
        abort_if($user->isDemo(), 403);

        $statusPage->update(['is_public' => $isPublic]);
        $this->log($user, $statusPage, $isPublic ? 'status_page_published' : 'status_page_unpublished');

        return $statusPage->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{incidentUpdate: IncidentUpdate, created: bool}
     */
    public function createUpdate(Incident $incident, User $user, array $attributes): array
    {
        abort_if($user->isDemo(), 403);

        $idempotencyKey = (string) Arr::pull($attributes, 'idempotency_key');

        return DB::transaction(function () use ($incident, $user, $attributes, $idempotencyKey): array {
            $incidentUpdate = $incident->updates()
                ->where('mobile_idempotency_key', $idempotencyKey)
                ->first();

            if ($incidentUpdate instanceof IncidentUpdate) {
                return ['incidentUpdate' => $incidentUpdate, 'created' => false];
            }

            $incidentUpdate = $incident->updates()->create([
                ...$attributes,
                'mobile_idempotency_key' => $idempotencyKey,
            ]);
            $this->log($user, $incident, 'incident_update_published', ['incident_update_id' => $incidentUpdate->id]);

            return ['incidentUpdate' => $incidentUpdate, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMetadata(Incident $incident, User $user, array $attributes): Incident
    {
        abort_if($user->isDemo(), 403);
        $incident->update($attributes);
        $this->log($user, $incident, 'incident_metadata_updated');

        return $incident->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateReview(Incident $incident, User $user, array $attributes): Incident
    {
        abort_if($user->isDemo(), 403);
        $incident->update($attributes);
        $this->log($user, $incident, 'incident_review_updated');

        return $incident->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{incidentFollowUp: IncidentFollowUp, created: bool}
     */
    public function createFollowUp(Incident $incident, StatusPage $statusPage, User $user, array $attributes): array
    {
        abort_if($user->isDemo(), 403);
        $this->assertAssignedUserIsStatusPageOwner($statusPage, $attributes['assigned_user_id'] ?? null);
        $idempotencyKey = (string) Arr::pull($attributes, 'idempotency_key');

        return DB::transaction(function () use ($incident, $user, $attributes, $idempotencyKey): array {
            $incidentFollowUp = $incident->followUps()
                ->where('mobile_idempotency_key', $idempotencyKey)
                ->first();

            if ($incidentFollowUp instanceof IncidentFollowUp) {
                return ['incidentFollowUp' => $incidentFollowUp, 'created' => false];
            }

            $incidentFollowUp = $incident->followUps()->create([
                ...$attributes,
                'status' => IncidentFollowUpStatus::OPEN,
                'mobile_idempotency_key' => $idempotencyKey,
            ]);
            $this->log($user, $incident, 'incident_follow_up_created', ['incident_follow_up_id' => $incidentFollowUp->id]);

            return ['incidentFollowUp' => $incidentFollowUp, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateFollowUp(IncidentFollowUp $incidentFollowUp, StatusPage $statusPage, User $user, array $attributes): IncidentFollowUp
    {
        abort_if($user->isDemo(), 403);
        $this->assertAssignedUserIsStatusPageOwner($statusPage, $attributes['assigned_user_id'] ?? null);
        $attributes['completed_at'] = $attributes['status'] === IncidentFollowUpStatus::COMPLETED->value
            ? ($incidentFollowUp->completed_at ?? Date::now())
            : null;
        $incidentFollowUp->update($attributes);
        $this->log($user, $incidentFollowUp->incident, 'incident_follow_up_updated', ['incident_follow_up_id' => $incidentFollowUp->id]);

        return $incidentFollowUp->refresh();
    }

    public function deleteFollowUp(IncidentFollowUp $incidentFollowUp, User $user): void
    {
        abort_if($user->isDemo(), 403);
        $incident = $incidentFollowUp->incident;
        $incidentFollowUp->delete();
        $this->log($user, $incident, 'incident_follow_up_deleted', ['incident_follow_up_id' => $incidentFollowUp->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{incidentTimelineEvent: IncidentTimelineEvent, created: bool}
     */
    public function createTimelineEvent(Incident $incident, User $user, array $attributes): array
    {
        abort_if($user->isDemo(), 403);
        $idempotencyKey = (string) Arr::pull($attributes, 'idempotency_key');

        return DB::transaction(function () use ($incident, $user, $attributes, $idempotencyKey): array {
            $incidentTimelineEvent = $incident->timelineEvents()
                ->where('mobile_idempotency_key', $idempotencyKey)
                ->first();

            if ($incidentTimelineEvent instanceof IncidentTimelineEvent) {
                return ['incidentTimelineEvent' => $incidentTimelineEvent, 'created' => false];
            }

            $incidentTimelineEvent = $incident->timelineEvents()->create([
                ...$attributes,
                'source_type' => 'custom',
                'mobile_idempotency_key' => $idempotencyKey,
            ]);
            $this->log($user, $incident, 'incident_timeline_event_created', ['incident_timeline_event_id' => $incidentTimelineEvent->id]);

            return ['incidentTimelineEvent' => $incidentTimelineEvent, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTimelineEvent(IncidentTimelineEvent $incidentTimelineEvent, User $user, array $attributes): IncidentTimelineEvent
    {
        abort_if($user->isDemo(), 403);
        $incidentTimelineEvent->update($attributes);
        $this->log($user, $incidentTimelineEvent->incident, 'incident_timeline_event_updated', ['incident_timeline_event_id' => $incidentTimelineEvent->id]);

        return $incidentTimelineEvent->refresh();
    }

    public function deleteTimelineEvent(IncidentTimelineEvent $incidentTimelineEvent, User $user): void
    {
        abort_if($user->isDemo(), 403);
        $incident = $incidentTimelineEvent->incident;
        $incidentTimelineEvent->delete();
        $this->log($user, $incident, 'incident_timeline_event_deleted', ['incident_timeline_event_id' => $incidentTimelineEvent->id]);
    }

    /**
     * @return Collection<int, string>
     */
    private function statusPageMonitoringIds(StatusPage $statusPage): Collection
    {
        $statusPage->loadMissing(['components.monitorings:id', 'components.monitoringGroup.monitorings:id']);

        return $statusPage->components
            ->flatMap(fn ($component) => $component->source_type->value === 'monitoring_group'
                ? $component->monitoringGroup?->monitorings->pluck('id') ?? []
                : $component->monitorings->pluck('id'))
            ->unique()
            ->values();
    }

    private function assertAssignedUserIsStatusPageOwner(StatusPage $statusPage, ?string $assignedUserId): void
    {
        abort_unless($assignedUserId === null || $assignedUserId === $statusPage->user_id, 422);
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function log(User $user, mixed $subject, string $event, array $properties = []): void
    {
        activity('status_page')
            ->causedBy($user)
            ->performedOn($subject)
            ->event($event)
            ->withProperties(['action' => $event, ...$properties])
            ->log($event);
    }
}
