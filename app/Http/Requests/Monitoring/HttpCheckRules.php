<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\HttpMethod;
use App\Services\MonitoringCheckIntervalService;
use App\Support\HttpStatusCodeRanges;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Validation rules for options that only apply to HTTP and keyword monitorings.
 */
class HttpCheckRules
{
    public function __construct(
        private readonly MonitoringTypeContext $monitoringTypeContext,
        private readonly MonitoringInputNormalizer $monitoringInputNormalizer,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function intervalRules(): array
    {
        return [
            'check_interval_minutes' => [
                'nullable',
                'integer',
                Rule::in(MonitoringCheckIntervalService::WEBSITE_INTERVAL_OPTIONS_MINUTES),
                function ($attribute, $value, $fail): void {
                    if (! $this->monitoringTypeContext->isHttpLike() && $this->monitoringTypeContext->request()->has('check_interval_minutes')) {
                        $fail(__('monitoring.validation.check_interval_invalid_config'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function requestRules(): array
    {
        $request = $this->monitoringTypeContext->request();

        return [
            'timeout' => [
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->isHttpLike()) {
                        if ($request->has('timeout')) {
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
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->isHttpLike()) {
                        if ($request->has('http_method')) {
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
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->isHttpLike()) {
                        if ($request->filled('expected_http_statuses')) {
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
                function ($attribute, $value, $fail) use ($request): void {
                    if (! $this->monitoringTypeContext->isHttpLike()) {
                        if ($request->has('http_headers')) {
                            $fail('Headers are only valid for HTTP or Keyword monitoring.');
                        }

                        return;
                    }

                    if ($this->monitoringInputNormalizer->hasInvalidHttpHeadersJson()) {
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
                $this->httpOnlyField('http_body', 'Body content is only valid for HTTP or Keyword monitoring.'),
                'max:2048',
            ],
            'auth_username' => [
                'nullable',
                'string',
                'max:255',
                $this->httpOnlyField('auth_username', 'Username for basic auth is only valid for HTTP or Keyword monitoring.'),
            ],
            'auth_password' => [
                'nullable',
                'string',
                'max:255',
                $this->httpOnlyField('auth_password', 'Password for basic auth is only valid for HTTP or Keyword monitoring.'),
            ],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function responseTimeRules(): array
    {
        return [
            'response_time_threshold_ms' => [
                'nullable',
                'integer',
                'min:1',
                'max:60000',
                Rule::requiredIf(fn (): bool => $this->monitoringTypeContext->request()->filled('response_time_confirmation_threshold') && ! $this->monitoringTypeContext->request()->filled('response_time_degradation_percent')),
                $this->responseTimeOnlyField(),
            ],
            'response_time_confirmation_threshold' => [
                'nullable',
                'integer',
                'min:1',
                'max:10',
                Rule::requiredIf(fn (): bool => $this->monitoringTypeContext->request()->filled('response_time_threshold_ms')),
                $this->responseTimeOnlyField(),
            ],
            'response_time_degradation_percent' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000',
                $this->responseTimeOnlyField(),
            ],
        ];
    }

    private function httpOnlyField(string $field, string $message): callable
    {
        return function ($attribute, $value, $fail) use ($field, $message): void {
            if (! $this->monitoringTypeContext->isHttpLike() && $this->monitoringTypeContext->request()->has($field)) {
                $fail($message);
            }
        };
    }

    private function responseTimeOnlyField(): callable
    {
        return function ($attribute, $value, $fail): void {
            if (! $this->monitoringTypeContext->isHttpLike() && $this->monitoringTypeContext->request()->filled($attribute)) {
                $fail(__('monitoring.validation.response_time_threshold_invalid_config'));
            }
        };
    }
}
