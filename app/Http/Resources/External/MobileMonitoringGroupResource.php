<?php

declare(strict_types=1);

namespace App\Http\Resources\External;

use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MonitoringGroup
 */
final class MobileMonitoringGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MonitoringGroup $monitoringGroup */
        $monitoringGroup = $this->resource;

        return [
            'id' => $monitoringGroup->id,
            'name' => $monitoringGroup->name,
            'description' => $monitoringGroup->description,
            'sla' => [
                'target_percentage' => $monitoringGroup->sla_target_percentage === null ? null : (float) $monitoringGroup->sla_target_percentage,
                'monthly_reports_enabled' => $monitoringGroup->sla_report_enabled,
            ],
            'ownership' => [
                'type' => 'private',
                'user_id' => $monitoringGroup->user_id,
                'team_id' => null,
                'can_manage' => true,
            ],
            'assignable_monitoring_count' => (int) ($monitoringGroup->assignable_monitoring_count ?? 0),
            'assignments' => $monitoringGroup->relationLoaded('monitorings')
                ? $monitoringGroup->monitorings
                    ->map(fn (Monitoring $monitoring): array => MobileMonitoringAssignmentResource::make($monitoring)->resolve($request))
                    ->values()
                    ->all()
                : [],
            'created_at' => $monitoringGroup->created_at?->toIso8601String(),
            'updated_at' => $monitoringGroup->updated_at?->toIso8601String(),
        ];
    }
}
