<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceWindowRecurrence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'monitoring_id',
    'monitoring_group_id',
    'starts_at',
    'duration_minutes',
    'recurrence',
    'repeat_until',
    'timezone',
    'enabled',
])]
#[Table(name: 'maintenance_windows', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;

    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    public function monitoringGroup(): BelongsTo
    {
        return $this->belongsTo(MonitoringGroup::class);
    }

    public function isManageableBy(User $user): bool
    {
        if ($this->monitoring_id !== null) {
            return $this->monitoring?->isManageableBy($user) ?? Monitoring::query()
                ->manageableBy($user)
                ->whereKey($this->monitoring_id)
                ->exists();
        }

        return $this->monitoringGroup?->user_id === $user->id
            || MonitoringGroup::query()
                ->whereKey($this->monitoring_group_id)
                ->where('user_id', $user->id)
                ->exists();
    }

    /**
     * @param  Builder<MaintenanceWindow>  $builder
     * @return Builder<MaintenanceWindow>
     */
    #[Scope]
    protected function enabled(Builder $builder): Builder
    {
        return $builder->where('enabled', true);
    }

    /**
     * @param  Builder<MaintenanceWindow>  $builder
     * @return Builder<MaintenanceWindow>
     */
    #[Scope]
    protected function visibleTo(Builder $builder, User $user): Builder
    {
        return $builder->where(function (Builder $builder) use ($user): void {
            $builder
                ->whereHas('monitoring', fn (Builder $builder): Builder => $builder->visibleTo($user))
                ->orWhereHas('monitoringGroup', fn (Builder $builder): Builder => $builder->where('user_id', $user->id));
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'recurrence' => MaintenanceWindowRecurrence::class,
            'repeat_until' => 'datetime',
            'enabled' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
