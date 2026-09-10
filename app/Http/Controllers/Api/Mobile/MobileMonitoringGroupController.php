<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\MobileMonitoringGroupRequest;
use App\Http\Resources\External\MobileMonitoringAssignmentResource;
use App\Http\Resources\External\MobileMonitoringGroupResource;
use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use App\Models\User;
use App\Services\Monitorings\MonitoringGroupAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MobileMonitoringGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lengthAwarePaginator = $this->ownedGroups($user)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        $lengthAwarePaginator->setCollection($lengthAwarePaginator->getCollection()->map(
            fn (MonitoringGroup $monitoringGroup): array => MobileMonitoringGroupResource::make($monitoringGroup)->resolve($request)
        ));

        return response()->json($lengthAwarePaginator);
    }

    public function show(Request $request, string $monitoringGroup): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $group = $this->ownedGroup($user, $monitoringGroup)->load([
            'monitorings' => fn ($query) => $query->privateOwnedBy($user)->orderBy('name')->orderBy('id'),
        ])->loadCount([
            'monitorings as assignable_monitoring_count' => fn ($query) => $query->privateOwnedBy($user),
        ]);

        return response()->json([
            'data' => MobileMonitoringGroupResource::make($group)->resolve($request),
        ]);
    }

    public function assignmentOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $search = $request->string('search')->trim()->toString();

        $monitorings = Monitoring::query()
            ->privateOwnedBy($user)
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('target', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        $monitorings->setCollection($monitorings->getCollection()->map(
            fn (Monitoring $monitoring): array => MobileMonitoringAssignmentResource::make($monitoring)->resolve($request)
        ));

        return response()->json($monitorings);
    }

    public function store(
        MobileMonitoringGroupRequest $mobileMonitoringGroupRequest,
        MonitoringGroupAssignmentService $monitoringGroupAssignmentService
    ): JsonResponse {
        /** @var User $user */
        $user = $mobileMonitoringGroupRequest->user();
        abort_if($user->isDemo(), 403);

        $validated = $mobileMonitoringGroupRequest->validated();
        $monitoringIds = $validated['monitoring_ids'] ?? [];
        unset($validated['monitoring_ids']);

        $monitoringGroup = DB::transaction(function () use ($user, $validated, $monitoringIds, $monitoringGroupAssignmentService): MonitoringGroup {
            $monitoringGroup = $user->monitoringGroups()->create($validated);
            $monitoringGroupAssignmentService->syncAssignableMonitorings($monitoringGroup, $user, $monitoringIds);

            return $monitoringGroup;
        });

        return response()->json([
            'data' => MobileMonitoringGroupResource::make($this->groupForResponse($user, $monitoringGroup))->resolve($mobileMonitoringGroupRequest),
        ], 201);
    }

    public function update(
        MobileMonitoringGroupRequest $mobileMonitoringGroupRequest,
        string $monitoringGroup,
        MonitoringGroupAssignmentService $monitoringGroupAssignmentService
    ): JsonResponse {
        /** @var User $user */
        $user = $mobileMonitoringGroupRequest->user();
        abort_if($user->isDemo(), 403);

        $group = $this->ownedGroup($user, $monitoringGroup);
        $validated = $mobileMonitoringGroupRequest->validated();
        $hasMonitoringIds = array_key_exists('monitoring_ids', $validated);
        $monitoringIds = $validated['monitoring_ids'] ?? [];
        $attributes = Arr::except($validated, ['monitoring_ids']);

        DB::transaction(function () use ($group, $attributes, $hasMonitoringIds, $monitoringIds, $user, $monitoringGroupAssignmentService): void {
            if ($attributes !== []) {
                $group->update($attributes);
            }

            if ($hasMonitoringIds) {
                $monitoringGroupAssignmentService->syncAssignableMonitorings($group, $user, $monitoringIds);
            }
        });

        return response()->json([
            'data' => MobileMonitoringGroupResource::make($this->groupForResponse($user, $group))->resolve($mobileMonitoringGroupRequest),
        ]);
    }

    public function destroy(Request $request, string $monitoringGroup): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isDemo(), 403);

        $this->ownedGroup($user, $monitoringGroup)->delete();

        return response()->json(status: 204);
    }

    /**
     * @return Builder<MonitoringGroup>
     */
    private function ownedGroups(User $user): Builder
    {
        return $user->monitoringGroups()->getQuery()->withCount([
            'monitorings as assignable_monitoring_count' => fn ($query) => $query->privateOwnedBy($user),
        ]);
    }

    private function ownedGroup(User $user, string $monitoringGroup): MonitoringGroup
    {
        return $this->ownedGroups($user)->whereKey($monitoringGroup)->firstOrFail();
    }

    private function groupForResponse(User $user, MonitoringGroup $monitoringGroup): MonitoringGroup
    {
        return $this->ownedGroups($user)
            ->with(['monitorings' => fn ($query) => $query->privateOwnedBy($user)->orderBy('name')->orderBy('id')])
            ->whereKey($monitoringGroup->id)
            ->firstOrFail();
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }
}
