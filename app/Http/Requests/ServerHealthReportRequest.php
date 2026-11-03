<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MonitoringStatus;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Throwable;

class ServerHealthReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isVersionedReport = fn (): bool => $this->filled('schema_version');

        return [
            'schema_version' => ['nullable', 'integer', Rule::in([1])],
            'report_id' => ['nullable', 'uuid', Rule::requiredIf($isVersionedReport)],
            'sampled_at' => [
                'nullable',
                'date',
                Rule::requiredIf($isVersionedReport),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    try {
                        $sampledAt = Date::parse((string) $value);
                    } catch (Throwable) {
                        return;
                    }

                    if ($sampledAt->greaterThan(Date::now()->addMinutes(5)) || $sampledAt->lessThan(Date::now()->subDay())) {
                        $fail('The sampled_at timestamp must be within the last 24 hours and no more than five minutes in the future.');
                    }
                },
            ],
            'host' => ['nullable', 'array:cpu_usage_percent,logical_cpu_count,load_average_1m,load_average_5m,load_average_15m,ram_usage_percent,swap_usage_percent,uptime_seconds', Rule::requiredIf($isVersionedReport)],
            'host.cpu_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'host.logical_cpu_count' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'host.load_average_1m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'host.load_average_5m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'host.load_average_15m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'host.ram_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'host.swap_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'host.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'service_checks' => ['nullable', 'array', 'max:20'],
            'service_checks.*' => ['required', 'array:id,success,response_time_ms,status_code'],
            'service_checks.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'service_checks.*.success' => ['required', 'boolean'],
            'service_checks.*.response_time_ms' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'service_checks.*.status_code' => ['nullable', 'integer', 'between:100,599'],
            'agent' => ['nullable', 'array:version'],
            'agent.version' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::enum(MonitoringStatus::class)],
            'cpu_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'ram_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'storage_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'load_average' => ['nullable', 'numeric', 'min:0'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'extra_metrics' => ['nullable', 'array'],
            'extra_metrics.*' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isVersionedReport = $this->filled('schema_version');

            if (! $isVersionedReport && ($this->has('host') || $this->has('service_checks') || $this->has('report_id') || $this->has('sampled_at'))) {
                $validator->errors()->add('schema_version', 'Versioned server health reports must declare schema_version 1.');

                return;
            }

            if (! $isVersionedReport) {
                return;
            }

            $host = $this->input('host', []);
            $hasHostMetric = is_array($host) && collect($host)->contains(static fn (mixed $value): bool => $value !== null);
            $hasServiceCheck = is_array($this->input('service_checks')) && $this->input('service_checks') !== [];

            if (! $hasHostMetric && ! $hasServiceCheck) {
                $validator->errors()->add('metrics', 'Provide at least one host metric or service check.');
            }
        });
    }
}
