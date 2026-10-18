<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\MobileMaintenanceOperation;
use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MobileMaintenanceWorkspaceService
{
    public function __construct(
        private readonly MaintenanceWindowService $maintenanceWindowService,
        private readonly PlannedMaintenanceNotificationService $plannedMaintenanceNotificationService,
    ) {}

    /**
     * @return Builder<Monitoring>
     */
    public function oneOffWindowsFor(User $user, ?string $state): Builder
    {
        $query = Monitoring::query()
            ->visibleTo($user)
            ->whereNotNull('maintenance_from')
            ->select(['id', 'name', 'target', 'user_id', 'team_id', 'maintenance_from', 'maintenance_until', 'updated_at']);

        $now = Date::now();
        match ($state) {
            'active' => $query->where('maintenance_from', '<=', $now)
                ->where(fn (Builder $builder) => $builder->whereNull('maintenance_until')->orWhere('maintenance_until', '>=', $now)),
            'upcoming' => $query->where('maintenance_from', '>', $now),
            'expired' => $query->whereNotNull('maintenance_until')->where('maintenance_until', '<', $now),
            default => null,
        };

        return $query->orderBy('maintenance_from')->orderBy('id');
    }

    /**
     * @return Builder<MaintenanceWindow>
     */
    public function recurringWindowsFor(User $user, ?string $state): Builder
    {
        $query = MaintenanceWindow::query()
            ->visibleTo($user)
            ->with([
                'monitoring:id,name',
                'monitoringGroup:id,name',
                'monitoringGroup.monitorings:id',
            ])
            ->latest('starts_at');

        if ($state === 'disabled') {
            $query->where('enabled', false);
        } elseif ($state !== null) {
            $query->where('enabled', true);
        }

        return $query;
    }

    public function decorateOneOff(Monitoring $monitoring, User $user): Monitoring
    {
        $now = Date::now();
        $monitoring->setAttribute('mobile_maintenance_state', match (true) {
            $monitoring->maintenance_from?->gt($now) === true => 'upcoming',
            $monitoring->maintenance_until !== null && $monitoring->maintenance_until->lt($now) => 'expired',
            default => 'active',
        });
        $monitoring->setAttribute('mobile_can_manage', $monitoring->isManageableBy($user));

        return $monitoring;
    }

    public function decorateRecurring(MaintenanceWindow $maintenanceWindow, User $user): MaintenanceWindow
    {
        $occurrence = $this->maintenanceWindowService->occurrence($maintenanceWindow);
        $manageableMonitoringIds = $maintenanceWindow->monitoring_id !== null
            ? ($maintenanceWindow->monitoring?->isManageableBy($user) ? [$maintenanceWindow->monitoring_id] : [])
            : $maintenanceWindow->monitoringGroup?->monitorings
                ->filter(fn (Monitoring $monitoring): bool => $monitoring->isManageableBy($user))
                ->pluck('id')
                ->values()
                ->all() ?? [];

        $maintenanceWindow->setAttribute('mobile_state', match (true) {
            ! $maintenanceWindow->enabled => 'disabled',
            $occurrence === null => 'expired',
            $occurrence['active'] => 'active',
            default => 'upcoming',
        });
        $maintenanceWindow->setAttribute('mobile_can_manage', $maintenanceWindow->isManageableBy($user));
        $maintenanceWindow->setAttribute('manageable_monitoring_ids', $manageableMonitoringIds);
        $maintenanceWindow->setAttribute('mobile_ends_at', $occurrence === null ? null : $occurrence['ends_at']->toIso8601String());
        $maintenanceWindow->setAttribute('mobile_next_occurrence', $occurrence === null ? null : [
            'starts_at' => $occurrence['starts_at']->toIso8601String(),
            'ends_at' => $occurrence['ends_at']->toIso8601String(),
            'active' => $occurrence['active'],
        ]);

        return $maintenanceWindow;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{operation: MobileMaintenanceOperation, created: bool}
     */
    public function schedule(User $user, array $attributes): array
    {
        $idempotencyKey = (string) Arr::pull($attributes, 'idempotency_key');
        $fingerprint = hash('sha256', json_encode(Arr::sortRecursive($attributes), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $attributes, $idempotencyKey, $fingerprint): array {
            $operation = MobileMaintenanceOperation::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($operation instanceof MobileMaintenanceOperation) {
                if (! hash_equals($operation->fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for a different maintenance operation.'],
                    ]);
                }

                return ['operation' => $operation, 'created' => false];
            }

            if ($attributes['mode'] === 'recurring') {
                $timezone = (string) $attributes['recurring_timezone'];
                $maintenanceWindow = MaintenanceWindow::query()->create([
                    ...$this->target($user, $attributes),
                    'starts_at' => Date::parse((string) $attributes['recurring_starts_at'], $timezone)->setTimezone('UTC'),
                    'duration_minutes' => (int) $attributes['recurring_duration_minutes'],
                    'recurrence' => $attributes['recurrence'],
                    'repeat_until' => isset($attributes['recurring_repeat_until'])
                        ? Date::parse((string) $attributes['recurring_repeat_until'], $timezone)->endOfDay()->setTimezone('UTC')
                        : null,
                    'timezone' => $timezone,
                    'enabled' => true,
                ]);
                $result = ['kind' => 'recurring', 'maintenance_window_id' => $maintenanceWindow->id];
            } else {
                $updatedCount = $this->targetMonitorings($user, $attributes)->update([
                    'maintenance_from' => Date::parse((string) $attributes['maintenance_from']),
                    'maintenance_until' => isset($attributes['maintenance_until'])
                        ? Date::parse((string) $attributes['maintenance_until'])
                        : null,
                ]);
                $maintenanceWindow = null;
                $result = ['kind' => 'one_off', 'updated_count' => $updatedCount];
            }

            $operation = MobileMaintenanceOperation::query()->create([
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'operation' => 'schedule',
                'maintenance_window_id' => $maintenanceWindow?->id,
                'result' => $result,
            ]);

            if ($attributes['mode'] === 'recurring') {
                $maintenanceWindow = MaintenanceWindow::query()->findOrFail($operation->maintenance_window_id);
                $this->plannedMaintenanceNotificationService->notifyForRecurring($maintenanceWindow);
            } else {
                $this->plannedMaintenanceNotificationService->notifyForOneOff(
                    $this->targetMonitorings($user, $attributes)->get(),
                    Date::parse((string) $attributes['maintenance_from']),
                    isset($attributes['maintenance_until']) ? Date::parse((string) $attributes['maintenance_until']) : null,
                );
            }

            return ['operation' => $operation, 'created' => true];
        });
    }

    public function recurringWindowFor(User $user, string $maintenanceWindow): MaintenanceWindow
    {
        return MaintenanceWindow::query()
            ->visibleTo($user)
            ->whereKey($maintenanceWindow)
            ->firstOrFail();
    }

    public function updateRecurringEnabled(MaintenanceWindow $maintenanceWindow, User $user, bool $enabled): MaintenanceWindow
    {
        abort_unless($maintenanceWindow->isManageableBy($user), 404);
        DB::transaction(function () use ($maintenanceWindow, $enabled): void {
            $maintenanceWindow->update(['enabled' => $enabled]);

            if ($enabled) {
                $this->plannedMaintenanceNotificationService->notifyForRecurring(
                    MaintenanceWindow::query()->findOrFail($maintenanceWindow->id)
                );
            }
        });

        return $maintenanceWindow->refresh()->load([
            'monitoring:id,name',
            'monitoringGroup:id,name',
            'monitoringGroup.monitorings:id',
        ]);
    }

    public function cancelOneOff(Monitoring $monitoring, User $user): void
    {
        abort_unless($monitoring->isManageableBy($user), 404);
        $monitoring->update(['maintenance_from' => null, 'maintenance_until' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{monitoring_id?: string, monitoring_group_id?: string}
     */
    private function target(User $user, array $attributes): array
    {
        if ($attributes['scope'] === 'group') {
            return ['monitoring_group_id' => (string) $attributes['monitoring_group_id']];
        }

        $this->monitoringForManagement($user, (string) $attributes['monitoring_id']);

        return ['monitoring_id' => (string) $attributes['monitoring_id']];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Builder<Monitoring>
     */
    private function targetMonitorings(User $user, array $attributes): Builder
    {
        $query = Monitoring::query()->manageableBy($user);

        if ($attributes['scope'] === 'group') {
            return $query->whereHas('groups', fn (Builder $builder): Builder => $builder->whereKey((string) $attributes['monitoring_group_id']));
        }

        return $query->whereKey((string) $attributes['monitoring_id']);
    }

    private function monitoringForManagement(User $user, string $monitoring): Monitoring
    {
        return Monitoring::query()->manageableBy($user)->whereKey($monitoring)->firstOrFail();
    }
}
