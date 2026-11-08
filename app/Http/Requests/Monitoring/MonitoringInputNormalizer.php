<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\MonitoringType;
use App\Support\DnsRecordExpectation;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/**
 * Normalizes raw monitoring request input before validation and remembers inputs that could not be normalized.
 */
class MonitoringInputNormalizer
{
    private bool $invalidHttpHeadersJson = false;

    private bool $invalidDnsExpectedValues = false;

    public function __construct(private readonly Request $request) {}

    public function hasInvalidHttpHeadersJson(): bool
    {
        return $this->invalidHttpHeadersJson;
    }

    public function hasInvalidDnsExpectedValues(): bool
    {
        return $this->invalidDnsExpectedValues;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(): array
    {
        $hasGroupIds = $this->request->has('group_ids');
        $type = mb_strtolower((string) $this->request->input('type'));
        $dnsRecordType = DnsRecordExpectation::normalizeRecordType($this->request->input('dns_record_type'));
        $preferredLocations = $this->preferredLocations();

        $prepared = [
            'type' => $type,
            'http_headers' => $this->httpHeaders(),
            'dns_record_type' => $dnsRecordType,
            'dns_expected_values' => $this->dnsExpectedValues($type, $dnsRecordType),
            'preferred_location' => $preferredLocations[0] ?? null,
            'preferred_locations' => $preferredLocations,
            'public_label_enabled' => $this->request->boolean('public_label_enabled'),
            'notification_on_failure' => $this->request->boolean('notification_on_failure'),
            'notification_channels' => $this->notificationChannels(),
            'team_id' => $this->teamId(),
            'failure_confirmation_threshold' => $this->request->input('failure_confirmation_threshold', 2),
            'response_time_threshold_ms' => $this->nullableInteger('response_time_threshold_ms'),
            'response_time_confirmation_threshold' => $this->nullableInteger('response_time_confirmation_threshold'),
            'ssl_expiry_warning_days' => $this->request->input('ssl_expiry_warning_days', 7),
            'heartbeat_grace_minutes' => $this->request->input('heartbeat_grace_minutes', 5),
        ];

        if ($this->request->isMethod('post') || $this->request->has('response_time_degradation_percent')) {
            $prepared['response_time_degradation_percent'] = $this->nullableInteger('response_time_degradation_percent');
        }

        if ($hasGroupIds) {
            $prepared['group_ids'] = $this->groupIds();
        }

        if ($type === MonitoringType::SERVER_HEALTH->value) {
            $prepared['server_health_cpu_threshold_percent'] = $this->request->input('server_health_cpu_threshold_percent', 90);
            $prepared['server_health_ram_threshold_percent'] = $this->request->input('server_health_ram_threshold_percent', 90);
            $prepared['server_health_storage_threshold_percent'] = $this->request->input('server_health_storage_threshold_percent', 90);
            $prepared['server_health_load_threshold_per_cpu'] = $this->nullableFloat('server_health_load_threshold_per_cpu');
            $prepared['server_health_service_response_time_threshold_ms'] = $this->nullableInteger('server_health_service_response_time_threshold_ms');
            $prepared['server_health_report_interval_minutes'] = $this->request->input('server_health_report_interval_minutes', 1);
            $prepared['server_health_grace_minutes'] = $this->request->input('server_health_grace_minutes', 5);
        }

        return $prepared;
    }

    /**
     * @return list<string>
     */
    private function notificationChannels(): array
    {
        $channels = $this->request->input('notification_channels', []);

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
    private function groupIds(): array
    {
        $groupIds = $this->request->input('group_ids', []);

        if (! is_array($groupIds)) {
            $groupIds = [$groupIds];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $groupId): string => (string) $groupId, $groupIds),
            static fn (string $groupId): bool => $groupId !== ''
        )));
    }

    private function teamId(): ?string
    {
        $teamId = $this->request->input('team_id');

        if (! is_scalar($teamId)) {
            return null;
        }

        $teamId = mb_trim((string) $teamId);

        return $teamId === '' ? null : $teamId;
    }

    private function nullableInteger(string $key): ?int
    {
        $value = $this->request->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->request->input($key);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function preferredLocations(): array
    {
        $locations = $this->request->input('preferred_locations', $this->request->input('preferred_location', []));

        if (! is_array($locations)) {
            $locations = [$locations];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $location): string => (string) $location, $locations),
            static fn (string $location): bool => $location !== ''
        )));
    }

    private function httpHeaders(): mixed
    {
        $httpHeaders = $this->request->input('http_headers', $this->request->input('http_header'));

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
    private function dnsExpectedValues(string $type, ?string $recordType): mixed
    {
        $values = $this->request->input('dns_expected_values');

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
