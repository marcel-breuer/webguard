<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Enums\MonitoringType;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalUiMonitoringRequest;
use App\Models\Monitoring;
use App\Models\User;
use App\Services\Monitorings\MonitoringGroupAssignmentService;
use App\Services\Monitorings\ResetMonitoringResultsService;
use App\Support\MonitoringPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitoringManagementController extends Controller
{
    public function store(InternalUiMonitoringRequest $internalUiMonitoringRequest, MonitoringGroupAssignmentService $monitoringGroupAssignmentService): JsonResponse
    {
        /** @var User $user */
        $user = $internalUiMonitoringRequest->user();
        abort_if($user->isDemo(), 403);

        if ($user->monitorings()->whereNull('team_id')->count() >= $user->package->monitoring_limit
            && blank($internalUiMonitoringRequest->input('team_id'))) {
            return response()->json(['message' => __('monitoring.messages.limit_reached')], 422);
        }

        $validated = $internalUiMonitoringRequest->validated();
        $teamId = $validated['team_id'] ?? null;
        $groupIds = $validated['group_ids'] ?? [];
        unset($validated['group_ids'], $validated['team_id']);

        if ($teamId !== null && $groupIds !== []) {
            throw ValidationException::withMessages([
                'group_ids' => [__('monitoring_group.validation.monitoring_not_manageable')],
            ]);
        }

        $monitoring = DB::transaction(function () use ($teamId, $monitoringGroupAssignmentService, $groupIds, $user, $validated): Monitoring {
            $payload = MonitoringPayload::prepareStore($validated);

            if ($teamId !== null) {
                $payload['user_id'] = null;
                $payload['team_id'] = $teamId;
                $payload['created_by_user_id'] = $user->id;

                return Monitoring::query()->create($payload);
            }

            $monitoring = $user->monitorings()->create($payload);
            $monitoringGroupAssignmentService->syncGroupsForPrivateMonitoring($monitoring, $user, $groupIds);

            return $monitoring;
        });

        return response()->json(['data' => $this->successPayload($monitoring, $user)], 201);
    }

    public function update(InternalUiMonitoringRequest $internalUiMonitoringRequest, Monitoring $monitoring, MonitoringGroupAssignmentService $monitoringGroupAssignmentService): JsonResponse
    {
        /** @var User $user */
        $user = $internalUiMonitoringRequest->user();
        abort_if($user->isDemo(), 403);
        abort_unless($monitoring->isManageableBy($user), 403);

        $validated = $internalUiMonitoringRequest->validated();
        $hasGroupIds = array_key_exists('group_ids', $validated);
        $groupIds = $validated['group_ids'] ?? [];
        unset($validated['group_ids'], $validated['team_id']);

        if (in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            $validated['http_headers'] = $monitoring->http_headers ?? [];
            $validated['auth_password'] = $internalUiMonitoringRequest->boolean('clear_auth_password')
                ? null
                : $monitoring->auth_password;
        }

        if ($hasGroupIds && ! $monitoring->isPrivateOwned()) {
            throw ValidationException::withMessages([
                'group_ids' => [__('monitoring_group.validation.monitoring_not_manageable')],
            ]);
        }

        $payload = MonitoringPayload::prepareUpdate($validated, $monitoring);
        $payload['public_label_enabled'] = (bool) ($payload['public_label_enabled'] ?? false);

        DB::transaction(function () use ($monitoringGroupAssignmentService, $groupIds, $hasGroupIds, $monitoring, $payload, $user): void {
            $monitoring->update($payload);

            if ($hasGroupIds) {
                $monitoringGroupAssignmentService->syncGroupsForPrivateMonitoring($monitoring, $user, $groupIds);
            }
        });

        return response()->json(['data' => $this->successPayload($monitoring->refresh(), $user)]);
    }

    public function destroy(Request $request, Monitoring $monitoring): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isDemo(), 403);
        abort_unless($monitoring->isManageableBy($user), 403);

        $monitoring->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function resetResults(Request $request, Monitoring $monitoring, ResetMonitoringResultsService $resetMonitoringResultsService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isDemo(), 403);
        abort_unless($monitoring->isManageableBy($user), 403);

        $resetMonitoringResultsService->reset($monitoring);

        return response()->json(['data' => ['reset' => true]]);
    }

    /**
     * @return array{id: string, name: string, type: string, keyword: string|null, lifecycle_status: string, public_label_enabled: bool, check_interval_minutes: int|null, ownership: array{type: string, can_manage: bool, team_id: string|null}, group_assignments: Collection<int, array{id: string, name: string}>}
     */
    private function successPayload(Monitoring $monitoring, User $user): array
    {
        $monitoring->loadMissing([
            'groups' => fn ($query) => $query->where('user_id', $user->id)->select(['id', 'name']),
        ]);

        return [
            'id' => $monitoring->id,
            'name' => $monitoring->name,
            'type' => $monitoring->type->value,
            'keyword' => $monitoring->keyword,
            'lifecycle_status' => $monitoring->status->value,
            'public_label_enabled' => $monitoring->public_label_enabled,
            'check_interval_minutes' => $monitoring->check_interval_minutes,
            'ownership' => [
                'type' => $monitoring->isTeamOwned() ? 'team' : 'private',
                'can_manage' => $monitoring->isManageableBy($user),
                'team_id' => $monitoring->team_id,
            ],
            'group_assignments' => $monitoring->groups
                ->map(static fn ($group): array => ['id' => $group->id, 'name' => $group->name])
                ->values(),
        ];
    }
}
