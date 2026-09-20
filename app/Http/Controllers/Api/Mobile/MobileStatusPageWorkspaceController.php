<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\MobileStoreIncidentFollowUpRequest;
use App\Http\Requests\Api\Mobile\MobileStoreIncidentTimelineEventRequest;
use App\Http\Requests\Api\Mobile\MobileStoreIncidentUpdateRequest;
use App\Http\Requests\StatusPages\StoreStatusPageAnnouncementRequest;
use App\Http\Requests\StatusPages\UpdateIncidentFollowUpRequest;
use App\Http\Requests\StatusPages\UpdateIncidentMetadataRequest;
use App\Http\Requests\StatusPages\UpdateIncidentReviewRequest;
use App\Http\Requests\StatusPages\UpdateIncidentTimelineEventRequest;
use App\Http\Requests\StatusPages\UpdateStatusPageAnnouncementRequest;
use App\Http\Resources\External\MobileIncidentWorkspaceResource;
use App\Http\Resources\External\MobileStatusPageResource;
use App\Models\Incident;
use App\Models\IncidentFollowUp;
use App\Models\IncidentTimelineEvent;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\User;
use App\Services\IncidentTimelineService;
use App\Services\MobileStatusPageWorkspaceService;
use App\Services\StatusPageAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileStatusPageWorkspaceController extends Controller
{
    public function __construct(
        private readonly IncidentTimelineService $incidentTimelineService,
        private readonly MobileStatusPageWorkspaceService $mobileStatusPageWorkspaceService,
        private readonly StatusPageAnnouncementService $statusPageAnnouncementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lengthAwarePaginator = $this->mobileStatusPageWorkspaceService->statusPagesFor($user)
            ->latest()
            ->paginate($this->perPage($request));

        $lengthAwarePaginator->getCollection()->each(function (StatusPage $statusPage) use ($user): void {
            $statusPage->setAttribute('open_incident_count', $this->mobileStatusPageWorkspaceService->openIncidentCount($statusPage, $user));
        });
        $lengthAwarePaginator->setCollection($lengthAwarePaginator->getCollection()->map(
            fn (StatusPage $statusPage): array => MobileStatusPageResource::make($statusPage)->resolve($request)
        ));

        return response()->json($lengthAwarePaginator);
    }

    public function show(Request $request, string $statusPage): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $statusPageModel = $this->workspaceStatusPage($user, $statusPage);

        return response()->json([
            'data' => MobileStatusPageResource::make($statusPageModel)->resolve($request),
        ]);
    }

    public function updatePublication(Request $request, string $statusPage): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate(['is_public' => ['required', 'boolean']]);
        $statusPageModel = $this->mobileStatusPageWorkspaceService->updatePublication(
            $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage),
            $user,
            (bool) $validated['is_public'],
        );

        return response()->json([
            'data' => MobileStatusPageResource::make($this->workspaceStatusPage($user, $statusPageModel->id))->resolve($request),
        ]);
    }

    public function storeAnnouncement(StoreStatusPageAnnouncementRequest $storeStatusPageAnnouncementRequest, string $statusPage): JsonResponse
    {
        /** @var User $user */
        $user = $storeStatusPageAnnouncementRequest->user();
        $this->statusPageAnnouncementService->create(
            $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage),
            $user,
            $storeStatusPageAnnouncementRequest->validated(),
        );

        return response()->json([
            'data' => MobileStatusPageResource::make($this->workspaceStatusPage($user, $statusPage))->resolve($storeStatusPageAnnouncementRequest),
        ], 201);
    }

    public function updateAnnouncement(UpdateStatusPageAnnouncementRequest $updateStatusPageAnnouncementRequest, string $statusPage, string $announcement): JsonResponse
    {
        /** @var User $user */
        $user = $updateStatusPageAnnouncementRequest->user();
        $this->statusPageAnnouncementService->update(
            $this->announcementFor($this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage), $announcement),
            $user,
            $updateStatusPageAnnouncementRequest->validated(),
        );

        return response()->json([
            'data' => MobileStatusPageResource::make($this->workspaceStatusPage($user, $statusPage))->resolve($updateStatusPageAnnouncementRequest),
        ]);
    }

    public function dismissAnnouncement(Request $request, string $statusPage, string $announcement): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->statusPageAnnouncementService->dismiss(
            $this->announcementFor($this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage), $announcement),
            $user,
        );

        return response()->json(status: 204);
    }

    public function incidents(Request $request, string $statusPage): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $statusPageModel = $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage);
        $validated = $request->validate(['state' => ['nullable', 'in:open,resolved']]);
        $lengthAwarePaginator = $this->mobileStatusPageWorkspaceService->incidentsFor($statusPageModel, $user)
            ->when(
                ($validated['state'] ?? null) === 'open',
                fn ($query) => $query->whereNull('up_at'),
                fn ($query) => ($validated['state'] ?? null) === 'resolved' ? $query->whereNotNull('up_at') : $query,
            )
            ->paginate($this->perPage($request));

        $lengthAwarePaginator->setCollection($lengthAwarePaginator->getCollection()->map(
            fn (Incident $incident): array => $this->incidentResource($incident)->resolve($request)
        ));

        return response()->json($lengthAwarePaginator);
    }

    public function showIncident(Request $request, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);

        return response()->json(['data' => $this->incidentResource($incidentModel)->resolve($request)]);
    }

    public function storeIncidentUpdate(MobileStoreIncidentUpdateRequest $mobileStoreIncidentUpdateRequest, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $mobileStoreIncidentUpdateRequest->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $result = $this->mobileStatusPageWorkspaceService->createUpdate($incidentModel, $user, $mobileStoreIncidentUpdateRequest->validated());

        return response()->json([
            'data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($mobileStoreIncidentUpdateRequest),
        ], $result['created'] ? 201 : 200);
    }

    public function updateMetadata(UpdateIncidentMetadataRequest $updateIncidentMetadataRequest, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $updateIncidentMetadataRequest->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $this->mobileStatusPageWorkspaceService->updateMetadata($incidentModel, $user, $updateIncidentMetadataRequest->validated());

        return response()->json(['data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($updateIncidentMetadataRequest)]);
    }

    public function updateReview(UpdateIncidentReviewRequest $updateIncidentReviewRequest, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $updateIncidentReviewRequest->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $this->mobileStatusPageWorkspaceService->updateReview($incidentModel, $user, $updateIncidentReviewRequest->validated());

        return response()->json(['data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($updateIncidentReviewRequest)]);
    }

    public function storeFollowUp(MobileStoreIncidentFollowUpRequest $mobileStoreIncidentFollowUpRequest, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $mobileStoreIncidentFollowUpRequest->user();
        $statusPageModel = $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage);
        $incidentModel = $this->mobileStatusPageWorkspaceService->incidentFor($statusPageModel, $user, $incident);
        $result = $this->mobileStatusPageWorkspaceService->createFollowUp($incidentModel, $statusPageModel, $user, $mobileStoreIncidentFollowUpRequest->validated());

        return response()->json([
            'data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($mobileStoreIncidentFollowUpRequest),
        ], $result['created'] ? 201 : 200);
    }

    public function updateFollowUp(UpdateIncidentFollowUpRequest $updateIncidentFollowUpRequest, string $statusPage, string $incident, string $incidentFollowUp): JsonResponse
    {
        /** @var User $user */
        $user = $updateIncidentFollowUpRequest->user();
        $statusPageModel = $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage);
        $incidentModel = $this->mobileStatusPageWorkspaceService->incidentFor($statusPageModel, $user, $incident);
        $followUp = $this->followUpFor($incidentModel, $incidentFollowUp);
        $this->mobileStatusPageWorkspaceService->updateFollowUp($followUp, $statusPageModel, $user, $updateIncidentFollowUpRequest->validated());

        return response()->json(['data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($updateIncidentFollowUpRequest)]);
    }

    public function destroyFollowUp(Request $request, string $statusPage, string $incident, string $incidentFollowUp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $this->mobileStatusPageWorkspaceService->deleteFollowUp($this->followUpFor($incidentModel, $incidentFollowUp), $user);

        return response()->json(status: 204);
    }

    public function storeTimelineEvent(MobileStoreIncidentTimelineEventRequest $mobileStoreIncidentTimelineEventRequest, string $statusPage, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $mobileStoreIncidentTimelineEventRequest->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $result = $this->mobileStatusPageWorkspaceService->createTimelineEvent($incidentModel, $user, $mobileStoreIncidentTimelineEventRequest->validated());

        return response()->json([
            'data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($mobileStoreIncidentTimelineEventRequest),
        ], $result['created'] ? 201 : 200);
    }

    public function updateTimelineEvent(UpdateIncidentTimelineEventRequest $updateIncidentTimelineEventRequest, string $statusPage, string $incident, string $incidentTimelineEvent): JsonResponse
    {
        /** @var User $user */
        $user = $updateIncidentTimelineEventRequest->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $timelineEvent = $this->timelineEventFor($incidentModel, $incidentTimelineEvent);
        $this->mobileStatusPageWorkspaceService->updateTimelineEvent($timelineEvent, $user, $updateIncidentTimelineEventRequest->validated());

        return response()->json(['data' => $this->incidentResource($this->incidentFor($user, $statusPage, $incident))->resolve($updateIncidentTimelineEventRequest)]);
    }

    public function destroyTimelineEvent(Request $request, string $statusPage, string $incident, string $incidentTimelineEvent): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $incidentModel = $this->incidentFor($user, $statusPage, $incident);
        $this->mobileStatusPageWorkspaceService->deleteTimelineEvent($this->timelineEventFor($incidentModel, $incidentTimelineEvent), $user);

        return response()->json(status: 204);
    }

    private function workspaceStatusPage(User $user, string $statusPage): StatusPage
    {
        $statusPageModel = $this->mobileStatusPageWorkspaceService->loadWorkspace(
            $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage),
            $user,
        );
        $statusPageModel->setAttribute('open_incident_count', $this->mobileStatusPageWorkspaceService->openIncidentCount($statusPageModel, $user));

        return $statusPageModel;
    }

    private function incidentFor(User $user, string $statusPage, string $incident): Incident
    {
        return $this->mobileStatusPageWorkspaceService->incidentFor(
            $this->mobileStatusPageWorkspaceService->statusPageFor($user, $statusPage),
            $user,
            $incident,
        );
    }

    private function announcementFor(StatusPage $statusPage, string $announcement): StatusPageAnnouncement
    {
        return $statusPage->announcements()->whereKey($announcement)->firstOrFail();
    }

    private function followUpFor(Incident $incident, string $incidentFollowUp): IncidentFollowUp
    {
        return $incident->followUps()->whereKey($incidentFollowUp)->firstOrFail();
    }

    private function timelineEventFor(Incident $incident, string $incidentTimelineEvent): IncidentTimelineEvent
    {
        return $incident->timelineEvents()->whereKey($incidentTimelineEvent)->firstOrFail();
    }

    private function incidentResource(Incident $incident): MobileIncidentWorkspaceResource
    {
        return new MobileIncidentWorkspaceResource($incident, $this->incidentTimelineService);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }
}
