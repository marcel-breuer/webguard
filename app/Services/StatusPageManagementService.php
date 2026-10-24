<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatusPageComponentSource;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StatusPageManagementService
{
    /**
     * @param  array{name: string, description?: string|null, is_public: bool, auto_publish_incident_updates?: bool, components: list<array{name: string, description?: string|null, source_type: string, monitoring_group_id?: string|null, monitoring_ids?: list<string>}>}  $attributes
     */
    public function create(User $user, array $attributes): StatusPage
    {
        return DB::transaction(function () use ($user, $attributes): StatusPage {
            $statusPage = $user->statusPages()->create($this->statusPageAttributes($attributes));
            $this->syncComponents($statusPage, $attributes['components']);

            return $statusPage;
        });
    }

    /**
     * @param  array{name: string, description?: string|null, is_public: bool, auto_publish_incident_updates?: bool, components: list<array{name: string, description?: string|null, source_type: string, monitoring_group_id?: string|null, monitoring_ids?: list<string>}>}  $attributes
     */
    public function update(StatusPage $statusPage, array $attributes): StatusPage
    {
        return DB::transaction(function () use ($statusPage, $attributes): StatusPage {
            $statusPage->update($this->statusPageAttributes($attributes));
            $this->syncComponents($statusPage, $attributes['components']);

            return $statusPage->refresh();
        });
    }

    /**
     * @param  array{name: string, description?: string|null, is_public: bool, auto_publish_incident_updates?: bool, components: list<array{name: string, description?: string|null, source_type: string, monitoring_group_id?: string|null, monitoring_ids?: list<string>}>}  $attributes
     * @return array{name: string, description: string|null, is_public: bool, auto_publish_incident_updates: bool}
     */
    private function statusPageAttributes(array $attributes): array
    {
        return [
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'is_public' => $attributes['is_public'],
            'auto_publish_incident_updates' => $attributes['auto_publish_incident_updates'] ?? false,
        ];
    }

    /**
     * @param  list<array{name: string, description?: string|null, source_type: string, monitoring_group_id?: string|null, monitoring_ids?: list<string>}>  $components
     */
    private function syncComponents(StatusPage $statusPage, array $components): void
    {
        $statusPage->components()->delete();

        foreach (array_values($components) as $position => $componentAttributes) {
            $sourceType = $componentAttributes['source_type'];
            $component = $statusPage->components()->create([
                'name' => $componentAttributes['name'],
                'description' => $componentAttributes['description'] ?? null,
                'position' => $position,
                'source_type' => $sourceType,
                'monitoring_group_id' => $sourceType === StatusPageComponentSource::MONITORING_GROUP->value
                    ? $componentAttributes['monitoring_group_id']
                    : null,
            ]);

            if ($sourceType === StatusPageComponentSource::MONITORING_GROUP->value) {
                continue;
            }

            $component->monitorings()->sync(
                collect($componentAttributes['monitoring_ids'] ?? [])
                    ->values()
                    ->mapWithKeys(fn (string $monitoringId, int $monitoringPosition): array => [
                        $monitoringId => ['position' => $monitoringPosition],
                    ])
                    ->all(),
            );
        }
    }
}
