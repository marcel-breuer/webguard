<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\IncidentDiagnosisEvidence;
use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDomainResult;
use App\Models\MonitoringResponse;
use App\Support\MonitoringHost;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

/**
 * Gathers the evidence available around an incident's start time.
 */
final class IncidentDiagnosisEvidenceCollector
{
    private const int FAILING_CHECK_LIMIT = 5;

    private const int FAILING_CHECK_LOOKBACK_MINUTES = 60;

    private const int REFERENCE_GRACE_MINUTES = 2;

    private const int RECENT_RESPONSE_TIME_MINUTES = 60;

    private const int BASELINE_RESPONSE_TIME_DAYS = 7;

    private const int CONFIG_CHANGE_LOOKBACK_HOURS = 24;

    private const int CORRELATION_WINDOW_MINUTES = 10;

    private const int CORRELATION_LIMIT = 10;

    private const int RELATED_DNS_LOOKBACK_MINUTES = 120;

    private const int FAILURE_REASON_MAX_LENGTH = 200;

    private const float DEFAULT_SERVER_HEALTH_THRESHOLD_PERCENT = 90.0;

    /**
     * Monitoring attributes whose change can plausibly alter check outcomes.
     * Only field names are kept; values may contain credentials.
     */
    private const array CONFIG_FIELDS = [
        'target',
        'port',
        'keyword',
        'dns_record_type',
        'dns_expected_values',
        'timeout',
        'http_method',
        'expected_http_statuses',
        'http_headers',
        'http_body',
        'auth_username',
        'auth_password',
        'preferred_location',
        'preferred_locations',
        'check_interval_minutes',
        'failure_confirmation_threshold',
        'heartbeat_interval_minutes',
        'heartbeat_grace_minutes',
        'server_health_cpu_threshold_percent',
        'server_health_ram_threshold_percent',
        'server_health_storage_threshold_percent',
        'server_health_load_threshold_per_cpu',
    ];

    public function __construct(
        private readonly MonitoringHealthEvaluator $monitoringHealthEvaluator,
        private readonly MaintenanceWindowService $maintenanceWindowService,
    ) {}

    public function collect(Incident $incident): ?IncidentDiagnosisEvidence
    {
        $monitoring = $incident->monitoring;

        if (! $monitoring instanceof Monitoring) {
            return null;
        }

        $referenceAt = Date::parse($incident->down_at);
        $host = MonitoringHost::for($monitoring);
        $failingResponses = $this->failingResponses($monitoring, $referenceAt);

        return new IncidentDiagnosisEvidence(
            referenceAt: $referenceAt->toIso8601String(),
            monitoringType: $monitoring->type,
            host: $host,
            timeoutMs: $monitoring->timeout !== null ? $monitoring->timeout * 1000 : null,
            consensus: $this->consensus($incident),
            failingChecks: array_map(
                fn (MonitoringResponse $monitoringResponse): array => $this->failingCheck($monitoring, $monitoringResponse),
                $failingResponses,
            ),
            responseTime: $this->responseTime($monitoring, $referenceAt),
            certificate: $this->certificate($monitoring, $referenceAt),
            domain: $this->domain($monitoring, $host, $referenceAt),
            dnsMismatches: $this->dnsMismatches($monitoring, $host, $failingResponses, $referenceAt),
            maintenanceActive: $this->maintenanceActive($monitoring, $referenceAt),
            configChanges: $this->configChanges($monitoring, $referenceAt),
            correlatedIncidents: $this->correlatedIncidents($incident, $monitoring, $host, $referenceAt),
        );
    }

    /**
     * @return array{status: string, total_locations: int, reporting_locations: int, affected_locations: list<string>, healthy_locations: list<string>, unavailable_locations: list<string>}|null
     */
    private function consensus(Incident $incident): ?array
    {
        $snapshot = $incident->consensus_snapshot;

        if (! is_array($snapshot) || ! is_string($snapshot['status'] ?? null)) {
            return null;
        }

        $locations = is_array($snapshot['locations'] ?? null) ? $snapshot['locations'] : [];
        $healthy = [];
        $unavailable = [];

        foreach ($locations as $location) {
            if (! is_array($location) || ! is_string($location['code'] ?? null)) {
                continue;
            }

            if (in_array($location['availability'] ?? null, ['stale', 'missing'], true)) {
                $unavailable[] = $location['code'];

                continue;
            }

            if (($location['status'] ?? null) === MonitoringStatus::UP->value) {
                $healthy[] = $location['code'];
            }
        }

        return [
            'status' => $snapshot['status'],
            'total_locations' => (int) ($snapshot['total_locations'] ?? count($locations)),
            'reporting_locations' => (int) ($snapshot['reporting_locations'] ?? 0),
            'affected_locations' => array_values(array_map(strval(...), is_array($snapshot['affected_locations'] ?? null) ? $snapshot['affected_locations'] : [])),
            'healthy_locations' => $healthy,
            'unavailable_locations' => $unavailable,
        ];
    }

    /**
     * @return list<MonitoringResponse>
     */
    private function failingResponses(Monitoring $monitoring, CarbonInterface $referenceAt): array
    {
        return $monitoring->responseResults()
            ->whereBetween('created_at', [
                $referenceAt->copy()->subMinutes(self::FAILING_CHECK_LOOKBACK_MINUTES),
                $referenceAt->copy()->addMinutes(self::REFERENCE_GRACE_MINUTES),
            ])
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::FAILING_CHECK_LIMIT)
            ->get()
            ->filter(fn (MonitoringResponse $monitoringResponse): bool => $this->monitoringHealthEvaluator->availabilityFor($monitoring, $monitoringResponse) === MonitoringStatus::DOWN)
            ->values()
            ->all();
    }

    /**
     * @return array{location_code: string|null, checked_at: string, http_status_code: int|null, transport_failed: bool, connection_failed: bool, response_time_ms: float|null, failure_reason: string|null, heartbeat_overdue: bool, server_health_breaches: list<string>}
     */
    private function failingCheck(Monitoring $monitoring, MonitoringResponse $monitoringResponse): array
    {
        $vitalValues = is_array($monitoringResponse->vital_values) ? $monitoringResponse->vital_values : [];
        $failureReason = is_string($vitalValues['failure_reason'] ?? null) ? mb_trim($vitalValues['failure_reason']) : '';

        return [
            'location_code' => $monitoringResponse->location_code,
            'checked_at' => $monitoringResponse->created_at->toIso8601String(),
            'http_status_code' => $monitoringResponse->http_status_code,
            'transport_failed' => ($vitalValues['transport_succeeded'] ?? null) === false,
            'connection_failed' => ($vitalValues['connection_succeeded'] ?? null) === false,
            'response_time_ms' => $monitoringResponse->response_time,
            'failure_reason' => $failureReason === '' ? null : mb_substr($failureReason, 0, self::FAILURE_REASON_MAX_LENGTH),
            'heartbeat_overdue' => ($vitalValues['heartbeat_overdue'] ?? null) === true,
            'server_health_breaches' => $monitoring->isServerHealth()
                ? $this->serverHealthBreaches($monitoring, $monitoringResponse->server_health_metrics)
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     * @return list<string>
     */
    private function serverHealthBreaches(Monitoring $monitoring, ?array $metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }

        $breaches = [];

        foreach ([
            'cpu_usage_percent' => $monitoring->server_health_cpu_threshold_percent,
            'ram_usage_percent' => $monitoring->server_health_ram_threshold_percent,
            'storage_usage_percent' => $monitoring->server_health_storage_threshold_percent,
        ] as $metric => $threshold) {
            if (is_numeric($metrics[$metric] ?? null) && (float) $metrics[$metric] >= (float) ($threshold ?? self::DEFAULT_SERVER_HEALTH_THRESHOLD_PERCENT)) {
                $breaches[] = $metric;
            }
        }

        $logicalCpuCount = $metrics['logical_cpu_count'] ?? null;
        $loadAverage = $metrics['load_average_1m'] ?? null;
        $loadThreshold = $monitoring->server_health_load_threshold_per_cpu;

        if (is_numeric($logicalCpuCount) && (int) $logicalCpuCount > 0 && is_numeric($loadAverage) && $loadThreshold !== null
            && ((float) $loadAverage / (int) $logicalCpuCount) >= (float) $loadThreshold) {
            $breaches[] = 'load_average_1m';
        }

        $serviceChecks = is_array($metrics['service_checks'] ?? null) ? $metrics['service_checks'] : [];

        foreach ($serviceChecks as $serviceCheck) {
            if (is_array($serviceCheck) && ($serviceCheck['success'] ?? true) === false) {
                $breaches[] = 'service:' . mb_substr((string) ($serviceCheck['name'] ?? 'unknown'), 0, 64);
            }
        }

        return $breaches;
    }

    /**
     * @return array{recent_avg_ms: float|null, recent_samples: int, baseline_avg_ms: float|null, baseline_samples: int}
     */
    private function responseTime(Monitoring $monitoring, CarbonInterface $referenceAt): array
    {
        $recentStart = $referenceAt->copy()->subMinutes(self::RECENT_RESPONSE_TIME_MINUTES);
        $recent = $this->averageResponseTime($monitoring, $recentStart, $referenceAt);
        $baseline = $this->averageResponseTime(
            $monitoring,
            $referenceAt->copy()->subDays(self::BASELINE_RESPONSE_TIME_DAYS),
            $recentStart,
        );

        return [
            'recent_avg_ms' => $recent['avg'],
            'recent_samples' => $recent['samples'],
            'baseline_avg_ms' => $baseline['avg'],
            'baseline_samples' => $baseline['samples'],
        ];
    }

    /**
     * @return array{avg: float|null, samples: int}
     */
    private function averageResponseTime(Monitoring $monitoring, CarbonInterface $from, CarbonInterface $until): array
    {
        $row = $monitoring->responseResults()
            ->whereNotNull('response_time')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->toBase()
            ->selectRaw('AVG(response_time) as average_ms, COUNT(*) as samples')
            ->first();

        $samples = (int) ($row->samples ?? 0);

        return [
            'avg' => $samples > 0 && is_numeric($row->average_ms ?? null) ? round((float) $row->average_ms, 1) : null,
            'samples' => $samples,
        ];
    }

    /**
     * @return array{is_valid: bool, expired: bool, expires_at: string|null}|null
     */
    private function certificate(Monitoring $monitoring, CarbonInterface $referenceAt): ?array
    {
        if (! in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            return null;
        }

        $sslResult = $monitoring->sslResult;

        if ($sslResult === null) {
            return null;
        }

        return [
            'is_valid' => (bool) $sslResult->is_valid,
            'expired' => $sslResult->expires_at !== null && $sslResult->expires_at->lte($referenceAt),
            'expires_at' => $sslResult->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{is_valid: bool, expired: bool, expires_at: string|null, domain: string}|null
     */
    private function domain(Monitoring $monitoring, ?string $host, CarbonInterface $referenceAt): ?array
    {
        $domainMonitoring = $monitoring->type === MonitoringType::DOMAIN_EXPIRATION
            ? $monitoring
            : $this->sameOwnerMonitorings($monitoring)
                ->where('type', MonitoringType::DOMAIN_EXPIRATION)
                ->with('domainResult')
                ->get()
                ->first(fn (Monitoring $candidate): bool => MonitoringHost::belongsToDomain($host, MonitoringHost::for($candidate)));

        $domainResult = $domainMonitoring?->domainResult;

        if (! $domainResult instanceof MonitoringDomainResult) {
            return null;
        }

        return [
            'is_valid' => (bool) $domainResult->is_valid,
            'expired' => $domainResult->expires_at !== null && $domainResult->expires_at->lte($referenceAt),
            'expires_at' => $domainResult->expires_at?->toIso8601String(),
            'domain' => (string) MonitoringHost::for($domainMonitoring),
        ];
    }

    /**
     * @param  list<MonitoringResponse>  $failingResponses
     * @return list<array{monitoring_id: string, monitoring_name: string, own_monitoring: bool, record_type: string, expected: list<string>, observed: list<string>}>
     */
    private function dnsMismatches(Monitoring $monitoring, ?string $host, array $failingResponses, CarbonInterface $referenceAt): array
    {
        $mismatches = [];

        if ($monitoring->type === MonitoringType::DNS_RECORD && $failingResponses !== []) {
            $mismatches[] = $this->dnsMismatch($monitoring, $failingResponses[0], ownMonitoring: true);
        }

        if ($host === null || $monitoring->type === MonitoringType::DNS_RECORD) {
            return $mismatches;
        }

        $relatedDnsMonitorings = $this->sameOwnerMonitorings($monitoring)
            ->where('type', MonitoringType::DNS_RECORD)
            ->get()
            ->filter(static fn (Monitoring $candidate): bool => MonitoringHost::same($host, MonitoringHost::for($candidate)));

        foreach ($relatedDnsMonitorings as $relatedDnsMonitoring) {
            $latestResponse = $relatedDnsMonitoring->responseResults()
                ->whereBetween('created_at', [
                    $referenceAt->copy()->subMinutes(self::RELATED_DNS_LOOKBACK_MINUTES),
                    $referenceAt->copy()->addMinutes(self::REFERENCE_GRACE_MINUTES),
                ])
                ->latest('created_at')
                ->orderByDesc('id')
                ->first();

            if ($latestResponse instanceof MonitoringResponse
                && $this->monitoringHealthEvaluator->availabilityFor($relatedDnsMonitoring, $latestResponse) === MonitoringStatus::DOWN) {
                $mismatches[] = $this->dnsMismatch($relatedDnsMonitoring, $latestResponse, ownMonitoring: false);
            }
        }

        return $mismatches;
    }

    /**
     * @return array{monitoring_id: string, monitoring_name: string, own_monitoring: bool, record_type: string, expected: list<string>, observed: list<string>}
     */
    private function dnsMismatch(Monitoring $monitoring, MonitoringResponse $monitoringResponse, bool $ownMonitoring): array
    {
        $vitalValues = is_array($monitoringResponse->vital_values) ? $monitoringResponse->vital_values : [];
        $observed = is_array($vitalValues['observed_values'] ?? null) ? $vitalValues['observed_values'] : [];

        return [
            'monitoring_id' => (string) $monitoring->getKey(),
            'monitoring_name' => (string) $monitoring->name,
            'own_monitoring' => $ownMonitoring,
            'record_type' => (string) ($monitoring->dns_record_type ?? ''),
            'expected' => array_values(array_map(strval(...), $monitoring->dns_expected_values ?? [])),
            'observed' => array_values(array_map(strval(...), array_filter($observed, is_scalar(...)))),
        ];
    }

    private function maintenanceActive(Monitoring $monitoring, CarbonInterface $referenceAt): bool
    {
        if ($monitoring->maintenance_from !== null
            && Date::parse($monitoring->maintenance_from)->lte($referenceAt)
            && ($monitoring->maintenance_until === null || Date::parse($monitoring->maintenance_until)->gte($referenceAt))) {
            return true;
        }

        return $this->maintenanceWindowService->isUnderMaintenance($monitoring, $referenceAt);
    }

    /**
     * @return list<array{changed_at: string, minutes_before: int, fields: list<string>}>
     */
    private function configChanges(Monitoring $monitoring, CarbonInterface $referenceAt): array
    {
        return Activity::query()
            ->where('subject_type', $monitoring->getMorphClass())
            ->where('subject_id', (string) $monitoring->getKey())
            ->where('event', 'updated')
            ->whereBetween('created_at', [$referenceAt->copy()->subHours(self::CONFIG_CHANGE_LOOKBACK_HOURS), $referenceAt])
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(function (Activity $activity) use ($referenceAt): ?array {
                $changes = collect($activity->attribute_changes)->get('attributes');
                $fields = is_array($changes)
                    ? array_values(array_intersect(self::CONFIG_FIELDS, array_map(strval(...), array_keys($changes))))
                    : [];

                if ($fields === [] || $activity->created_at === null) {
                    return null;
                }

                return [
                    'changed_at' => $activity->created_at->toIso8601String(),
                    'minutes_before' => max(0, (int) $activity->created_at->diffInMinutes($referenceAt)),
                    'fields' => $fields,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{incident_id: string, monitoring_id: string, monitoring_name: string, down_at: string, same_host: bool}>
     */
    private function correlatedIncidents(Incident $incident, Monitoring $monitoring, ?string $host, CarbonInterface $referenceAt): array
    {
        $monitoringIds = $this->sameOwnerMonitorings($monitoring)->select('id');

        return Incident::query()
            ->whereKeyNot($incident->getKey())
            ->whereIn('monitoring_id', $monitoringIds)
            ->whereBetween('down_at', [
                $referenceAt->copy()->subMinutes(self::CORRELATION_WINDOW_MINUTES),
                $referenceAt->copy()->addMinutes(self::CORRELATION_WINDOW_MINUTES),
            ])
            ->with('monitoring:id,name,type,target')
            ->oldest('down_at')
            ->oldest('id')
            ->limit(self::CORRELATION_LIMIT)
            ->get()
            ->filter(static fn (Incident $correlated): bool => $correlated->monitoring instanceof Monitoring)
            ->map(static fn (Incident $correlated): array => [
                'incident_id' => (string) $correlated->getKey(),
                'monitoring_id' => (string) $correlated->monitoring_id,
                'monitoring_name' => (string) $correlated->monitoring->name,
                'down_at' => Date::parse($correlated->down_at)->toIso8601String(),
                'same_host' => MonitoringHost::same($host, MonitoringHost::for($correlated->monitoring)),
            ])
            ->values()
            ->all();
    }

    /**
     * Other monitorings in the same ownership scope. Correlation never
     * crosses users or teams, so evidence stays visible to the same people.
     *
     * @return Builder<Monitoring>
     */
    private function sameOwnerMonitorings(Monitoring $monitoring): Builder
    {
        return Monitoring::query()
            ->whereKeyNot($monitoring->getKey())
            ->when(
                $monitoring->team_id !== null,
                static fn (Builder $builder): Builder => $builder->where('team_id', $monitoring->team_id),
                static fn (Builder $builder): Builder => $builder->where('user_id', $monitoring->user_id)->whereNull('team_id'),
            );
    }
}
