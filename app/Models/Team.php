<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'description',
    'created_by_user_id',
])]
#[Table(name: 'teams', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasMany<Monitoring, $this>
     */
    public function monitorings(): HasMany
    {
        return $this->hasMany(Monitoring::class);
    }

    public function isMember(User $user): bool
    {
        return $this->memberships()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isAdmin(User $user): bool
    {
        return $this->memberships()
            ->where('user_id', $user->id)
            ->where('role', TeamRole::ADMIN)
            ->exists();
    }

    public function adminCount(): int
    {
        return $this->memberships()
            ->where('role', TeamRole::ADMIN)
            ->count();
    }

    /**
     * @param  Builder<Team>  $builder
     * @return Builder<Team>
     */
    #[Scope]
    protected function visibleTo(Builder $builder, User $user): Builder
    {
        return $builder->whereHas('memberships', function (Builder $builder) use ($user): void {
            $builder->where('user_id', $user->id);
        });
    }

    /**
     * @param  Builder<Team>  $builder
     * @return Builder<Team>
     */
    #[Scope]
    protected function administeredBy(Builder $builder, User $user): Builder
    {
        return $builder->whereHas('memberships', function (Builder $builder) use ($user): void {
            $builder->where('user_id', $user->id)
                ->where('role', TeamRole::ADMIN);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
