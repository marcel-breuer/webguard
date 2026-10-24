<?php

declare(strict_types=1);

namespace App\Http\Requests\StatusPages;

use App\Enums\StatusPageComponentSource;
use App\Models\Monitoring;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['boolean'],
            'auto_publish_incident_updates' => ['boolean'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.description' => ['nullable', 'string', 'max:1000'],
            'components.*.source_type' => ['required', Rule::enum(StatusPageComponentSource::class)],
            'components.*.monitoring_group_id' => [
                'nullable',
                'string',
                Rule::exists('monitoring_groups', 'id')->where('user_id', $this->user()?->id),
            ],
            'components.*.monitoring_ids' => ['nullable', 'array'],
            'components.*.monitoring_ids.*' => [
                'required',
                'string',
                Rule::exists('monitorings', 'id'),
                function ($attribute, $value, $fail): void {
                    if (! $this->user()
                        || ! Monitoring::query()->visibleTo($this->user())->whereKey((string) $value)->exists()) {
                        $fail(__('status_page.validation.monitoring_not_accessible'));
                    }
                },
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $components = $this->input('components', []);

            if (! is_array($components)) {
                return;
            }

            foreach ($components as $index => $component) {
                if (! is_array($component)) {
                    continue;
                }

                $sourceType = $component['source_type'] ?? StatusPageComponentSource::MANUAL->value;

                if ($sourceType === StatusPageComponentSource::MONITORING_GROUP->value && empty($component['monitoring_group_id'])) {
                    $validator->errors()->add("components.{$index}.monitoring_group_id", __('validation.required'));
                }

                if ($sourceType === StatusPageComponentSource::MANUAL->value && empty($component['monitoring_ids'])) {
                    $validator->errors()->add("components.{$index}.monitoring_ids", __('validation.required'));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_public' => $this->boolean('is_public'),
            'auto_publish_incident_updates' => $this->boolean('auto_publish_incident_updates'),
            'components' => $this->normalizeComponents(),
        ]);
    }

    /**
     * @return list<array{name: string, description: string|null, source_type: string, monitoring_group_id: string|null, monitoring_ids: list<string>}>
     */
    private function normalizeComponents(): array
    {
        $components = $this->input('components', []);

        if (! is_array($components)) {
            return [];
        }

        $normalized = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $name = mb_trim((string) ($component['name'] ?? ''));
            $description = mb_trim((string) ($component['description'] ?? ''));
            $sourceType = (string) ($component['source_type'] ?? StatusPageComponentSource::MANUAL->value);
            $monitoringGroupId = mb_trim((string) ($component['monitoring_group_id'] ?? ''));
            $monitoringIds = $component['monitoring_ids'] ?? [];

            if (! is_array($monitoringIds)) {
                $monitoringIds = [$monitoringIds];
            }

            $monitoringIds = array_values(array_unique(array_filter(
                array_map(static fn (mixed $monitoringId): string => (string) $monitoringId, $monitoringIds),
                static fn (string $monitoringId): bool => $monitoringId !== ''
            )));

            if ($name === '' && $monitoringIds === []) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'source_type' => $sourceType,
                'monitoring_group_id' => $monitoringGroupId !== '' ? $monitoringGroupId : null,
                'monitoring_ids' => $monitoringIds,
            ];
        }

        return $normalized;
    }
}
