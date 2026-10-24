<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Services\MonitoringCheckIntervalService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitoring>
 */
class MonitoringFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([
            MonitoringType::HTTP,
            MonitoringType::PING,
            MonitoringType::KEYWORD,
            MonitoringType::PORT,
            MonitoringType::DOMAIN_EXPIRATION,
            MonitoringType::DNS_RECORD,
        ]);

        $data = [
            'name' => fake()->name(),
            'type' => $type,
            'target' => match ($type) {
                MonitoringType::HTTP, MonitoringType::KEYWORD => fake()->url(),
                MonitoringType::PING => fake()->ipv4(),
                MonitoringType::PORT => fake()->ipv4(), // Or fake()->domainName() if ports can be checked on domain names
                MonitoringType::DOMAIN_EXPIRATION => fake()->domainName(),
                MonitoringType::DNS_RECORD => fake()->domainName(),
            },
            'preferred_location' => 'de-1',
            'status' => MonitoringLifecycleStatus::ACTIVE,
            'check_interval_minutes' => in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) ? MonitoringCheckIntervalService::DEFAULT_WEBSITE_INTERVAL_MINUTES : null,
            'expected_http_statuses' => in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) ? '200-299' : null,
        ];

        if ($type === MonitoringType::PORT) {
            $data['port'] = fake()->numberBetween(1, 65535);
        }

        if ($type === MonitoringType::KEYWORD) {
            $data['keyword'] = fake()->word();
        }

        if ($type === MonitoringType::DNS_RECORD) {
            $data['dns_record_type'] = 'A';
            $data['dns_expected_values'] = [fake()->ipv4()];
        }

        return $data;
    }

    public function heartbeat(): static
    {
        return $this->state(function (): array {
            return [
                'type' => MonitoringType::HEARTBEAT,
                'target' => 'https://webguard.test/heartbeat/example-token',
                'heartbeat_token' => 'example-token',
                'heartbeat_interval_minutes' => 60,
                'heartbeat_grace_minutes' => 10,
                'heartbeat_last_ping_at' => null,
            ];
        });
    }

    public function serverHealth(): static
    {
        return $this->state(function (): array {
            return [
                'type' => MonitoringType::SERVER_HEALTH,
                'target' => 'https://webguard.test/api/server-health/example-token',
                'server_health_token' => 'example-token',
                'server_health_last_reported_at' => null,
                'server_health_cpu_threshold_percent' => 90.0,
                'server_health_ram_threshold_percent' => 90.0,
                'server_health_storage_threshold_percent' => 90.0,
                'server_health_load_threshold_per_cpu' => null,
                'server_health_service_response_time_threshold_ms' => null,
                'server_health_report_interval_minutes' => 1,
                'server_health_grace_minutes' => 5,
                'timeout' => 5,
                'expected_http_statuses' => null,
                'http_method' => null,
                'http_headers' => null,
                'http_body' => null,
                'auth_username' => null,
                'auth_password' => null,
                'port' => null,
                'keyword' => null,
            ];
        });
    }

    public function domainExpiration(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitoringType::DOMAIN_EXPIRATION,
            'target' => 'example.com',
            'timeout' => 5,
            'expected_http_statuses' => null,
            'http_method' => null,
            'http_headers' => null,
            'http_body' => null,
            'auth_username' => null,
            'auth_password' => null,
            'port' => null,
            'keyword' => null,
        ]);
    }

    public function dnsRecord(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitoringType::DNS_RECORD,
            'target' => 'example.com',
            'dns_record_type' => 'A',
            'dns_expected_values' => ['192.0.2.10'],
            'timeout' => 5,
            'expected_http_statuses' => null,
            'http_method' => null,
            'http_headers' => null,
            'http_body' => null,
            'auth_username' => null,
            'auth_password' => null,
            'port' => null,
            'keyword' => null,
        ]);
    }
}
