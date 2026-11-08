<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Requests;

use App\Enums\TeamRole;
use App\Http\Requests\InternalUiMonitoringRequest;
use App\Http\Requests\MonitoringRequest;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringRequestCharacterizationTest extends TestCase
{
    private const string ROUTE = '/__tests/monitoring-request';

    private const string INTERNAL_UI_ROUTE = '/__tests/internal-ui-monitoring-request';

    protected function setUp(): void
    {
        parent::setUp();

        Route::match(['post', 'patch'], self::ROUTE, fn (MonitoringRequest $monitoringRequest) => response()->json($monitoringRequest->validated()));
        Route::match(['post', 'patch'], self::INTERNAL_UI_ROUTE, fn (InternalUiMonitoringRequest $internalUiMonitoringRequest) => response()->json($internalUiMonitoringRequest->validated()));

        foreach (['char-a', 'char-b'] as $code) {
            ServerInstance::query()->create([
                'code' => $code,
                'ip_address' => '192.0.2.10',
                'api_key_hash' => 'characterization-token-' . $code,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, int, array<string, mixed>}>
     */
    public static function cases(): array
    {
        return [
            'http valid with normalization' => ['POST', 'api', self::http([
                'type' => 'HTTP',
                'http_headers' => ' {"X-Trace": "1", "Accept": "application/json"} ',
                'expected_http_statuses' => '200-299,301',
                'http_body' => 'ping',
                'auth_username' => 'user',
                'auth_password' => 'secret',
                'check_interval_minutes' => 5,
                'response_time_threshold_ms' => '1500',
                'response_time_confirmation_threshold' => '3',
                'response_time_degradation_percent' => '50',
                'preferred_locations' => ['char-a', 'char-b', 'char-a', ''],
                'public_label_enabled' => '1',
                'notification_on_failure' => 'true',
                'notification_channels' => ['slack', 'slack', '', 5],
                'team_id' => ' 01JCHARACTERIZATIONTEAMADM1 ',
                'group_ids' => '01JCHARACTERIZATIONGROUP001',
                'heartbeat_grace_minutes' => 3,
            ]), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 3,
                'check_interval_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'expected_http_statuses' => '200-299,301',
                'http_headers' => [
                    'X-Trace' => '1',
                    'Accept' => 'application/json',
                ],
                'http_body' => 'ping',
                'auth_username' => 'user',
                'auth_password' => 'secret',
                'preferred_location' => 'char-a',
                'public_label_enabled' => true,
                'notification_on_failure' => true,
                'team_id' => '01JCHARACTERIZATIONTEAMADM1',
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => 1500,
                'response_time_confirmation_threshold' => 3,
                'response_time_degradation_percent' => 50,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                    'char-b',
                ],
                'notification_channels' => [
                    'slack',
                ],
                'group_ids' => [
                    '01JCHARACTERIZATIONGROUP001',
                ],
            ]],
            'http minimal defaults' => ['POST', 'api', self::http(), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'http legacy preferred location and header alias' => ['POST', 'api', self::http([
                'preferred_locations' => null,
                'preferred_location' => 'char-b',
                'http_header' => '',
            ]), 422, [
                'preferred_location' => [
                    'The preferred location field is required.',
                ],
                'preferred_locations' => [
                    'The preferred locations field is required.',
                ],
            ]],
            'http invalid fields' => ['POST', 'api', self::http([
                'target' => 'not-a-url',
                'timeout' => 0,
                'http_method' => 'FETCH',
                'expected_http_statuses' => 'abc',
                'http_headers' => '{broken',
                'http_body' => str_repeat('a', 2049),
                'auth_username' => str_repeat('u', 256),
                'check_interval_minutes' => 2,
                'response_time_confirmation_threshold' => 2,
                'failure_confirmation_threshold' => 11,
                'ssl_expiry_warning_days' => 0,
            ]), 422, [
                'check_interval_minutes' => [
                    'Choose a check interval of 1, 5, 10, 15, 30, or 60 minutes.',
                ],
                'timeout' => [
                    'The timeout must be a number between 1 and 60 seconds.',
                ],
                'http_method' => [
                    'The HTTP method must be a valid HTTP method.',
                ],
                'expected_http_statuses' => [
                    'Expected HTTP status codes must use values or ascending ranges between 100 and 599, for example 200-299, 204.',
                ],
                'http_headers' => [
                    'Headers must be valid JSON.',
                ],
                'http_body' => [
                    'The http body field must not be greater than 2048 characters.',
                ],
                'auth_username' => [
                    'The auth username field must not be greater than 255 characters.',
                ],
                'failure_confirmation_threshold' => [
                    'The failure confirmation threshold field must not be greater than 10.',
                ],
                'response_time_threshold_ms' => [
                    'The response time threshold ms field is required.',
                ],
                'ssl_expiry_warning_days' => [
                    'The ssl expiry warning days field must be at least 1.',
                ],
                'target' => [
                    'The target must be a valid URL for type http.',
                ],
            ]],
            'http scalar json headers' => ['POST', 'api', self::http(['http_headers' => '"text"']), 422, [
                'http_headers' => [
                    'Headers must be valid JSON.',
                ],
            ]],
            'http missing timeout value' => ['POST', 'api', self::http(['timeout' => null, 'http_method' => null]), 422, [
                'timeout' => [
                    'The timeout field is required for HTTP or Keyword monitoring.',
                ],
            ]],
            'http timeout too high' => ['POST', 'api', self::http(['timeout' => 61]), 422, [
                'timeout' => [
                    'The timeout must be a number between 1 and 60 seconds.',
                ],
            ]],
            'http response time threshold without confirmation' => ['POST', 'api', self::http(['response_time_threshold_ms' => 1000]), 422, [
                'response_time_confirmation_threshold' => [
                    'The response time confirmation threshold field is required.',
                ],
            ]],
            'http degradation without threshold' => ['POST', 'api', self::http(['response_time_confirmation_threshold' => 2, 'response_time_degradation_percent' => 20]), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => 2,
                'response_time_degradation_percent' => 20,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'missing required fields' => ['POST', 'api', [], 422, [
                'name' => [
                    'The name field is required.',
                ],
                'type' => [
                    'The type field is required.',
                ],
                'status' => [
                    'The status field is required.',
                ],
                'preferred_location' => [
                    'The preferred location field is required.',
                ],
                'preferred_locations' => [
                    'The preferred locations field is required.',
                ],
                'target' => [
                    'The target field is required.',
                ],
            ]],
            'invalid type and status' => ['POST', 'api', self::http(['type' => 'smtp', 'status' => 'sleeping']), 422, [
                'type' => [
                    'The selected type is invalid.',
                ],
                'status' => [
                    'The selected status is invalid.',
                ],
                'timeout' => [
                    'Timeout configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'http_method' => [
                    'HTTP method configuration is only valid for HTTP or Keyword monitoring.',
                ],
            ]],
            'ping with http only fields' => ['POST', 'api', self::http([
                'type' => 'ping',
                'target' => 'example.test',
                'expected_http_statuses' => '200',
                'http_headers' => ['A' => 'b'],
                'http_body' => 'x',
                'auth_username' => 'u',
                'auth_password' => 'p',
                'check_interval_minutes' => 5,
                'response_time_threshold_ms' => 100,
                'response_time_confirmation_threshold' => 1,
                'response_time_degradation_percent' => 10,
                'dns_record_type' => 'A',
                'dns_expected_values' => ['1.1.1.1'],
                'server_health_cpu_threshold_percent' => 50,
                'server_health_report_interval_minutes' => 1,
            ]), 422, [
                'dns_record_type' => [
                    'DNS record type is only valid for DNS Record monitoring.',
                ],
                'dns_expected_values' => [
                    'Expected DNS values are only valid for DNS Record monitoring.',
                ],
                'check_interval_minutes' => [
                    'Check interval selection is only valid for HTTP or Keyword monitoring.',
                ],
                'server_health_cpu_threshold_percent' => [
                    'Server health thresholds are only valid for Server Health monitoring.',
                ],
                'server_health_report_interval_minutes' => [
                    'Server health thresholds are only valid for Server Health monitoring.',
                ],
                'timeout' => [
                    'Timeout configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'http_method' => [
                    'HTTP method configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'expected_http_statuses' => [
                    'Expected HTTP status codes are only valid for HTTP or Keyword monitoring.',
                ],
                'http_headers' => [
                    'Headers are only valid for HTTP or Keyword monitoring.',
                ],
                'http_body' => [
                    'Body content is only valid for HTTP or Keyword monitoring.',
                ],
                'auth_username' => [
                    'Username for basic auth is only valid for HTTP or Keyword monitoring.',
                ],
                'auth_password' => [
                    'Password for basic auth is only valid for HTTP or Keyword monitoring.',
                ],
                'response_time_threshold_ms' => [
                    'Response time alert configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'response_time_confirmation_threshold' => [
                    'Response time alert configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'response_time_degradation_percent' => [
                    'Response time alert configuration is only valid for HTTP or Keyword monitoring.',
                ],
                'target' => [
                    'The target must be a valid IP address for type ping.',
                ],
            ]],
            'ping valid' => ['POST', 'api', ['name' => 'Ping', 'type' => 'ping', 'target' => '192.0.2.1', 'status' => 'paused', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Ping',
                'type' => 'ping',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'paused',
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => '192.0.2.1',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'port missing port and invalid target' => ['POST', 'api', ['name' => 'Port', 'type' => 'port', 'target' => 'nope', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'port' => [
                    'The port field is required when type is port.',
                ],
                'target' => [
                    'The target must be a valid IP address or URL for type port.',
                ],
            ]],
            'port invalid range' => ['POST', 'api', ['name' => 'Port', 'type' => 'port', 'target' => '192.0.2.1', 'port' => 70000, 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'port' => [
                    'The port field must not be greater than 65535.',
                ],
            ]],
            'port valid url target' => ['POST', 'api', ['name' => 'Port', 'type' => 'port', 'target' => 'https://example.test', 'port' => '443', 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Port',
                'type' => 'port',
                'port' => '443',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'keyword missing keyword' => ['POST', 'api', self::http(['type' => 'keyword']), 422, [
                'keyword' => [
                    'The keyword field is required when type is keyword.',
                ],
            ]],
            'keyword valid' => ['POST', 'api', self::http(['type' => 'keyword', 'keyword' => 'healthy']), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'keyword',
                'keyword' => 'healthy',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'dns record missing config' => ['POST', 'api', ['name' => 'DNS', 'type' => 'dns_record', 'target' => 'example.test', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'dns_record_type' => [
                    'The dns record type field is required.',
                ],
                'dns_expected_values' => [
                    'The dns expected values field is required.',
                ],
            ]],
            'dns record invalid values' => ['POST', 'api', ['name' => 'DNS', 'type' => 'dns_record', 'target' => 'https://example.test', 'dns_record_type' => 'a', 'dns_expected_values' => ['not-an-ip'], 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'dns_expected_values' => [
                    'Expected DNS values must contain at least one valid value for the selected record type.',
                ],
                'target' => [
                    'The target must be a valid domain name for type dns_record.',
                ],
            ]],
            'dns record unknown record type' => ['POST', 'api', ['name' => 'DNS', 'type' => 'dns_record', 'target' => 'example.test', 'dns_record_type' => 'ZZZ', 'dns_expected_values' => ['x'], 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'dns_record_type' => [
                    'The selected dns record type is invalid.',
                ],
            ]],
            'dns record too many values' => ['POST', 'api', ['name' => 'DNS', 'type' => 'dns_record', 'target' => 'example.test', 'dns_record_type' => 'A', 'dns_expected_values' => array_map(static fn (int $i): string => '192.0.2.' . $i, range(1, 51)), 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'dns_expected_values' => [
                    'Expected DNS values may contain at most 50 values.',
                ],
            ]],
            'dns record valid normalized' => ['POST', 'api', ['name' => 'DNS', 'type' => 'dns_record', 'target' => 'Example.test', 'dns_record_type' => ' a ', 'dns_expected_values' => "192.0.2.2\n192.0.2.1", 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'DNS',
                'type' => 'dns_record',
                'dns_record_type' => 'A',
                'dns_expected_values' => [
                    '192.0.2.1',
                    '192.0.2.2',
                ],
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'Example.test',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'domain expiration ip target' => ['POST', 'api', ['name' => 'Domain', 'type' => 'domain_expiration', 'target' => '192.0.2.1', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'target' => [
                    'The target must be a valid domain name for type domain_expiration.',
                ],
            ]],
            'domain expiration single label' => ['POST', 'api', ['name' => 'Domain', 'type' => 'domain_expiration', 'target' => 'localhost', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'target' => [
                    'The target must be a valid domain name for type domain_expiration.',
                ],
            ]],
            'domain expiration valid' => ['POST', 'api', ['name' => 'Domain', 'type' => 'domain_expiration', 'target' => 'example.test', 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Domain',
                'type' => 'domain_expiration',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'example.test',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'heartbeat missing interval' => ['POST', 'api', ['name' => 'Beat', 'type' => 'heartbeat', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'heartbeat_interval_minutes' => [
                    'The heartbeat interval minutes field is required when type is heartbeat.',
                ],
            ]],
            'heartbeat valid' => ['POST', 'api', ['name' => 'Beat', 'type' => 'heartbeat', 'heartbeat_interval_minutes' => 10, 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Beat',
                'type' => 'heartbeat',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_interval_minutes' => 10,
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'heartbeat invalid ranges' => ['POST', 'api', ['name' => 'Beat', 'type' => 'heartbeat', 'heartbeat_interval_minutes' => 0, 'heartbeat_grace_minutes' => 2000, 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'heartbeat_interval_minutes' => [
                    'The heartbeat interval minutes field must be at least 1.',
                ],
                'heartbeat_grace_minutes' => [
                    'The heartbeat grace minutes field must not be greater than 1440.',
                ],
            ]],
            'server health defaults' => ['POST', 'api', ['name' => 'Server', 'type' => 'server_health', 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Server',
                'type' => 'server_health',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'server_health_cpu_threshold_percent' => 90,
                'server_health_ram_threshold_percent' => 90,
                'server_health_storage_threshold_percent' => 90,
                'server_health_load_threshold_per_cpu' => null,
                'server_health_service_response_time_threshold_ms' => null,
                'server_health_report_interval_minutes' => 1,
                'server_health_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'server health custom values' => ['POST', 'api', ['name' => 'Server', 'type' => 'server_health', 'status' => 'active', 'preferred_locations' => ['char-a'], 'server_health_cpu_threshold_percent' => '75.5', 'server_health_load_threshold_per_cpu' => '1.5', 'server_health_service_response_time_threshold_ms' => '800', 'server_health_report_interval_minutes' => 5, 'server_health_grace_minutes' => 0], 200, [
                'name' => 'Server',
                'type' => 'server_health',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'server_health_cpu_threshold_percent' => '75.5',
                'server_health_ram_threshold_percent' => 90,
                'server_health_storage_threshold_percent' => 90,
                'server_health_load_threshold_per_cpu' => 1.5,
                'server_health_service_response_time_threshold_ms' => 800,
                'server_health_report_interval_minutes' => 5,
                'server_health_grace_minutes' => 0,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'server health invalid values' => ['POST', 'api', ['name' => 'Server', 'type' => 'server_health', 'status' => 'active', 'preferred_locations' => ['char-a'], 'server_health_cpu_threshold_percent' => 0, 'server_health_ram_threshold_percent' => '', 'server_health_storage_threshold_percent' => 'abc', 'server_health_load_threshold_per_cpu' => 'x', 'server_health_service_response_time_threshold_ms' => '1.5', 'server_health_report_interval_minutes' => 0, 'server_health_grace_minutes' => 1441], 422, [
                'server_health_cpu_threshold_percent' => [
                    'Server health thresholds must be numbers between 1 and 100 percent.',
                ],
                'server_health_storage_threshold_percent' => [
                    'Server health thresholds must be numbers between 1 and 100 percent.',
                ],
                'server_health_report_interval_minutes' => [
                    'Server health thresholds must be numbers between 1 and 100 percent.',
                ],
                'server_health_grace_minutes' => [
                    'Server health thresholds must be numbers between 1 and 100 percent.',
                ],
            ]],
            'ownership and channel rules' => ['POST', 'api', self::http([
                'team_id' => '01JCHARACTERIZATIONTEAMMEM1',
                'group_ids' => ['missing-group'],
                'notification_channels' => ['discord', 'email'],
                'preferred_locations' => ['char-a', 'unknown'],
            ]), 422, [
                'team_id' => [
                    'You must be a team admin for this action.',
                ],
                'preferred_locations.1' => [
                    'The selected preferred_locations.1 is invalid.',
                ],
                'notification_channels.0' => [
                    'The selected notification_channels.0 is invalid.',
                ],
                'notification_channels.1' => [
                    'The selected notification_channels.1 is invalid.',
                ],
                'group_ids.0' => [
                    'The selected group_ids.0 is invalid.',
                ],
            ]],
            'unknown team id' => ['POST', 'api', self::http(['team_id' => 'does-not-exist']), 422, [
                'team_id' => [
                    'The selected team id is invalid.',
                    'You must be a team admin for this action.',
                ],
            ]],
            'non array locations and channels' => ['POST', 'api', self::http(['preferred_locations' => 'char-a', 'notification_channels' => 'slack']), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'patch without target' => ['PATCH', 'api', ['name' => 'Patched', 'type' => 'http', 'status' => 'active', 'timeout' => 5, 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Patched',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'ssl_expiry_warning_days' => 7,
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'patch with invalid target' => ['PATCH', 'api', ['name' => 'Patched', 'type' => 'ping', 'target' => 'example', 'status' => 'active', 'preferred_locations' => ['char-a']], 422, [
                'target' => [
                    'The target must be a valid IP address for type ping.',
                ],
            ]],
            'patch keeps degradation untouched' => ['PATCH', 'api', self::http(['response_time_threshold_ms' => 100, 'response_time_confirmation_threshold' => 2]), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => 100,
                'response_time_confirmation_threshold' => 2,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'internal ui default headers' => ['POST', 'internal', self::http(), 200, [
                'name' => 'Characterized HTTP',
                'type' => 'http',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'timeout' => 10,
                'http_method' => 'get',
                'http_headers' => [],
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => 'https://example.test/health',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
            'internal ui keeps headers for ping' => ['POST', 'internal', ['name' => 'Ping', 'type' => 'ping', 'target' => '192.0.2.1', 'status' => 'active', 'preferred_locations' => ['char-a']], 200, [
                'name' => 'Ping',
                'type' => 'ping',
                'dns_record_type' => null,
                'dns_expected_values' => null,
                'status' => 'active',
                'heartbeat_grace_minutes' => 5,
                'http_headers' => null,
                'preferred_location' => 'char-a',
                'public_label_enabled' => false,
                'notification_on_failure' => false,
                'notification_channels' => [],
                'team_id' => null,
                'failure_confirmation_threshold' => 2,
                'response_time_threshold_ms' => null,
                'response_time_confirmation_threshold' => null,
                'response_time_degradation_percent' => null,
                'ssl_expiry_warning_days' => 7,
                'target' => '192.0.2.1',
                'preferred_locations' => [
                    'char-a',
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('cases')]
    public function test_monitoring_request_validation_behavior_is_stable(string $method, string $route, array $payload, int $status, array $expected): void
    {
        $package = Package::factory()->create();
        $user = User::factory()->create([
            'package_id' => $package->id,
            'id' => '01JCHARACTERIZATIONUSER0001',
            'notification_channels' => [
                'slack' => ['enabled' => true, 'webhook_url' => 'https://hooks.example.test/a'],
                'discord' => ['enabled' => false],
            ],
        ]);
        $adminTeam = Team::factory()->create(['id' => '01JCHARACTERIZATIONTEAMADM1']);
        TeamMembership::query()->create(['team_id' => $adminTeam->id, 'user_id' => $user->id, 'role' => TeamRole::ADMIN]);
        $memberTeam = Team::factory()->create(['id' => '01JCHARACTERIZATIONTEAMMEM1']);
        TeamMembership::query()->create(['team_id' => $memberTeam->id, 'user_id' => $user->id, 'role' => TeamRole::MEMBER]);
        MonitoringGroup::factory()->for($user)->create(['id' => '01JCHARACTERIZATIONGROUP001']);

        $this->actingAs($user);

        $testResponse = $this->json($method, $route === 'internal' ? self::INTERNAL_UI_ROUTE : self::ROUTE, $payload);

        $testResponse->assertStatus($status);

        $actual = $status === 422 ? $testResponse->json('errors') : $testResponse->json();

        $this->assertSame($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function http(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Characterized HTTP',
            'type' => 'http',
            'target' => 'https://example.test/health',
            'status' => 'active',
            'timeout' => 10,
            'http_method' => 'get',
            'preferred_locations' => ['char-a'],
        ], $overrides);
    }
}
