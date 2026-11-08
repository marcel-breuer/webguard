<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MonitoringType;

/**
 * Event-time facts collected for one incident. The diagnosis rules only read
 * these values, which keeps the rule evaluation deterministic and testable.
 *
 * @phpstan-type ConsensusEvidence array{status: string, total_locations: int, reporting_locations: int, affected_locations: list<string>, healthy_locations: list<string>, unavailable_locations: list<string>}
 * @phpstan-type FailingCheckEvidence array{location_code: string|null, checked_at: string, http_status_code: int|null, transport_failed: bool, connection_failed: bool, response_time_ms: float|null, failure_reason: string|null, heartbeat_overdue: bool, server_health_breaches: list<string>}
 * @phpstan-type ResponseTimeEvidence array{recent_avg_ms: float|null, recent_samples: int, baseline_avg_ms: float|null, baseline_samples: int}
 * @phpstan-type CertificateEvidence array{is_valid: bool, expired: bool, expires_at: string|null}
 * @phpstan-type DomainEvidence array{is_valid: bool, expired: bool, expires_at: string|null, domain: string}
 * @phpstan-type DnsMismatchEvidence array{monitoring_id: string, monitoring_name: string, own_monitoring: bool, record_type: string, expected: list<string>, observed: list<string>}
 * @phpstan-type ConfigChangeEvidence array{changed_at: string, minutes_before: int, fields: list<string>}
 * @phpstan-type CorrelatedIncidentEvidence array{incident_id: string, monitoring_id: string, monitoring_name: string, down_at: string, same_host: bool}
 */
final readonly class IncidentDiagnosisEvidence
{
    /**
     * @param  ConsensusEvidence|null  $consensus
     * @param  list<FailingCheckEvidence>  $failingChecks
     * @param  ResponseTimeEvidence  $responseTime
     * @param  CertificateEvidence|null  $certificate
     * @param  DomainEvidence|null  $domain
     * @param  list<DnsMismatchEvidence>  $dnsMismatches
     * @param  list<ConfigChangeEvidence>  $configChanges
     * @param  list<CorrelatedIncidentEvidence>  $correlatedIncidents
     */
    public function __construct(
        public string $referenceAt,
        public MonitoringType $monitoringType,
        public ?string $host,
        public ?int $timeoutMs,
        public ?array $consensus,
        public array $failingChecks,
        public array $responseTime,
        public ?array $certificate,
        public ?array $domain,
        public array $dnsMismatches,
        public bool $maintenanceActive,
        public array $configChanges,
        public array $correlatedIncidents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference_at' => $this->referenceAt,
            'monitoring_type' => $this->monitoringType->value,
            'timeout_ms' => $this->timeoutMs,
            'consensus' => $this->consensus,
            'failing_checks' => $this->failingChecks,
            'response_time' => $this->responseTime,
            'certificate' => $this->certificate,
            'domain' => $this->domain,
            'dns_mismatches' => $this->dnsMismatches,
            'maintenance_active' => $this->maintenanceActive,
            'config_changes' => $this->configChanges,
            'correlated_incidents' => $this->correlatedIncidents,
        ];
    }
}
