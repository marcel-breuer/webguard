<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\MonitoringType;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the monitoring target, which depends on the monitoring type.
 */
class TargetRules
{
    public function __construct(private readonly MonitoringTypeContext $monitoringTypeContext) {}

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            Rule::requiredIf(fn (): bool => ! in_array(
                $this->monitoringTypeContext->type(),
                [MonitoringType::HEARTBEAT, MonitoringType::SERVER_HEALTH],
                true
            )),
            'nullable',
            'string',
            'max:255',
            function ($attribute, $value, $fail): void {
                $type = $this->monitoringTypeContext->request()->input('type');

                if (in_array($type, [MonitoringType::HEARTBEAT->value, MonitoringType::SERVER_HEALTH->value], true)) {
                    return;
                }

                if (($type === MonitoringType::HTTP->value || $type === MonitoringType::KEYWORD->value) && ! filter_var($value, FILTER_VALIDATE_URL)) {
                    $fail(sprintf('The %s must be a valid URL for type %s.', $attribute, $type));
                }

                if ($type === MonitoringType::PING->value && ! filter_var($value, FILTER_VALIDATE_IP)) {
                    $fail(sprintf('The %s must be a valid IP address for type %s.', $attribute, $type));
                }

                if ($type === MonitoringType::PORT->value && (! filter_var($value, FILTER_VALIDATE_IP) && ! filter_var($value, FILTER_VALIDATE_URL))) {
                    $fail(sprintf('The %s must be a valid IP address or URL for type %s.', $attribute, $type));
                }

                if (in_array($type, [MonitoringType::DOMAIN_EXPIRATION->value, MonitoringType::DNS_RECORD->value], true)
                    && ! $this->isValidDomainTarget((string) $value)) {
                    $fail(__('monitoring.validation.target_invalid_domain', ['attribute' => $attribute, 'type' => $type]));
                }
            },
        ];
    }

    private function isValidDomainTarget(string $value): bool
    {
        $domain = mb_strtolower(mb_trim($value));

        if ($domain === '' || str_contains($domain, '://') || str_contains($domain, '/')) {
            return false;
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($domain, '.');
    }
}
