<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\IncidentDiagnosisEvidence;
use App\Enums\MonitoringType;
use App\Services\IncidentDiagnosisRules;
use PHPUnit\Framework\TestCase;

class IncidentDiagnosisRulesTest extends TestCase
{
    public function test_expired_certificate_is_the_top_hypothesis(): void
    {
        $hypotheses = $this->evaluate([
            'certificate' => ['is_valid' => false, 'expired' => true, 'expires_at' => '2026-11-01T00:00:00+00:00'],
            'failingChecks' => [$this->check(['transport_failed' => true])],
        ]);

        $this->assertSame('certificate', $hypotheses[0]['category']);
        $this->assertSame(1, $hypotheses[0]['rank']);
        $this->assertGreaterThanOrEqual(90, $hypotheses[0]['confidence']);
        $this->assertSame('certificate_expired', $hypotheses[0]['summary']['key']);
        $this->assertSame('certificate', $hypotheses[0]['evidence'][0]['type']);
    }

    public function test_tls_failure_reason_supports_certificate_hypothesis_without_ssl_result(): void
    {
        $hypotheses = $this->evaluate([
            'failingChecks' => [$this->check(['transport_failed' => true, 'failure_reason' => 'SSL certificate problem: self signed'])],
        ]);

        $this->assertSame('certificate', $hypotheses[0]['category']);
        $this->assertSame('certificate_handshake', $hypotheses[0]['summary']['key']);
        $this->assertNotContains('connectivity', array_column($hypotheses, 'category'));
    }

    public function test_expired_domain_outranks_generic_connectivity(): void
    {
        $hypotheses = $this->evaluate([
            'domain' => ['is_valid' => false, 'expired' => true, 'expires_at' => '2026-11-01T00:00:00+00:00', 'domain' => 'example.test'],
            'failingChecks' => [$this->check(['transport_failed' => true, 'failure_reason' => 'connection refused'])],
        ]);

        $this->assertSame('domain', $hypotheses[0]['category']);
        $this->assertContains('connectivity', array_column($hypotheses, 'category'));
    }

    public function test_dns_mismatch_on_related_monitoring_is_reported(): void
    {
        $hypotheses = $this->evaluate([
            'dnsMismatches' => [[
                'monitoring_id' => '01', 'monitoring_name' => 'DNS A', 'own_monitoring' => false,
                'record_type' => 'A', 'expected' => ['203.0.113.10'], 'observed' => ['203.0.113.99'],
            ]],
            'failingChecks' => [$this->check(['transport_failed' => true])],
        ]);

        $this->assertSame('dns', $hypotheses[0]['category']);
        $this->assertSame(80, $hypotheses[0]['confidence']);
        $this->assertSame(['record_type' => 'A'], $hypotheses[0]['summary']['params']);
    }

    public function test_localized_consensus_points_to_regional_network(): void
    {
        $hypotheses = $this->evaluate([
            'consensus' => [
                'status' => 'localized', 'total_locations' => 3, 'reporting_locations' => 3,
                'affected_locations' => ['us-1'], 'healthy_locations' => ['de-1', 'jp-1'], 'unavailable_locations' => [],
            ],
            'failingChecks' => [$this->check(['transport_failed' => true])],
        ]);

        $this->assertSame('network_regional', $hypotheses[0]['category']);
        $this->assertCount(2, $hypotheses[0]['evidence']);
    }

    public function test_global_consensus_with_5xx_ranks_server_error_first(): void
    {
        $hypotheses = $this->evaluate([
            'consensus' => [
                'status' => 'global', 'total_locations' => 3, 'reporting_locations' => 3,
                'affected_locations' => ['a', 'b', 'c'], 'healthy_locations' => [], 'unavailable_locations' => [],
            ],
            'failingChecks' => [$this->check(['http_status_code' => 503]), $this->check(['http_status_code' => 503])],
        ]);

        $this->assertSame('server_error', $hypotheses[0]['category']);
        $this->assertSame('server_error_unavailable', $hypotheses[0]['summary']['key']);
        $this->assertSame(90, $hypotheses[0]['confidence']);
        $this->assertNotContains('network_regional', array_column($hypotheses, 'category'));
    }

    public function test_client_error_status_is_classified_separately(): void
    {
        $hypotheses = $this->evaluate(['failingChecks' => [$this->check(['http_status_code' => 404])]]);

        $this->assertSame('client_error', $hypotheses[0]['category']);
        $this->assertSame('client_error_not_found', $hypotheses[0]['summary']['key']);
    }

    public function test_slow_failing_checks_are_timeouts(): void
    {
        $hypotheses = $this->evaluate([
            'timeoutMs' => 10_000,
            'failingChecks' => [$this->check(['transport_failed' => true, 'response_time_ms' => 9_800.0])],
        ]);

        $this->assertSame('timeout_performance', $hypotheses[0]['category']);
        $this->assertSame('timeout', $hypotheses[0]['summary']['key']);
        $this->assertSame(['timeout' => 10], $hypotheses[0]['summary']['params']);
    }

    public function test_response_time_degradation_needs_enough_samples(): void
    {
        $degraded = ['recent_avg_ms' => 900.0, 'recent_samples' => 6, 'baseline_avg_ms' => 200.0, 'baseline_samples' => 100];

        $this->assertSame('timeout_performance', $this->evaluate(['responseTime' => $degraded, 'failingChecks' => [$this->check()]])[0]['category']);

        $sparse = [...$degraded, 'baseline_samples' => 5];
        $this->assertNotContains('timeout_performance', array_column(
            $this->evaluate(['responseTime' => $sparse, 'failingChecks' => [$this->check()]]),
            'category',
        ));
    }

    public function test_recent_configuration_change_is_a_hypothesis(): void
    {
        $hypotheses = $this->evaluate([
            'configChanges' => [['changed_at' => '2026-11-08T11:50:00+00:00', 'minutes_before' => 10, 'fields' => ['target']]],
            'failingChecks' => [$this->check(['http_status_code' => 404])],
        ]);

        $this->assertSame('config_change', $hypotheses[0]['category']);
        $this->assertSame(80, $hypotheses[0]['confidence']);
    }

    public function test_correlated_incidents_on_same_host_suggest_shared_dependency(): void
    {
        $correlated = [
            ['incident_id' => '1', 'monitoring_id' => 'a', 'monitoring_name' => 'API', 'down_at' => '2026-11-08T12:01:00+00:00', 'same_host' => true],
            ['incident_id' => '2', 'monitoring_id' => 'b', 'monitoring_name' => 'Shop', 'down_at' => '2026-11-08T12:02:00+00:00', 'same_host' => false],
        ];

        $hypotheses = $this->evaluate(['correlatedIncidents' => $correlated, 'failingChecks' => [$this->check()]]);

        $this->assertSame('shared_dependency', $hypotheses[0]['category']);
        $this->assertSame('shared_dependency_same_host', $hypotheses[0]['summary']['key']);
        $this->assertCount(2, $hypotheses[0]['evidence']);
    }

    public function test_active_maintenance_is_reported_with_high_confidence(): void
    {
        $hypotheses = $this->evaluate(['maintenanceActive' => true, 'failingChecks' => [$this->check(['http_status_code' => 503])]]);

        $this->assertSame('maintenance', $hypotheses[0]['category']);
    }

    public function test_heartbeat_and_server_health_signals(): void
    {
        $heartbeat = $this->evaluate([
            'monitoringType' => MonitoringType::HEARTBEAT,
            'failingChecks' => [$this->check(['heartbeat_overdue' => true])],
        ]);
        $this->assertSame('missed_heartbeat', $heartbeat[0]['category']);

        $health = $this->evaluate([
            'monitoringType' => MonitoringType::SERVER_HEALTH,
            'failingChecks' => [$this->check(['server_health_breaches' => ['cpu_usage_percent']])],
        ]);
        $this->assertSame('server_resources', $health[0]['category']);
    }

    public function test_corroborating_signals_in_one_category_raise_confidence_once(): void
    {
        $single = $this->evaluate(['failingChecks' => [$this->check(['transport_failed' => true, 'failure_reason' => 'connection refused'])]]);
        $double = $this->evaluate([
            'certificate' => ['is_valid' => false, 'expired' => false, 'expires_at' => null],
            'failingChecks' => [$this->check(['transport_failed' => true, 'failure_reason' => 'TLS handshake failed'])],
        ]);

        $this->assertSame('connectivity', $single[0]['category']);
        $this->assertSame(75, $single[0]['confidence']);
        $this->assertSame('certificate', $double[0]['category']);
        $this->assertSame(90, $double[0]['confidence']);
        $this->assertCount(2, $double[0]['evidence']);
    }

    public function test_unknown_hypothesis_is_returned_when_no_rule_matches(): void
    {
        $hypotheses = $this->evaluate([]);

        $this->assertCount(1, $hypotheses);
        $this->assertSame('unknown', $hypotheses[0]['category']);
        $this->assertSame('no_failing_checks', $hypotheses[0]['evidence'][0]['key']);
    }

    public function test_hypotheses_are_ranked_deterministically(): void
    {
        $overrides = [
            'certificate' => ['is_valid' => false, 'expired' => true, 'expires_at' => null],
            'configChanges' => [['changed_at' => '2026-11-08T11:50:00+00:00', 'minutes_before' => 10, 'fields' => ['target']]],
            'failingChecks' => [$this->check(['http_status_code' => 500])],
        ];

        $first = $this->evaluate($overrides);
        $this->assertSame($first, $this->evaluate($overrides));
        $this->assertSame(range(1, count($first)), array_column($first, 'rank'));
        $confidences = array_column($first, 'confidence');
        $sorted = $confidences;
        rsort($sorted);
        $this->assertSame($sorted, $confidences);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<array<string, mixed>>
     */
    private function evaluate(array $overrides): array
    {
        $defaults = [
            'referenceAt' => '2026-11-08T12:00:00+00:00',
            'monitoringType' => MonitoringType::HTTP,
            'host' => 'example.test',
            'timeoutMs' => 10_000,
            'consensus' => null,
            'failingChecks' => [],
            'responseTime' => ['recent_avg_ms' => null, 'recent_samples' => 0, 'baseline_avg_ms' => null, 'baseline_samples' => 0],
            'certificate' => null,
            'domain' => null,
            'dnsMismatches' => [],
            'maintenanceActive' => false,
            'configChanges' => [],
            'correlatedIncidents' => [],
        ];

        return (new IncidentDiagnosisRules())->evaluate(new IncidentDiagnosisEvidence(...array_replace($defaults, $overrides)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function check(array $overrides = []): array
    {
        return array_replace([
            'location_code' => 'de-1',
            'checked_at' => '2026-11-08T11:59:00+00:00',
            'http_status_code' => null,
            'transport_failed' => false,
            'connection_failed' => false,
            'response_time_ms' => null,
            'failure_reason' => null,
            'heartbeat_overdue' => false,
            'server_health_breaches' => [],
        ], $overrides);
    }
}
