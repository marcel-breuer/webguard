<?php

declare(strict_types=1);

namespace App\Http\Resources\External;

use App\Enums\TeamRole;
use App\Models\Monitoring;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Http\Request;

final class MonitoringResource extends CompatibilityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Monitoring $monitoring */
        $monitoring = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $payload = parent::toArray($request);

        $payload['ownership'] = [
            'type' => $monitoring->isTeamOwned() ? 'team' : 'private',
            'user_id' => $monitoring->user_id,
            'team_id' => $monitoring->team_id,
            'can_manage' => $this->canManage($monitoring, $user),
        ];
        $payload['check_interval_minutes'] = $monitoring->check_interval_minutes;
        $payload['group_assignments'] = $monitoring->relationLoaded('groups')
            ? $monitoring->groups
                ->map(fn ($monitoringGroup): array => [
                    'id' => $monitoringGroup->id,
                    'name' => $monitoringGroup->name,
                    'description' => $monitoringGroup->description,
                ])
                ->values()
                ->all()
            : [];

        return $payload;
    }

    private function canManage(Monitoring $monitoring, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($monitoring->isPrivateOwned()) {
            return $monitoring->user_id === $user->id;
        }

        if ($monitoring->team?->relationLoaded('memberships')) {
            return $monitoring->team->memberships
                ->contains(fn (TeamMembership $teamMembership): bool => $teamMembership->user_id === $user->id && $teamMembership->role === TeamRole::ADMIN);
        }

        return $monitoring->isManageableBy($user);
    }
}
