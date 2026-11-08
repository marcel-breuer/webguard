<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ownership, visibility and management rules of a monitoring for users and teams.
 *
 * @mixin Monitoring
 */
trait HasMonitoringOwnership
{
    public function isTeamOwned(): bool
    {
        return $this->team_id !== null;
    }

    public function isPrivateOwned(): bool
    {
        return $this->user_id !== null && $this->team_id === null;
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        if ($this->team_id === null) {
            return false;
        }

        return $this->team()
            ->whereHas('memberships', function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id);
            })
            ->exists();
    }

    public function isManageableBy(User $user): bool
    {
        if ($this->user_id === $user->id && $this->team_id === null) {
            return true;
        }

        if ($this->team_id === null) {
            return false;
        }

        return $this->team()
            ->whereHas('memberships', function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id)
                    ->where('role', TeamRole::ADMIN);
            })
            ->exists();
    }

    /**
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    #[Scope]
    protected function visibleTo(Builder $builder, User $user): Builder
    {
        return $builder->where(function (Builder $builder) use ($user): void {
            $builder->where('user_id', $user->id)
                ->orWhereHas('team.memberships', function (Builder $builder) use ($user): void {
                    $builder->where('user_id', $user->id);
                });
        });
    }

    /**
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    #[Scope]
    protected function manageableBy(Builder $builder, User $user): Builder
    {
        return $builder->where(function (Builder $builder) use ($user): void {
            $builder->where(function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id)
                    ->whereNull('team_id');
            })
                ->orWhereHas('team.memberships', function (Builder $builder) use ($user): void {
                    $builder->where('user_id', $user->id)
                        ->where('role', TeamRole::ADMIN);
                });
        });
    }

    /**
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    #[Scope]
    protected function privateOwnedBy(Builder $builder, User $user): Builder
    {
        return $builder->where('user_id', $user->id)
            ->whereNull('team_id');
    }
}
