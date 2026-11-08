<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\MonitoringType;

/**
 * Validation rules for server-health monitorings.
 */
class ServerHealthRules
{
    public function __construct(private readonly MonitoringTypeContext $monitoringTypeContext) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'server_health_cpu_threshold_percent' => $this->thresholdRules(),
            'server_health_ram_threshold_percent' => $this->thresholdRules(),
            'server_health_storage_threshold_percent' => $this->thresholdRules(),
            'server_health_load_threshold_per_cpu' => $this->nullableNumberRules(0.01, 100),
            'server_health_service_response_time_threshold_ms' => $this->nullableIntegerRules(1, 60000),
            'server_health_report_interval_minutes' => $this->requiredIntegerRules(1, 1440),
            'server_health_grace_minutes' => $this->requiredIntegerRules(0, 1440),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function thresholdRules(): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail): void {
                if (! $this->monitoringTypeContext->is(MonitoringType::SERVER_HEALTH)) {
                    $this->failWhenPresent($attribute, $fail);

                    return;
                }

                if ($value === null || $value === '') {
                    $fail(__('monitoring.validation.server_health_threshold_required'));

                    return;
                }

                if (! is_numeric($value) || (float) $value < 1 || (float) $value > 100) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function nullableNumberRules(float $minimum, float $maximum): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                if (! $this->monitoringTypeContext->is(MonitoringType::SERVER_HEALTH)) {
                    $this->failWhenPresent($attribute, $fail);

                    return;
                }

                if ($value !== null && (! is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum)) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function nullableIntegerRules(int $minimum, int $maximum): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                if (! $this->monitoringTypeContext->is(MonitoringType::SERVER_HEALTH)) {
                    $this->failWhenPresent($attribute, $fail);

                    return;
                }

                if ($value !== null && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum)) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function requiredIntegerRules(int $minimum, int $maximum): array
    {
        return [
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                if (! $this->monitoringTypeContext->is(MonitoringType::SERVER_HEALTH)) {
                    $this->failWhenPresent($attribute, $fail);

                    return;
                }

                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    private function failWhenPresent(string $attribute, callable $fail): void
    {
        if ($this->monitoringTypeContext->request()->has($attribute)) {
            $fail(__('monitoring.validation.server_health_threshold_invalid_config'));
        }
    }
}
