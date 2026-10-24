<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Models\Team;
use App\Models\User;
use App\Services\MonitoringCheckIntervalService;
use App\Support\DnsRecordExpectation;
use App\Support\HttpStatusCodeRanges;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

/**
 * Class MonitoringRequest
 *
 * Handles validation logic for creating or updating a monitoring configuration.
 * Applies dynamic rules depending on the selected monitoring type.
 */
class MonitoringRequest extends FormRequest
{
    private bool $invalidHttpHeadersJson = false;

    private bool $invalidDnsExpectedValues = false;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool True if the user is authorized, false otherwise.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Applies conditional validation based on the selected monitoring type.
     *
     * @return array<string, ValidationRule|array|string> The validation rules.
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(MonitoringType::class)],
            'port' => ['nullable', 'required_if:type,port', 'integer', 'min:1', 'max:65535'],
            'keyword' => ['nullable', 'required_if:type,keyword', 'string', 'max:255'],
            'dns_record_type' => [
                'nullable',
                Rule::requiredIf(fn (): bool => MonitoringType::tryFrom((string) $this->input('type')) === MonitoringType::DNS_RECORD),
                'string',
                Rule::in(DnsRecordExpectation::recordTypes()),
                function ($attribute, $value, $fail): void {
                    if (MonitoringType::tryFrom((string) $this->input('type')) !== MonitoringType::DNS_RECORD && $this->filled('dns_record_type')) {
                        $fail(__('monitoring.validation.dns_record_type_invalid_config'));
                    }
                },
            ],
            'dns_expected_values' => [
                'nullable',
                Rule::requiredIf(fn (): bool => MonitoringType::tryFrom((string) $this->input('type')) === MonitoringType::DNS_RECORD),
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom((string) $this->input('type'));

                    if ($type !== MonitoringType::DNS_RECORD) {
                        if ($this->filled('dns_expected_values')) {
                            $fail(__('monitoring.validation.dns_expected_values_invalid_config'));
                        }

                        return;
                    }

                    if ($this->invalidDnsExpectedValues || ! is_array($value) || count($value) === 0) {
                        $fail(__('monitoring.validation.dns_expected_values_invalid_format'));

                        return;
                    }

                    if (count($value) > 50) {
                        $fail(__('monitoring.validation.dns_expected_values_too_many'));
                    }
                },
            ],
            'status' => ['required', Rule::enum(MonitoringLifecycleStatus::class)],
            'heartbeat_interval_minutes' => ['nullable', 'required_if:type,heartbeat', 'integer', 'min:1', 'max:10080'],
            'heartbeat_grace_minutes' => ['nullable', 'required_if:type,heartbeat', 'integer', 'min:0', 'max:1440'],
            'check_interval_minutes' => [
                'nullable',
                'integer',
                Rule::in(MonitoringCheckIntervalService::WEBSITE_INTERVAL_OPTIONS_MINUTES),
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom((string) $this->input('type'));

                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->has('check_interval_minutes')) {
                        $fail('Check interval selection is only valid for HTTP or Keyword monitoring.');
                    }
                },
            ],
            'server_health_cpu_threshold_percent' => $this->serverHealthThresholdRules(),
            'server_health_ram_threshold_percent' => $this->serverHealthThresholdRules(),
            'server_health_storage_threshold_percent' => $this->serverHealthThresholdRules(),
            'server_health_load_threshold_per_cpu' => $this->serverHealthNullableNumberRules(0.01, 100),
            'server_health_service_response_time_threshold_ms' => $this->serverHealthNullableIntegerRules(1, 60000),
            'server_health_report_interval_minutes' => $this->serverHealthRequiredIntegerRules(1, 1440),
            'server_health_grace_minutes' => $this->serverHealthRequiredIntegerRules(0, 1440),
            'timeout' => [
                function ($attribute, $value, $fail): void {
                    $user = $this->user();
                    $type = MonitoringType::tryFrom($this->input('type'));

                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
                        if ($this->has('timeout')) {
                            $fail('Timeout configuration is only valid for HTTP or Keyword monitoring.');
                        }

                        return;
                    }

                    if ($value === null) {
                        $fail('The timeout field is required for HTTP or Keyword monitoring.');
                    } elseif (! is_numeric($value) || $value < 1 || $value > 60) {
                        $fail('The timeout must be a number between 1 and 60 seconds.');
                    }
                },
                'max:60',
            ],
            'http_method' => [
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));
                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
                        if ($this->has('http_method')) {
                            $fail('HTTP method configuration is only valid for HTTP or Keyword monitoring.');
                        }

                        return;
                    }

                    if ($value && HttpMethod::tryFrom($value) === null) {
                        $fail('The HTTP method must be a valid HTTP method.');
                    }
                },
            ],
            'expected_http_statuses' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));

                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
                        if ($this->filled('expected_http_statuses')) {
                            $fail(__('monitoring.validation.expected_http_statuses_invalid_config'));
                        }

                        return;
                    }

                    try {
                        HttpStatusCodeRanges::normalize(is_string($value) ? $value : null);
                    } catch (InvalidArgumentException) {
                        $fail(__('monitoring.validation.expected_http_statuses_invalid_format'));
                    }
                },
            ],
            'http_headers' => [
                'nullable',
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));
                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
                        if ($this->has('http_headers')) {
                            $fail('Headers are only valid for HTTP or Keyword monitoring.');
                        }

                        return;
                    }

                    if ($this->invalidHttpHeadersJson) {
                        $fail(__('monitoring.validation.headers_invalid_json'));

                        return;
                    }

                    if (! is_array($value)) {
                        $fail(__('monitoring.validation.headers_invalid_format'));
                    }
                },
            ],
            'http_body' => [
                'nullable',
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));
                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->has('http_body')) {
                        $fail('Body content is only valid for HTTP or Keyword monitoring.');
                    }
                },
                'max:2048',
            ],
            'auth_username' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));
                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->has('auth_username')) {
                        $fail('Username for basic auth is only valid for HTTP or Keyword monitoring.');
                    }
                },
            ],
            'auth_password' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail): void {
                    $type = MonitoringType::tryFrom($this->input('type'));
                    if (! in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->has('auth_password')) {
                        $fail('Password for basic auth is only valid for HTTP or Keyword monitoring.');
                    }
                },
            ],
            'preferred_location' => ['required', 'string', Rule::exists('server_instances', 'code')->where('is_active', true)],
            'preferred_locations' => ['required', 'array', 'min:1'],
            'preferred_locations.*' => ['required', 'string', 'distinct', Rule::exists('server_instances', 'code')->where('is_active', true)],
            'public_label_enabled' => ['boolean'],
            'notification_on_failure' => ['boolean'],
            'notification_channels' => ['nullable', 'array'],
            'notification_channels.*' => [
                'string',
                Rule::in($this->notificationChannelUser()?->enabledNotificationChannelKeys() ?? []),
            ],
            'team_id' => [
                'nullable',
                'string',
                Rule::exists('teams', 'id'),
                function ($attribute, $value, $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    $user = $this->user();

                    if (! $user || ! Team::query()->administeredBy($user)->whereKey((string) $value)->exists()) {
                        $fail(__('team.validation.not_admin'));
                    }
                },
            ],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => [
                'string',
                Rule::exists('monitoring_groups', 'id')->where('user_id', $this->user()?->id),
            ],
            'failure_confirmation_threshold' => ['required', 'integer', 'min:1', 'max:10'],
            'response_time_threshold_ms' => [
                'nullable',
                'integer',
                'min:1',
                'max:60000',
                Rule::requiredIf(fn (): bool => $this->filled('response_time_confirmation_threshold')),
                function ($attribute, $value, $fail): void {
                    if (! in_array(MonitoringType::tryFrom((string) $this->input('type')), [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->filled($attribute)) {
                        $fail(__('monitoring.validation.response_time_threshold_invalid_config'));
                    }
                },
            ],
            'response_time_confirmation_threshold' => [
                'nullable',
                'integer',
                'min:1',
                'max:10',
                Rule::requiredIf(fn (): bool => $this->filled('response_time_threshold_ms')),
                function ($attribute, $value, $fail): void {
                    if (! in_array(MonitoringType::tryFrom((string) $this->input('type')), [MonitoringType::HTTP, MonitoringType::KEYWORD], true) && $this->filled($attribute)) {
                        $fail(__('monitoring.validation.response_time_threshold_invalid_config'));
                    }
                },
            ],
            'ssl_expiry_warning_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];

        if ($this->isMethod('post')) {
            $rules['target'] = $this->targetRules();
        } elseif ($this->isMethod('patch') || $this->isMethod('put')) {
            $rules['target'] = ['sometimes', ...$this->targetRules()];
        }

        return $rules;
    }

    protected function notificationChannelUser(): ?User
    {
        return $this->user();
    }

    /**
     * Prepare the data for validation.
     *
     * Ensures the 'type' field is consistently lowercase before validation.
     */
    protected function prepareForValidation(): void
    {
        $hasGroupIds = $this->has('group_ids');
        $type = mb_strtolower((string) $this->input('type'));
        $httpHeaders = $this->normalizeHttpHeaders();
        $dnsRecordType = DnsRecordExpectation::normalizeRecordType($this->input('dns_record_type'));
        $dnsExpectedValues = $this->normalizeDnsExpectedValues($type, $dnsRecordType);
        $preferredLocations = $this->normalizePreferredLocations();

        $prepared = [
            'type' => $type,
            'http_headers' => $httpHeaders,
            'dns_record_type' => $dnsRecordType,
            'dns_expected_values' => $dnsExpectedValues,
            'preferred_location' => $preferredLocations[0] ?? null,
            'preferred_locations' => $preferredLocations,
            'public_label_enabled' => $this->boolean('public_label_enabled'),
            'notification_on_failure' => $this->boolean('notification_on_failure'),
            'notification_channels' => $this->normalizeNotificationChannels(),
            'team_id' => $this->normalizeTeamId(),
            'failure_confirmation_threshold' => $this->input('failure_confirmation_threshold', 2),
            'response_time_threshold_ms' => $this->normalizeNullableInteger('response_time_threshold_ms'),
            'response_time_confirmation_threshold' => $this->normalizeNullableInteger('response_time_confirmation_threshold'),
            'ssl_expiry_warning_days' => $this->input('ssl_expiry_warning_days', 7),
            'heartbeat_grace_minutes' => $this->input('heartbeat_grace_minutes', 5),
        ];

        if ($hasGroupIds) {
            $prepared['group_ids'] = $this->normalizeGroupIds();
        }

        if ($type === MonitoringType::SERVER_HEALTH->value) {
            $prepared['server_health_cpu_threshold_percent'] = $this->input('server_health_cpu_threshold_percent', 90);
            $prepared['server_health_ram_threshold_percent'] = $this->input('server_health_ram_threshold_percent', 90);
            $prepared['server_health_storage_threshold_percent'] = $this->input('server_health_storage_threshold_percent', 90);
            $prepared['server_health_load_threshold_per_cpu'] = $this->normalizeNullableFloat('server_health_load_threshold_per_cpu');
            $prepared['server_health_service_response_time_threshold_ms'] = $this->normalizeNullableInteger('server_health_service_response_time_threshold_ms');
            $prepared['server_health_report_interval_minutes'] = $this->input('server_health_report_interval_minutes', 1);
            $prepared['server_health_grace_minutes'] = $this->input('server_health_grace_minutes', 5);
        }

        $this->merge($prepared);
    }

    /**
     * @return list<string>
     */
    private function normalizeNotificationChannels(): array
    {
        $channels = $this->input('notification_channels', []);

        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $channels,
            static fn ($channel): bool => is_string($channel) && $channel !== ''
        )));
    }

    /**
     * @return list<string>
     */
    private function normalizeGroupIds(): array
    {
        $groupIds = $this->input('group_ids', []);

        if (! is_array($groupIds)) {
            $groupIds = [$groupIds];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $groupId): string => (string) $groupId, $groupIds),
            static fn (string $groupId): bool => $groupId !== ''
        )));
    }

    private function normalizeTeamId(): ?string
    {
        $teamId = $this->input('team_id');

        if (! is_scalar($teamId)) {
            return null;
        }

        $teamId = mb_trim((string) $teamId);

        return $teamId === '' ? null : $teamId;
    }

    private function normalizeNullableInteger(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeNullableFloat(string $key): ?float
    {
        $value = $this->input($key);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function normalizePreferredLocations(): array
    {
        $locations = $this->input('preferred_locations', $this->input('preferred_location', []));

        if (! is_array($locations)) {
            $locations = [$locations];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $location): string => (string) $location, $locations),
            static fn (string $location): bool => $location !== ''
        )));
    }

    /**
     * Get validation rules for the target field.
     *
     * @return array<int, ValidationRule|callable|string>
     */
    private function targetRules(): array
    {
        return [
            Rule::requiredIf(fn (): bool => ! in_array(
                MonitoringType::tryFrom((string) $this->input('type')),
                [MonitoringType::HEARTBEAT, MonitoringType::SERVER_HEALTH],
                true
            )),
            'nullable',
            'string',
            'max:255',
            function ($attribute, $value, $fail): void {
                $type = $this->input('type');

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

    /**
     * @return array<int, ValidationRule|callable|string>
     */
    private function serverHealthThresholdRules(): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail): void {
                $type = MonitoringType::tryFrom((string) $this->input('type'));

                if ($type !== MonitoringType::SERVER_HEALTH) {
                    if ($this->has($attribute)) {
                        $fail(__('monitoring.validation.server_health_threshold_invalid_config'));
                    }

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
     * @return array<int, ValidationRule|callable|string>
     */
    private function serverHealthNullableNumberRules(float $minimum, float $maximum): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                $type = MonitoringType::tryFrom((string) $this->input('type'));

                if ($type !== MonitoringType::SERVER_HEALTH) {
                    if ($this->has($attribute)) {
                        $fail(__('monitoring.validation.server_health_threshold_invalid_config'));
                    }

                    return;
                }

                if ($value !== null && (! is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum)) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    /**
     * @return array<int, ValidationRule|callable|string>
     */
    private function serverHealthNullableIntegerRules(int $minimum, int $maximum): array
    {
        return [
            'nullable',
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                $type = MonitoringType::tryFrom((string) $this->input('type'));

                if ($type !== MonitoringType::SERVER_HEALTH) {
                    if ($this->has($attribute)) {
                        $fail(__('monitoring.validation.server_health_threshold_invalid_config'));
                    }

                    return;
                }

                if ($value !== null && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum)) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
                }
            },
        ];
    }

    /**
     * @return array<int, ValidationRule|callable|string>
     */
    private function serverHealthRequiredIntegerRules(int $minimum, int $maximum): array
    {
        return [
            function ($attribute, $value, $fail) use ($minimum, $maximum): void {
                if (MonitoringType::tryFrom((string) $this->input('type')) !== MonitoringType::SERVER_HEALTH) {
                    if ($this->has($attribute)) {
                        $fail(__('monitoring.validation.server_health_threshold_invalid_config'));
                    }

                    return;
                }

                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum) {
                    $fail(__('monitoring.validation.server_health_threshold_range'));
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

    /**
     * @return array<string, mixed>|null|string
     */
    private function normalizeHttpHeaders(): array|null|string
    {
        $httpHeaders = $this->input('http_headers', $this->input('http_header'));

        if (is_array($httpHeaders) || $httpHeaders === null) {
            return $httpHeaders;
        }

        if (! is_string($httpHeaders)) {
            return $httpHeaders;
        }

        $trimmedHeaders = mb_trim($httpHeaders);

        if ($trimmedHeaders === '') {
            return null;
        }

        try {
            $decodedHeaders = json_decode($trimmedHeaders, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidHttpHeadersJson = true;

            return $httpHeaders;
        }

        if (! is_array($decodedHeaders)) {
            $this->invalidHttpHeadersJson = true;

            return $httpHeaders;
        }

        return $decodedHeaders;
    }

    /**
     * @return array<int, string>|mixed
     */
    private function normalizeDnsExpectedValues(string $type, ?string $recordType): mixed
    {
        $values = $this->input('dns_expected_values');

        if ($type !== MonitoringType::DNS_RECORD->value) {
            return $values;
        }

        try {
            return DnsRecordExpectation::normalizeValues($values, $recordType);
        } catch (InvalidArgumentException) {
            $this->invalidDnsExpectedValues = true;

            return $values;
        }
    }
}
