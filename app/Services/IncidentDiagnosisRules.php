<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\IncidentDiagnosisEvidence;
use App\Enums\IncidentDiagnosisCategory;
use App\Enums\MonitoringType;
use App\Enums\RegionalConsensusStatus;

/**
 * Deterministic, explainable root-cause rules. Each hypothesis carries a
 * 1-99 confidence score and the evidence items that produced it.
 *
 * @phpstan-type DiagnosisParams array<string, string|int|float>
 * @phpstan-type DiagnosisEvidenceItem array{type: string, key: string, params: DiagnosisParams}
 * @phpstan-type DiagnosisHypothesis array{rank: int, category: string, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}
 */
final class IncidentDiagnosisRules
{
    private const int MIN_CONFIDENCE = 1;

    private const int MAX_CONFIDENCE = 99;

    private const float TIMEOUT_RATIO = 0.9;

    private const float DEGRADATION_RATIO = 2.0;

    private const float SEVERE_DEGRADATION_RATIO = 4.0;

    private const int MIN_RECENT_SAMPLES = 3;

    private const int MIN_BASELINE_SAMPLES = 20;

    private const int MAX_LISTED_ITEMS = 5;

    /**
     * Configuration fields whose change directly alters what a check expects.
     */
    private const array CRITICAL_CONFIG_FIELDS = [
        'target',
        'port',
        'keyword',
        'dns_record_type',
        'dns_expected_values',
        'expected_http_statuses',
        'http_method',
        'http_headers',
        'http_body',
        'auth_username',
        'auth_password',
        'timeout',
    ];

    private const string TLS_PATTERN = '/\b(ssl|tls|certificate|x509|handshake)\b/i';

    private const string DNS_PATTERN = '/\b(dns|nxdomain|getaddrinfo|resolve|resolving|resolution|no such host|name or service not known)\b/i';

    private const string TIMEOUT_PATTERN = '/\b(time[ds]?[ -]?out|timed out|deadline exceeded)\b/i';

    /**
     * @return list<DiagnosisHypothesis>
     */
    public function evaluate(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $candidates = array_merge(
            $this->maintenance($incidentDiagnosisEvidence),
            $this->certificate($incidentDiagnosisEvidence),
            $this->domain($incidentDiagnosisEvidence),
            $this->dns($incidentDiagnosisEvidence),
            $this->regional($incidentDiagnosisEvidence),
            $this->httpStatus($incidentDiagnosisEvidence),
            $this->transport($incidentDiagnosisEvidence),
            $this->responseTimeTrend($incidentDiagnosisEvidence),
            $this->configChange($incidentDiagnosisEvidence),
            $this->sharedDependency($incidentDiagnosisEvidence),
            $this->heartbeat($incidentDiagnosisEvidence),
            $this->serverResources($incidentDiagnosisEvidence),
        );

        if ($candidates === []) {
            $candidates[] = $this->hypothesis(
                IncidentDiagnosisCategory::UNKNOWN,
                10,
                'unknown',
                [],
                [$this->evidence('checks', $incidentDiagnosisEvidence->failingChecks === [] ? 'no_failing_checks' : 'insufficient_evidence')],
            );
        }

        return $this->rank($this->merge($candidates));
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function maintenance(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        if (! $incidentDiagnosisEvidence->maintenanceActive) {
            return [];
        }

        return [$this->hypothesis(IncidentDiagnosisCategory::MAINTENANCE, 90, 'maintenance', [], [
            $this->evidence('maintenance', 'maintenance_active'),
        ])];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function certificate(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $hypotheses = [];
        $certificate = $incidentDiagnosisEvidence->certificate;

        if ($certificate !== null && $certificate['expired']) {
            $hypotheses[] = $this->hypothesis(IncidentDiagnosisCategory::CERTIFICATE, 95, 'certificate_expired', ['expires_at' => (string) $certificate['expires_at']], [
                $this->evidence('certificate', 'certificate_expired', ['expires_at' => (string) $certificate['expires_at']]),
            ]);
        } elseif ($certificate !== null && ! $certificate['is_valid']) {
            $hypotheses[] = $this->hypothesis(IncidentDiagnosisCategory::CERTIFICATE, 85, 'certificate_invalid', [], [
                $this->evidence('certificate', 'certificate_invalid'),
            ]);
        }

        $tlsReason = $this->firstFailureReason($incidentDiagnosisEvidence, self::TLS_PATTERN);

        if ($tlsReason !== null) {
            $hypotheses[] = $this->hypothesis(IncidentDiagnosisCategory::CERTIFICATE, 70, 'certificate_handshake', [], [
                $this->evidence('check', 'failure_reason', ['reason' => $tlsReason]),
            ]);
        }

        return $hypotheses;
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function domain(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $domain = $incidentDiagnosisEvidence->domain;

        if ($domain === null || (! $domain['expired'] && $domain['is_valid'])) {
            return [];
        }

        $key = $domain['expired'] ? 'domain_expired' : 'domain_invalid';
        $params = ['domain' => $domain['domain'], 'expires_at' => (string) $domain['expires_at']];

        return [$this->hypothesis(IncidentDiagnosisCategory::DOMAIN, $domain['expired'] ? 92 : 75, $key, $params, [
            $this->evidence('domain', $key, $params),
        ])];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function dns(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $hypotheses = [];

        foreach ($incidentDiagnosisEvidence->dnsMismatches as $dnsMismatch) {
            $params = [
                'monitoring' => $dnsMismatch['monitoring_name'],
                'record_type' => $dnsMismatch['record_type'],
                'expected' => $this->list($dnsMismatch['expected']),
                'observed' => $this->list($dnsMismatch['observed']),
            ];

            $hypotheses[] = $this->hypothesis(
                IncidentDiagnosisCategory::DNS,
                $dnsMismatch['own_monitoring'] ? 90 : 80,
                'dns_mismatch',
                ['record_type' => $dnsMismatch['record_type']],
                [$this->evidence('dns', 'dns_mismatch', $params)],
            );
        }

        $dnsReason = $this->firstFailureReason($incidentDiagnosisEvidence, self::DNS_PATTERN);

        if ($dnsReason !== null) {
            $hypotheses[] = $this->hypothesis(IncidentDiagnosisCategory::DNS, 65, 'dns_resolution', [], [
                $this->evidence('check', 'failure_reason', ['reason' => $dnsReason]),
            ]);
        }

        return $hypotheses;
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function regional(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $consensus = $incidentDiagnosisEvidence->consensus;

        if ($consensus === null) {
            return [];
        }

        $status = RegionalConsensusStatus::tryFrom($consensus['status']);

        if (! in_array($status, [RegionalConsensusStatus::LOCALIZED, RegionalConsensusStatus::REGIONAL], true)) {
            return [];
        }

        $evidence = [
            $this->evidence('consensus', 'consensus_partial', [
                'failed' => count($consensus['affected_locations']),
                'reporting' => $consensus['reporting_locations'],
                'total' => $consensus['total_locations'],
                'locations' => $this->list($consensus['affected_locations']),
            ]),
        ];

        if ($consensus['healthy_locations'] !== []) {
            $evidence[] = $this->evidence('consensus', 'healthy_locations', ['locations' => $this->list($consensus['healthy_locations'])]);
        }

        if ($consensus['unavailable_locations'] !== []) {
            $evidence[] = $this->evidence('consensus', 'unavailable_locations', ['locations' => $this->list($consensus['unavailable_locations'])]);
        }

        return [$this->hypothesis(
            IncidentDiagnosisCategory::NETWORK_REGIONAL,
            $status === RegionalConsensusStatus::LOCALIZED ? 75 : 60,
            'network_regional',
            ['locations' => $this->list($consensus['affected_locations'])],
            $evidence,
        )];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function httpStatus(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $checks = $incidentDiagnosisEvidence->failingChecks;
        $total = count($checks);
        $serverCodes = [];
        $clientCodes = [];

        foreach ($checks as $check) {
            $code = $check['http_status_code'];

            if ($code === null || $check['transport_failed']) {
                continue;
            }

            if ($code >= 500) {
                $serverCodes[] = $code;
            } elseif ($code >= 400) {
                $clientCodes[] = $code;
            }
        }

        $hypotheses = [];
        $globalEvidence = $this->globalConsensusEvidence($incidentDiagnosisEvidence);

        if ($serverCodes !== []) {
            $code = $this->mostFrequent($serverCodes);
            $key = match ($code) {
                502, 504 => 'server_error_gateway',
                503 => 'server_error_unavailable',
                default => 'server_error',
            };

            $hypotheses[] = $this->hypothesis(
                IncidentDiagnosisCategory::SERVER_ERROR,
                75 + (count($serverCodes) === $total ? 10 : 0) + ($globalEvidence !== [] ? 5 : 0),
                $key,
                ['status' => $code],
                [
                    $this->evidence('check', 'http_status', ['status' => $code, 'count' => count($serverCodes), 'total' => $total]),
                    ...$globalEvidence,
                ],
            );
        }

        if ($clientCodes !== []) {
            $code = $this->mostFrequent($clientCodes);
            $key = match ($code) {
                401, 403 => 'client_error_forbidden',
                404, 410 => 'client_error_not_found',
                429 => 'client_error_rate_limited',
                default => 'client_error',
            };

            $hypotheses[] = $this->hypothesis(
                IncidentDiagnosisCategory::CLIENT_ERROR,
                65 + (count($clientCodes) === $total ? 10 : 0),
                $key,
                ['status' => $code],
                [$this->evidence('check', 'http_status', ['status' => $code, 'count' => count($clientCodes), 'total' => $total])],
            );
        }

        return $hypotheses;
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function transport(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $checks = $incidentDiagnosisEvidence->failingChecks;
        $total = count($checks);
        $timedOut = 0;
        $failed = 0;
        $reason = null;

        foreach ($checks as $check) {
            if (! $check['transport_failed'] && ! $check['connection_failed']) {
                continue;
            }

            $failureReason = $check['failure_reason'] ?? '';

            if ($this->isTimeout($check['response_time_ms'], $incidentDiagnosisEvidence->timeoutMs, $failureReason)) {
                $timedOut++;

                continue;
            }

            if ($failureReason !== '' && (preg_match(self::TLS_PATTERN, $failureReason) === 1 || preg_match(self::DNS_PATTERN, $failureReason) === 1)) {
                continue;
            }

            $failed++;
            $reason ??= $failureReason !== '' ? $failureReason : null;
        }

        $hypotheses = [];

        if ($timedOut > 0) {
            $timeoutSeconds = $incidentDiagnosisEvidence->timeoutMs !== null ? intdiv($incidentDiagnosisEvidence->timeoutMs, 1000) : 0;

            $hypotheses[] = $this->hypothesis(
                IncidentDiagnosisCategory::TIMEOUT_PERFORMANCE,
                70 + ($timedOut === $total ? 10 : 0),
                'timeout',
                ['timeout' => $timeoutSeconds],
                [$this->evidence('check', 'check_timed_out', ['count' => $timedOut, 'total' => $total, 'timeout' => $timeoutSeconds])],
            );
        }

        if ($failed > 0) {
            $evidence = [$this->evidence('check', 'transport_failed', ['count' => $failed, 'total' => $total])];

            if ($reason !== null) {
                $evidence[] = $this->evidence('check', 'failure_reason', ['reason' => $reason]);
            }

            $globalEvidence = $this->globalConsensusEvidence($incidentDiagnosisEvidence);

            $hypotheses[] = $this->hypothesis(
                IncidentDiagnosisCategory::CONNECTIVITY,
                65 + ($failed === $total ? 10 : 0) + ($globalEvidence !== [] ? 5 : 0),
                'connectivity',
                [],
                [...$evidence, ...$globalEvidence],
            );
        }

        return $hypotheses;
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function responseTimeTrend(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $responseTime = $incidentDiagnosisEvidence->responseTime;
        $recent = $responseTime['recent_avg_ms'];
        $baseline = $responseTime['baseline_avg_ms'];

        if ($recent === null || $baseline === null || $baseline <= 0
            || $responseTime['recent_samples'] < self::MIN_RECENT_SAMPLES
            || $responseTime['baseline_samples'] < self::MIN_BASELINE_SAMPLES) {
            return [];
        }

        $ratio = $recent / $baseline;

        if ($ratio < self::DEGRADATION_RATIO) {
            return [];
        }

        $params = [
            'recent' => (int) round($recent),
            'baseline' => (int) round($baseline),
            'ratio' => round($ratio, 1),
        ];

        return [$this->hypothesis(
            IncidentDiagnosisCategory::TIMEOUT_PERFORMANCE,
            $ratio >= self::SEVERE_DEGRADATION_RATIO ? 65 : 50,
            'performance_degraded',
            $params,
            [$this->evidence('response_time', 'response_time_degraded', $params)],
        )];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function configChange(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $changes = $incidentDiagnosisEvidence->configChanges;

        if ($changes === []) {
            return [];
        }

        $latest = $changes[0];
        $fields = array_values(array_unique(array_merge(...array_map(static fn (array $change): array => $change['fields'], $changes))));
        $confidence = match (true) {
            $latest['minutes_before'] <= 60 => 70,
            $latest['minutes_before'] <= 360 => 55,
            default => 40,
        };

        if (array_intersect($fields, self::CRITICAL_CONFIG_FIELDS) !== []) {
            $confidence += 10;
        }

        $evidence = array_map(
            fn (array $change): array => $this->evidence('config', 'config_changed', [
                'fields' => $this->list($change['fields']),
                'changed_at' => $change['changed_at'],
                'minutes' => $change['minutes_before'],
            ]),
            array_slice($changes, 0, self::MAX_LISTED_ITEMS),
        );

        return [$this->hypothesis(
            IncidentDiagnosisCategory::CONFIG_CHANGE,
            $confidence,
            'config_change',
            ['minutes' => $latest['minutes_before'], 'fields' => $this->list($fields)],
            $evidence,
        )];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function sharedDependency(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $correlated = $incidentDiagnosisEvidence->correlatedIncidents;
        $count = count($correlated);

        if ($count === 0) {
            return [];
        }

        $sameHost = array_values(array_filter($correlated, static fn (array $incident): bool => $incident['same_host']));
        $confidence = min(80, 45 + (10 * ($count - 1))) + ($sameHost !== [] ? 10 : 0);
        $evidence = array_map(
            fn (array $incident): array => $this->evidence('correlation', $incident['same_host'] ? 'correlated_incident_same_host' : 'correlated_incident', [
                'monitoring' => $incident['monitoring_name'],
                'down_at' => $incident['down_at'],
            ]),
            array_slice($correlated, 0, self::MAX_LISTED_ITEMS),
        );

        return [$this->hypothesis(
            IncidentDiagnosisCategory::SHARED_DEPENDENCY,
            $confidence,
            $sameHost !== [] ? 'shared_dependency_same_host' : 'shared_dependency',
            ['count' => $count],
            $evidence,
        )];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function heartbeat(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        if ($incidentDiagnosisEvidence->monitoringType !== MonitoringType::HEARTBEAT) {
            return [];
        }

        $overdue = array_filter($incidentDiagnosisEvidence->failingChecks, static fn (array $check): bool => $check['heartbeat_overdue']);

        return [$this->hypothesis(
            IncidentDiagnosisCategory::MISSED_HEARTBEAT,
            $overdue !== [] ? 85 : 60,
            'missed_heartbeat',
            [],
            [$this->evidence('check', $overdue !== [] ? 'heartbeat_overdue' : 'heartbeat_missing')],
        )];
    }

    /**
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function serverResources(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $breaches = array_values(array_unique(array_merge(
            ...array_map(static fn (array $check): array => $check['server_health_breaches'], $incidentDiagnosisEvidence->failingChecks),
        )));

        if ($breaches === []) {
            return [];
        }

        return [$this->hypothesis(
            IncidentDiagnosisCategory::SERVER_RESOURCES,
            80,
            'server_resources',
            ['metrics' => $this->list($breaches)],
            [$this->evidence('server_health', 'server_health_breach', ['metrics' => $this->list($breaches)])],
        )];
    }

    /**
     * @return list<DiagnosisEvidenceItem>
     */
    private function globalConsensusEvidence(IncidentDiagnosisEvidence $incidentDiagnosisEvidence): array
    {
        $consensus = $incidentDiagnosisEvidence->consensus;

        if ($consensus === null || $consensus['status'] !== RegionalConsensusStatus::GLOBAL->value) {
            return [];
        }

        return [$this->evidence('consensus', 'consensus_global', [
            'failed' => count($consensus['affected_locations']),
            'total' => $consensus['total_locations'],
        ])];
    }

    private function isTimeout(?float $responseTimeMs, ?int $timeoutMs, string $failureReason): bool
    {
        if ($failureReason !== '' && preg_match(self::TIMEOUT_PATTERN, $failureReason) === 1) {
            return true;
        }

        return $responseTimeMs !== null && $timeoutMs !== null && $timeoutMs > 0
            && $responseTimeMs >= $timeoutMs * self::TIMEOUT_RATIO;
    }

    private function firstFailureReason(IncidentDiagnosisEvidence $incidentDiagnosisEvidence, string $pattern): ?string
    {
        foreach ($incidentDiagnosisEvidence->failingChecks as $check) {
            $reason = $check['failure_reason'];

            if ($reason !== null && preg_match($pattern, $reason) === 1) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $values
     */
    private function mostFrequent(array $values): int
    {
        $counts = array_count_values($values);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * @param  list<string>  $values
     */
    private function list(array $values): string
    {
        if ($values === []) {
            return '—';
        }

        $listed = array_slice($values, 0, self::MAX_LISTED_ITEMS);
        $remaining = count($values) - count($listed);

        return implode(', ', $listed) . ($remaining > 0 ? sprintf(' (+%d)', $remaining) : '');
    }

    /**
     * @param  DiagnosisParams  $summaryParams
     * @param  list<DiagnosisEvidenceItem>  $evidence
     * @return array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}
     */
    private function hypothesis(IncidentDiagnosisCategory $incidentDiagnosisCategory, int $confidence, string $summaryKey, array $summaryParams, array $evidence): array
    {
        return [
            'category' => $incidentDiagnosisCategory,
            'confidence' => max(self::MIN_CONFIDENCE, min(self::MAX_CONFIDENCE, $confidence)),
            'summary' => ['key' => $summaryKey, 'params' => $summaryParams],
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  DiagnosisParams  $params
     * @return DiagnosisEvidenceItem
     */
    private function evidence(string $type, string $key, array $params = []): array
    {
        return ['type' => $type, 'key' => $key, 'params' => $params];
    }

    /**
     * Combine hypotheses of one category: the strongest summary wins, each
     * additional independent signal adds a small corroboration bonus.
     *
     * @param  list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>  $candidates
     * @return list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>
     */
    private function merge(array $candidates): array
    {
        $merged = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['category']->value;

            if (! isset($merged[$key])) {
                $merged[$key] = $candidate;

                continue;
            }

            $existing = $merged[$key];
            $strongest = $candidate['confidence'] > $existing['confidence'] ? $candidate : $existing;
            $evidence = $existing['evidence'];

            foreach ($candidate['evidence'] as $item) {
                if (! in_array($item, $evidence, true)) {
                    $evidence[] = $item;
                }
            }

            $merged[$key] = $this->hypothesis(
                $strongest['category'],
                max($existing['confidence'], $candidate['confidence']) + 5,
                $strongest['summary']['key'],
                $strongest['summary']['params'],
                $evidence,
            );
        }

        return array_values($merged);
    }

    /**
     * @param  list<array{category: IncidentDiagnosisCategory, confidence: int, summary: array{key: string, params: DiagnosisParams}, evidence: list<DiagnosisEvidenceItem>}>  $hypotheses
     * @return list<DiagnosisHypothesis>
     */
    private function rank(array $hypotheses): array
    {
        $order = array_flip(IncidentDiagnosisCategory::values());

        usort($hypotheses, static fn (array $left, array $right): int => [$right['confidence'], $order[$left['category']->value]]
            <=> [$left['confidence'], $order[$right['category']->value]]);

        $ranked = [];

        foreach ($hypotheses as $index => $hypothesis) {
            $ranked[] = [
                'rank' => $index + 1,
                'category' => $hypothesis['category']->value,
                'confidence' => $hypothesis['confidence'],
                'summary' => $hypothesis['summary'],
                'evidence' => $hypothesis['evidence'],
            ];
        }

        return $ranked;
    }
}
