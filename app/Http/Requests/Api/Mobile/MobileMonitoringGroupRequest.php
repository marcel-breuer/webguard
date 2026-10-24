<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Mobile;

use App\Models\Monitoring;
use App\Models\MonitoringGroup;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MobileMonitoringGroupRequest extends FormRequest
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
        /** @var MonitoringGroup|string|null $monitoringGroup */
        $monitoringGroup = $this->route('monitoringGroup');
        $monitoringGroupId = $monitoringGroup instanceof MonitoringGroup
            ? $monitoringGroup->id
            : $monitoringGroup;

        return [
            'name' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:100',
                Rule::unique('monitoring_groups', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($monitoringGroupId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'sla_target_percentage' => ['sometimes', 'nullable', 'numeric', 'between:0,100', 'required_if:sla_report_enabled,1'],
            'sla_report_enabled' => ['sometimes', 'boolean'],
            'monitoring_ids' => ['sometimes', 'array', 'max:100'],
            'monitoring_ids.*' => [
                'string',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $user = $this->user();

                    if ($user === null || ! Monitoring::query()->privateOwnedBy($user)->whereKey((string) $value)->exists()) {
                        $fail(__('monitoring_group.validation.monitoring_not_manageable'));
                    }
                },
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('sla_report_enabled')) {
                return;
            }

            $target = $this->input('sla_target_percentage');
            if ($target !== null && $target !== '') {
                return;
            }

            /** @var MonitoringGroup|string|null $monitoringGroup */
            $monitoringGroup = $this->route('monitoringGroup');
            $existingGroup = $monitoringGroup instanceof MonitoringGroup
                ? $monitoringGroup
                : (is_string($monitoringGroup) ? MonitoringGroup::query()->find($monitoringGroup) : null);

            if ($existingGroup?->sla_target_percentage === null) {
                $validator->errors()->add('sla_target_percentage', __('validation.required'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $prepared = [];

        if ($this->has('name')) {
            $prepared['name'] = mb_trim((string) $this->input('name'));
        }

        if ($this->has('description')) {
            $description = mb_trim((string) $this->input('description'));
            $prepared['description'] = $description !== '' ? $description : null;
        }

        if ($this->has('sla_target_percentage')) {
            $target = mb_trim((string) $this->input('sla_target_percentage'));
            $prepared['sla_target_percentage'] = $target !== '' ? $target : null;
        }

        if ($this->has('sla_report_enabled')) {
            $prepared['sla_report_enabled'] = $this->boolean('sla_report_enabled');
        }

        if ($this->has('monitoring_ids')) {
            $monitoringIds = $this->input('monitoring_ids', []);
            if (! is_array($monitoringIds)) {
                $monitoringIds = [$monitoringIds];
            }

            $prepared['monitoring_ids'] = array_values(array_filter(
                array_map(static fn (mixed $monitoringId): string => (string) $monitoringId, $monitoringIds),
                static fn (string $monitoringId): bool => $monitoringId !== ''
            ));
        }

        $this->merge($prepared);
    }
}
