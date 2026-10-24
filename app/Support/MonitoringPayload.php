<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Services\MonitoringCheckIntervalService;
use Illuminate\Support\Str;

final class MonitoringPayload
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function prepareStore(array $validated): array
    {
        $type = MonitoringType::tryFrom((string) ($validated['type'] ?? ''));

        if ($type === MonitoringType::DOMAIN_EXPIRATION) {
            return self::applyDomainDefaults($validated);
        }

        if ($type === MonitoringType::DNS_RECORD) {
            return self::applyDnsDefaults($validated);
        }

        if (in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            $validated['check_interval_minutes'] ??= MonitoringCheckIntervalService::DEFAULT_WEBSITE_INTERVAL_MINUTES;
            $validated['expected_http_statuses'] = HttpStatusCodeRanges::normalize($validated['expected_http_statuses'] ?? null);

            return $validated;
        }

        if ($type === MonitoringType::SERVER_HEALTH) {
            $serverHealthToken = (string) Str::ulid();

            $validated['server_health_token'] = $serverHealthToken;
            $validated['target'] = route('server-health.store', ['token' => $serverHealthToken]);

            return self::applyServerHealthDefaults($validated);
        }

        if ($type !== MonitoringType::HEARTBEAT) {
            $validated['expected_http_statuses'] = null;

            return $validated;
        }

        $heartbeatToken = (string) Str::ulid();

        $validated['heartbeat_token'] = $heartbeatToken;
        $validated['target'] = route('monitorings.heartbeat.ping', ['token' => $heartbeatToken]);

        return self::applyNonHttpDefaults($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function prepareUpdate(array $validated, Monitoring $monitoring): array
    {
        $validated['target'] ??= $monitoring->target;
        $requestedType = MonitoringType::tryFrom((string) ($validated['type'] ?? $monitoring->type->value));

        if (in_array($requestedType, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            $validated['check_interval_minutes'] ??= $monitoring->check_interval_minutes ?? MonitoringCheckIntervalService::DEFAULT_WEBSITE_INTERVAL_MINUTES;
        } else {
            unset($validated['check_interval_minutes']);
        }

        if ($monitoring->type === MonitoringType::DOMAIN_EXPIRATION) {
            return self::applyDomainDefaults($validated);
        }

        if ($monitoring->type === MonitoringType::DNS_RECORD) {
            return self::applyDnsDefaults($validated);
        }

        if (in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            $validated['expected_http_statuses'] = HttpStatusCodeRanges::normalize($validated['expected_http_statuses'] ?? null);

            return $validated;
        }

        if ($monitoring->isServerHealth()) {
            $validated['target'] = $monitoring->target;

            return self::applyServerHealthDefaults($validated);
        }

        if (! $monitoring->isHeartbeat()) {
            $validated['expected_http_statuses'] = null;

            return $validated;
        }

        $validated['target'] = $monitoring->target;

        return self::applyNonHttpDefaults($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function applyDomainDefaults(array $validated): array
    {
        $validated['target'] = mb_strtolower(mb_trim((string) $validated['target']));

        return self::applyNonHttpDefaults($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function applyDnsDefaults(array $validated): array
    {
        $validated['target'] = mb_strtolower(mb_trim((string) $validated['target']));
        $validated['dns_record_type'] = DnsRecordExpectation::normalizeRecordType($validated['dns_record_type'] ?? null);
        $validated['dns_expected_values'] = DnsRecordExpectation::normalizeValues(
            $validated['dns_expected_values'] ?? [],
            $validated['dns_record_type']
        );

        return self::applyNonHttpDefaults($validated, clearDnsFields: false);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function applyNonHttpDefaults(array $validated, bool $clearDnsFields = true): array
    {
        $validated['timeout'] = 5;
        $validated['http_method'] = null;
        $validated['expected_http_statuses'] = null;
        $validated['http_headers'] = null;
        $validated['http_body'] = null;
        $validated['auth_username'] = null;
        $validated['auth_password'] = null;
        $validated['port'] = null;
        $validated['keyword'] = null;

        if ($clearDnsFields) {
            $validated['dns_record_type'] = null;
            $validated['dns_expected_values'] = null;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function applyServerHealthDefaults(array $validated): array
    {
        $validated['server_health_cpu_threshold_percent'] = (float) ($validated['server_health_cpu_threshold_percent'] ?? 90);
        $validated['server_health_ram_threshold_percent'] = (float) ($validated['server_health_ram_threshold_percent'] ?? 90);
        $validated['server_health_storage_threshold_percent'] = (float) ($validated['server_health_storage_threshold_percent'] ?? 90);
        $validated['server_health_load_threshold_per_cpu'] = isset($validated['server_health_load_threshold_per_cpu'])
            ? (float) $validated['server_health_load_threshold_per_cpu']
            : null;
        $validated['server_health_service_response_time_threshold_ms'] = $validated['server_health_service_response_time_threshold_ms'] ?? null;
        $validated['server_health_report_interval_minutes'] = (int) ($validated['server_health_report_interval_minutes'] ?? 1);
        $validated['server_health_grace_minutes'] = (int) ($validated['server_health_grace_minutes'] ?? 5);

        return self::applyNonHttpDefaults($validated);
    }
}
