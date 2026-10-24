<?php

declare(strict_types=1);

namespace App\Http\Resources\InternalUi;

use App\Models\Monitoring;
use App\Models\User;
use App\Services\MonitoringHealthEvaluator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Monitoring */
class MonitoringResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'target' => $this->target,
            'type' => $this->type?->value ?? $this->type,
            'keyword' => $this->keyword,
            'check_interval_minutes' => $this->check_interval_minutes,
            'lifecycle_status' => $this->status?->value ?? $this->status,
            'groups' => $this->whenLoaded('groups', fn (): array => $this->groups->map(
                fn ($group): array => ['id' => $group->id, 'name' => $group->name]
            )->values()->all()),
            'latest_check' => $this->whenLoaded('latestResponseResult', fn (): ?array => $this->latestResponseResult ? [
                'status' => resolve(MonitoringHealthEvaluator::class)->availabilityFor($this->resource, $this->latestResponseResult)->value,
                'checked_at' => $this->latestResponseResult->created_at?->toIso8601String(),
                'response_time_ms' => $this->latestResponseResult->response_time,
            ] : null),
            'performance' => $this->whenLoaded('performanceState', fn (): ?array => $this->performanceState ? [
                'status' => $this->performanceState->status?->value,
                'consecutive_breaches' => $this->performanceState->consecutive_breaches,
                'degraded_at' => $this->performanceState->degraded_at?->toIso8601String(),
            ] : null),
            'open_incident' => $this->whenLoaded('latestIncident', fn (): bool => $this->latestIncident?->up_at === null),
            'can_manage' => $user !== null && ! $user->isDemo() && $this->isManageableBy($user),
            'maintenance' => [
                'starts_at' => $this->maintenance_from?->toIso8601String(),
                'ends_at' => $this->maintenance_until?->toIso8601String(),
                'has_recurring_window' => $this->has_enabled_maintenance_windows,
            ],
        ];
    }
}
