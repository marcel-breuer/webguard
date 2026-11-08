<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\MonitoringType;
use App\Support\DnsRecordExpectation;
use Illuminate\Validation\Rule;

/**
 * Validation rules for DNS record monitorings.
 */
class DnsRecordRules
{
    public function __construct(
        private readonly MonitoringTypeContext $monitoringTypeContext,
        private readonly MonitoringInputNormalizer $monitoringInputNormalizer,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $request = $this->monitoringTypeContext->request();

        return [
            'dns_record_type' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->monitoringTypeContext->is(MonitoringType::DNS_RECORD)),
                'string',
                Rule::in(DnsRecordExpectation::recordTypes()),
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->is(MonitoringType::DNS_RECORD) && $request->filled('dns_record_type')) {
                        $fail(__('monitoring.validation.dns_record_type_invalid_config'));
                    }
                },
            ],
            'dns_expected_values' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->monitoringTypeContext->is(MonitoringType::DNS_RECORD)),
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->is(MonitoringType::DNS_RECORD)) {
                        if ($request->filled('dns_expected_values')) {
                            $fail(__('monitoring.validation.dns_expected_values_invalid_config'));
                        }

                        return;
                    }

                    if ($this->monitoringInputNormalizer->hasInvalidDnsExpectedValues() || ! is_array($value) || count($value) === 0) {
                        $fail(__('monitoring.validation.dns_expected_values_invalid_format'));

                        return;
                    }

                    if (count($value) > 50) {
                        $fail(__('monitoring.validation.dns_expected_values_too_many'));
                    }
                },
            ],
        ];
    }
}
