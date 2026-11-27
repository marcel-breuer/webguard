<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\IncidentContributingCategory;
use App\Enums\IncidentCustomerImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\RegionalConsensusStatus;
use App\Enums\TeamRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoScreenshotSeeder extends Seeder
{
    private const DEMO_EMAIL = 'demo.operator@example.test';

    private const LOCATIONS = [
        'de-1' => ['Germany', 'DE', 'Europe'],
        'demo-nl-1' => ['Amsterdam', 'NL', 'North Holland'],
        'demo-uk-1' => ['London', 'GB', 'England'],
        'demo-us-1' => ['Ashburn', 'US', 'Virginia'],
    ];

    /** @var array<string, string> */
    private array $ids = [];

    /** @var array<string, string> */
    private array $monitoringIds = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $userId = $this->id('user');
            $teamId = $this->id('team');

            $this->reset($userId, $teamId);
            $this->seedLocations();

            $now = now();
            $packageId = $this->seedDemoPackage($now);
            DB::table('users')->insert([
                'id' => $userId,
                'name' => 'Demo Operator',
                'email' => self::DEMO_EMAIL,
                'email_verified_at' => $now,
                'password' => Hash::make('password'),
                'role' => UserRole::DEMO->value,
                'package_id' => $packageId,
                'locale' => 'en',
                'format_locale' => 'en',
                'theme' => 'light',
                'notification_channels' => json_encode($this->notificationChannels(), JSON_THROW_ON_ERROR),
                'monitoring_digest_enabled' => true,
                'monitoring_digest_frequency' => 'weekly',
                'unread_notifications_reminder_enabled' => true,
                'unread_notifications_reminder_frequency' => 'weekly',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('teams')->insert([
                'id' => $teamId,
                'name' => 'Operations Team',
                'description' => 'An anonymized workspace for exploring WebGuard monitoring.',
                'created_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('team_memberships')->insert([
                'id' => $this->id('membership'),
                'team_id' => $teamId,
                'user_id' => $userId,
                'role' => TeamRole::ADMIN->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->seedMonitorings($userId, $now);
            $this->seedMonitoringGroups($userId, $now);
            $this->seedHistory($now);
            $this->seedDailyResults($now);
            $this->seedIncidents($userId, $now);
            $this->seedMaintenance($now);
            $this->seedNotifications($userId, $now);
            $this->seedStatusPages($userId, $now);
            $this->seedApiReferenceToken($userId, $now);
        });
    }

    private function reset(string $userId, string $teamId): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();

        DB::table('monitorings')
            ->where('team_id', $teamId)
            ->orWhere('created_by_user_id', $userId)
            ->delete();
        DB::table('teams')->where('id', $teamId)->delete();
        DB::table('users')->where('id', $userId)->delete();

    }

    private function seedDemoPackage(Carbon $now): string
    {
        $packageId = $this->id('package');
        $existing = DB::table('packages')->where('id', $packageId)->first();

        if ($existing !== null) {
            return $packageId;
        }

        DB::table('packages')->insert([
            'id' => $packageId,
            'monitoring_limit' => 100,
            'price' => 0,
            'is_selectable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $packageId;
    }

    private function seedLocations(): void
    {
        foreach (self::LOCATIONS as $code => [$displayName, $country, $region]) {
            if ($code === 'de-1') {
                continue;
            }

            $id = $this->id('location:' . $code);
            $existing = DB::table('server_instances')->where('code', $code)->first();

            if ($existing !== null && $existing->id !== $id) {
                throw new RuntimeException('Screenshot demo location code is already in use: ' . $code);
            }

            $values = [
                'display_name' => $displayName,
                'country_code' => $country,
                'region' => $region,
                'ip_address' => null,
                'api_key_hash' => Hash::make('disabled-demo-instance-key-' . $code),
                'is_active' => true,
                'last_seen_at' => now()->subMinutes(2),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('server_instances')->insert(array_merge($values, [
                    'id' => $id,
                    'code' => $code,
                    'created_at' => now(),
                ]));
            } else {
                DB::table('server_instances')->where('id', $id)->update($values);
            }
        }
    }

    private function seedMonitorings(string $userId, Carbon $now): void
    {
        $monitorings = [
            'campaign-hub' => ['Campaign Hub', MonitoringType::HTTP, 'https://campaign.example.test/health', ['expected_http_statuses' => '200-399']],
            'payment-api' => ['Payment API', MonitoringType::HTTP, 'https://payments.example.test/health', ['expected_http_statuses' => '200-299', 'response_time_threshold_ms' => 800]],
            'billing-worker' => ['Billing Worker', MonitoringType::HEARTBEAT, 'billing-worker.example.test', [
                'heartbeat_interval_minutes' => 5,
                'heartbeat_grace_minutes' => 10,
                'heartbeat_last_ping_at' => $now->copy()->subMinutes(2),
                'heartbeat_token' => 'demo-heartbeat-token-disabled',
                'maintenance_from' => $now->copy()->subMinutes(30),
                'maintenance_until' => $now->copy()->addMinutes(60),
            ]],
            'identity-gateway' => ['Identity Gateway', MonitoringType::KEYWORD, 'https://identity.example.test/ready', ['keyword' => 'ready', 'expected_http_statuses' => '200-399']],
            'asset-pipeline' => ['Asset Pipeline', MonitoringType::PING, 'assets.example.test', []],
            'message-queue' => ['Message Queue', MonitoringType::PORT, 'queue.example.test', ['port' => 5672]],
            'edge-dns' => ['Edge DNS', MonitoringType::DNS_RECORD, 'dns.example.test', ['dns_record_type' => 'A', 'dns_expected_values' => json_encode(['192.0.2.20', '192.0.2.21'], JSON_THROW_ON_ERROR)]],
            'domain-certificate' => ['Domain & Certificate', MonitoringType::DOMAIN_EXPIRATION, 'portal.example.test', []],
            'compute-cluster' => ['Compute Cluster', MonitoringType::SERVER_HEALTH, 'compute.example.test', [
                'server_health_token' => 'demo-server-health-token-disabled',
                'server_health_last_reported_at' => $now->copy()->subMinutes(2),
                'server_health_cpu_threshold_percent' => 85,
                'server_health_ram_threshold_percent' => 90,
                'server_health_storage_threshold_percent' => 92,
                'server_health_load_threshold_per_cpu' => 2.5,
                'server_health_service_response_time_threshold_ms' => 1200,
                'server_health_report_interval_minutes' => 5,
                'server_health_grace_minutes' => 10,
            ]],
        ];

        foreach ($monitorings as $key => [$name, $type, $target, $typeAttributes]) {
            $this->monitoringIds[$key] = $this->id('monitoring:' . $key);
            $attributes = array_merge([
                'id' => $this->monitoringIds[$key],
                'user_id' => $userId,
                'team_id' => null,
                'created_by_user_id' => $userId,
                'name' => $name,
                'type' => $type->value,
                'target' => $target,
                'status' => 'active',
                'timeout' => 5,
                'check_interval_minutes' => 5,
                'http_method' => in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) ? 'GET' : null,
                'expected_http_statuses' => in_array($type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true) ? '200-399' : null,
                'public_label_enabled' => true,
                'preferred_location' => 'de-1',
                'preferred_locations' => json_encode(array_keys(self::LOCATIONS), JSON_THROW_ON_ERROR),
                'notification_on_failure' => true,
                'notification_channels' => json_encode(['email', 'slack', 'webhook', 'mobile_push'], JSON_THROW_ON_ERROR),
                'failure_confirmation_threshold' => 2,
                'ssl_expiry_warning_days' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ], $typeAttributes);

            DB::table('monitorings')->insert($attributes);
        }

        $this->seedDomainAndCertificateResults($now);
    }

    private function seedDomainAndCertificateResults(Carbon $now): void
    {
        $domainMonitoring = $this->monitoringIds['domain-certificate'];
        DB::table('monitoring_domain_results')->insert([
            'id' => $this->id('domain-result'),
            'monitoring_id' => $domainMonitoring,
            'expires_at' => $now->copy()->addDays(142),
            'is_valid' => true,
            'registrar' => 'Example Registrar',
            'checked_at' => $now->copy()->subMinutes(2),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['campaign-hub', 'payment-api', 'identity-gateway', 'asset-pipeline'] as $key) {
            DB::table('monitoring_ssl_results')->insert([
                'id' => $this->id('ssl-result:' . $key),
                'monitoring_id' => $this->monitoringIds[$key],
                'expires_at' => $now->copy()->addDays($key === 'payment-api' ? 24 : 96),
                'is_valid' => true,
                'issuer' => 'Example Trust Services',
                'issued_at' => $now->copy()->subDays(270),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedMonitoringGroups(string $userId, Carbon $now): void
    {
        $groups = [
            'customer-facing' => ['Customer-facing services', 'Public web applications and edge availability.'],
            'payments' => ['Payments & billing', 'Payment processing and background billing.'],
            'platform' => ['Platform infrastructure', 'Identity, compute, networking, and message delivery.'],
        ];

        foreach ($groups as $key => [$name, $description]) {
            $groupId = $this->id('group:' . $key);
            DB::table('monitoring_groups')->insert([
                'id' => $groupId,
                'user_id' => $userId,
                'name' => $name,
                'description' => $description,
                'sla_report_enabled' => true,
                'sla_target_percentage' => 99.9,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($this->groupMonitoringKeys($key) as $position => $monitoringKey) {
                DB::table('monitoring_group_monitoring')->insert([
                    'monitoring_group_id' => $groupId,
                    'monitoring_id' => $this->monitoringIds[$monitoringKey],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @return list<string> */
    private function groupMonitoringKeys(string $group): array
    {
        return match ($group) {
            'customer-facing' => ['campaign-hub', 'identity-gateway', 'asset-pipeline'],
            'payments' => ['payment-api', 'billing-worker'],
            default => ['compute-cluster', 'edge-dns', 'message-queue', 'domain-certificate'],
        };
    }

    private function seedHistory(Carbon $now): void
    {
        $rows = [];
        $locationCodes = array_keys(self::LOCATIONS);

        foreach ($this->monitoringIds as $monitoringKey => $monitoringId) {
            for ($sample = 0; $sample < 365; $sample++) {
                $checkedAt = $now->copy()->subHours(6 * (365 - $sample));

                foreach ($locationCodes as $locationCode) {
                    $status = $this->sampleStatus($monitoringKey, $locationCode, $checkedAt, $now);
                    $responseTime = $this->responseTime($monitoringKey, $locationCode, $checkedAt);
                    $rows[] = $this->historyRow($monitoringKey, $monitoringId, $locationCode, $checkedAt, $status, $responseTime);
                }
            }

            foreach ($locationCodes as $locationCode) {
                $rows[] = $this->historyRow(
                    $monitoringKey,
                    $monitoringId,
                    $locationCode,
                    $now->copy(),
                    MonitoringStatus::UP,
                    $this->responseTime($monitoringKey, $locationCode, $now),
                );
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('monitoring_response_results')->insert($chunk);
        }
    }

    private function seedDailyResults(Carbon $now): void
    {
        $rows = [];
        $monitoringIndex = 0;

        foreach ($this->monitoringIds as $key => $monitoringId) {
            for ($dayIndex = 0; $dayIndex < 91; $dayIndex++) {
                $date = $now->copy()->subDays(91 - $dayIndex)->startOfDay();
                $downtimeMinutes = match (true) {
                    $key === 'payment-api' && $date->isSameDay($now->copy()->subDays(12)) => 31,
                    $key === 'campaign-hub' && $date->isSameDay($now->copy()->subDays(27)) => 37,
                    default => 0,
                };
                $uptimeMinutes = 1440 - $downtimeMinutes;
                $averageResponseTime = $this->responseTime($key, 'de-1', $date->copy()->setTime(12, 0));
                $rows[] = [
                    'id' => 900000000 + ($monitoringIndex * 1000) + $dayIndex,
                    'monitoring_id' => $monitoringId,
                    'date' => $date->toDateString(),
                    'uptime_total' => $uptimeMinutes,
                    'downtime_total' => $downtimeMinutes,
                    'unknown_total' => 0,
                    'uptime_percentage' => round(($uptimeMinutes / 1440) * 100, 4),
                    'downtime_percentage' => round(($downtimeMinutes / 1440) * 100, 4),
                    'unknown_percentage' => 0,
                    'uptime_minutes' => $uptimeMinutes,
                    'downtime_minutes' => $downtimeMinutes,
                    'unknown_minutes' => 0,
                    'avg_response_time' => $averageResponseTime,
                    'min_response_time' => (int) round($averageResponseTime * 0.72),
                    'max_response_time' => (int) round($averageResponseTime * 1.31),
                    'incidents_count' => $downtimeMinutes > 0 ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $monitoringIndex++;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('monitoring_daily_results')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function historyRow(string $key, string $monitoringId, string $location, Carbon $checkedAt, MonitoringStatus $status, float $responseTime): array
    {
        $serverHealthMetrics = $key === 'compute-cluster' ? json_encode([
            'cpu_usage_percent' => 34 + (($checkedAt->hour * 7) % 31),
            'ram_usage_percent' => 61 + (($checkedAt->dayOfYear * 3) % 17),
            'storage_usage_percent' => 57 + (($checkedAt->dayOfYear * 2) % 14),
            'swap_usage_percent' => 8 + (($checkedAt->hour * 3) % 14),
            'load_average_1m' => 0.42 + (($checkedAt->hour % 7) / 10),
            'uptime_seconds' => 864000 + ($checkedAt->dayOfYear * 86400),
            'service_response_time_ms' => (int) round($responseTime),
        ], JSON_THROW_ON_ERROR) : null;

        return [
            'id' => $this->timeOrderedId('response:' . $key . ':' . $location . ':' . $checkedAt->format('YmdHi'), $checkedAt),
            'monitoring_id' => $monitoringId,
            'location_code' => $location,
            'status' => $status->value,
            'http_status_code' => in_array($key, ['campaign-hub', 'payment-api', 'identity-gateway'], true)
                ? ($status === MonitoringStatus::DOWN ? 503 : 200)
                : null,
            'response_time' => $responseTime,
            'check_interval_seconds' => 300,
            'server_health_metrics' => $serverHealthMetrics,
            'server_health_sampled_at' => $key === 'compute-cluster' ? $checkedAt : null,
            'vital_values' => $key === 'compute-cluster' ? json_encode(['cpu' => 'normal', 'memory' => 'normal', 'load' => 'normal'], JSON_THROW_ON_ERROR) : null,
            'created_at' => $checkedAt,
            'updated_at' => $checkedAt,
        ];
    }

    private function sampleStatus(string $key, string $location, Carbon $checkedAt, Carbon $now): MonitoringStatus
    {
        $localizedIncidentAt = $now->copy()->subDays(12);
        if ($key === 'payment-api' && $location === 'demo-nl-1'
            && $checkedAt->between($localizedIncidentAt, $localizedIncidentAt->copy()->addMinute())) {
            return MonitoringStatus::DOWN;
        }

        $regionalIncidentAt = $now->copy()->subDays(27);
        if ($key === 'campaign-hub' && in_array($location, ['de-1', 'demo-nl-1', 'demo-uk-1'], true)
            && $checkedAt->between($regionalIncidentAt, $regionalIncidentAt->copy()->addMinute())) {
            return MonitoringStatus::DOWN;
        }

        return MonitoringStatus::UP;
    }

    private function responseTime(string $key, string $location, Carbon $checkedAt): float
    {
        $locationOffset = (int) array_search($location, array_keys(self::LOCATIONS), true) * 9;
        $dailyCurve = abs(sin(($checkedAt->hour + $locationOffset) / 24 * M_PI));
        $slowPeriod = $checkedAt->dayOfYear % 19 >= 11 && $checkedAt->dayOfYear % 19 <= 13;
        $base = match ($key) {
            'campaign-hub' => 142,
            'payment-api' => 188,
            'identity-gateway' => 96,
            'asset-pipeline' => 44,
            'compute-cluster' => 72,
            'message-queue' => 21,
            'billing-worker' => 18,
            'edge-dns' => 24,
            default => 110,
        };

        return round($base + ($locationOffset * 3) + ($dailyCurve * 68) + ($slowPeriod ? 120 : 0), 2);
    }

    private function seedIncidents(string $userId, Carbon $now): void
    {
        $this->createIncident(
            'localized-payment-incident',
            $this->monitoringIds['payment-api'],
            $now->copy()->subDays(12)->subMinutes(30),
            $now->copy()->subDays(12)->addMinutes(1),
            RegionalConsensusStatus::LOCALIZED,
            ['demo-nl-1'],
            IncidentSeverity::MEDIUM,
            IncidentCustomerImpact::DEGRADED,
            'Regional transit congestion increased payment API timeouts for Amsterdam traffic.',
            'Traffic shifted to the backup edge route and payment checks recovered.',
            IncidentContributingCategory::INFRASTRUCTURE->value,
            $userId,
        );

        $this->createIncident(
            'regional-edge-incident',
            $this->monitoringIds['campaign-hub'],
            $now->copy()->subDays(27)->subMinutes(30),
            $now->copy()->subDays(27)->addMinutes(1),
            RegionalConsensusStatus::REGIONAL,
            ['de-1', 'demo-nl-1', 'demo-uk-1'],
            IncidentSeverity::HIGH,
            IncidentCustomerImpact::OUTAGE,
            'A regional edge configuration rollout returned elevated errors across three European locations.',
            'The rollout was reverted and edge health returned to baseline.',
            IncidentContributingCategory::CONFIGURATION->value,
            $userId,
        );
    }

    /** @param list<string> $locations */
    private function createIncident(string $key, string $monitoringId, Carbon $downAt, Carbon $upAt, RegionalConsensusStatus $consensus, array $locations, IncidentSeverity $severity, IncidentCustomerImpact $impact, string $problem, string $resolution, string $contributingCategory, string $userId): void
    {
        $incidentId = $this->id('incident:' . $key);
        $now = now();
        DB::table('incidents')->insert([
            'id' => $incidentId,
            'monitoring_id' => $monitoringId,
            'consensus_status' => $consensus->value,
            'affected_locations' => json_encode($locations, JSON_THROW_ON_ERROR),
            'consensus_snapshot' => json_encode(['status' => $consensus->value, 'affected_locations' => $locations, 'sampled_at' => $downAt->toIso8601String()], JSON_THROW_ON_ERROR),
            'diagnosis_snapshot' => json_encode(['category' => $contributingCategory, 'confidence' => 'high', 'summary' => $problem], JSON_THROW_ON_ERROR),
            'diagnosed_at' => $upAt,
            'problem_description' => $problem,
            'resolution_description' => $resolution,
            'incident_type' => IncidentType::AVAILABILITY->value,
            'severity' => $severity->value,
            'affected_service' => $key === 'localized-payment-incident' ? 'Payment API' : 'Campaign Hub',
            'customer_impact' => $impact->value,
            'contributing_category' => $contributingCategory,
            'down_at' => $downAt,
            'up_at' => $upAt,
            'created_at' => $downAt,
            'updated_at' => $now,
        ]);

        foreach ([[$downAt, 'Issue detected', $problem], [$upAt, 'Service recovered', $resolution]] as $position => [$occurredAt, $title, $description]) {
            DB::table('incident_timeline_events')->insert([
                'id' => $this->id('timeline:' . $key . ':' . $position),
                'incident_id' => $incidentId,
                'title' => $title,
                'description' => $description,
                'occurred_at' => $occurredAt,
                'source_type' => 'custom',
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        }

        DB::table('incident_follow_ups')->insert([
            'id' => $this->id('follow-up:' . $key),
            'incident_id' => $incidentId,
            'assigned_user_id' => $userId,
            'title' => $key === 'localized-payment-incident' ? 'Review alternate transit route capacity' : 'Add a regional canary before edge rollouts',
            'description' => 'Document the verification steps and capture the outcome in the next weekly review.',
            'status' => 'open',
            'due_at' => now()->addDays(7)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedMaintenance(Carbon $now): void
    {
        $groupId = $this->id('group:platform');
        foreach ([
            ['upcoming', $now->copy()->addDays(5)->setTime(21, 0), 90],
            ['past', $now->copy()->subDays(9)->setTime(20, 0), 65],
        ] as [$key, $startsAt, $duration]) {
            DB::table('maintenance_windows')->insert([
                'id' => $this->id('maintenance:' . $key),
                'monitoring_id' => null,
                'monitoring_group_id' => $groupId,
                'starts_at' => $startsAt,
                'duration_minutes' => $duration,
                'recurrence' => $key === 'upcoming' ? 'weekly' : 'monthly',
                'repeat_until' => null,
                'timezone' => 'Europe/Berlin',
                // Keep the past entry visible in history without matching the
                // scalar "has enabled maintenance windows" subquery for every
                // monitoring in this group alongside the upcoming window.
                'enabled' => $key === 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedNotifications(string $userId, Carbon $now): void
    {
        $items = [
            ['localized-notification', 'payment-api', NotificationType::STATUS_CHANGE, 'DEGRADED', now()->subDays(12)->addHours(10)],
            ['regional-notification', 'campaign-hub', NotificationType::STATUS_CHANGE, 'DOWN', now()->subDays(27)->addHours(14)],
            ['ssl-notification', 'payment-api', NotificationType::SSL_EXPIRY, 'SSL_EXPIRING', now()->subDays(2)],
            ['performance-notification', 'identity-gateway', NotificationType::PERFORMANCE, 'DEGRADED', now()->subDays(1)],
        ];

        foreach ($items as [$key, $monitoringKey, $type, $message, $createdAt]) {
            $notificationId = $this->id('notification:' . $key);
            DB::table('monitoring_notifications')->insert([
                'id' => $notificationId,
                'monitoring_id' => $this->monitoringIds[$monitoringKey],
                'type' => $type->value,
                'message' => $message,
                'metadata' => json_encode(['demo' => true, 'location_code' => $key === 'localized-notification' ? 'demo-nl-1' : 'de-1'], JSON_THROW_ON_ERROR),
                'read' => $key === 'regional-notification',
                'sent' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            DB::table('monitoring_notification_states')->insert([
                'id' => $this->id('notification-state:' . $key),
                'monitoring_notification_id' => $notificationId,
                'user_id' => $userId,
                'read_at' => $key === 'regional-notification' ? $createdAt : null,
                'sent_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach (['email', 'slack', 'webhook', 'mobile_push'] as $channel) {
                DB::table('notification_channel_deliveries')->insert([
                    'id' => $this->id('delivery:' . $key . ':' . $channel),
                    'user_id' => $userId,
                    'monitoring_notification_id' => $notificationId,
                    'channel' => $channel,
                    'event_type' => $type->value,
                    'status' => NotificationDeliveryStatus::SKIPPED->value,
                    'payload' => json_encode(['demo' => true, 'delivery' => 'disabled for screenshot fixtures'], JSON_THROW_ON_ERROR),
                    'error_message' => null,
                    'sent_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }
    }

    private function seedStatusPages(string $userId, Carbon $now): void
    {
        $pageId = $this->id('status-page');
        DB::table('status_pages')->insert([
            'id' => $pageId,
            'user_id' => $userId,
            'name' => 'Operations Status',
            'slug' => 'operations-demo',
            'description' => 'Live service health for the Operations Team demo workspace.',
            'is_public' => true,
            'auto_publish_incident_updates' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $components = [
            ['customer-facing', 'Customer services', ['campaign-hub', 'identity-gateway']],
            ['payments', 'Payments', ['payment-api', 'billing-worker']],
            ['platform', 'Platform services', ['asset-pipeline', 'message-queue', 'edge-dns', 'domain-certificate', 'compute-cluster']],
        ];

        foreach ($components as $position => [$key, $name, $monitoringKeys]) {
            $componentId = $this->id('component:' . $key);
            DB::table('status_page_components')->insert([
                'id' => $componentId,
                'status_page_id' => $pageId,
                'monitoring_group_id' => $this->id('group:' . $key),
                'name' => $name,
                'description' => null,
                'position' => $position,
                'source_type' => 'monitoring_group',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($monitoringKeys as $monitoringPosition => $monitoringKey) {
                DB::table('status_page_component_monitoring')->insert([
                    'status_page_component_id' => $componentId,
                    'monitoring_id' => $this->monitoringIds[$monitoringKey],
                    'position' => $monitoringPosition,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $subscribers = ['status-reader@example.test', 'ops-contact@example.test'];
        foreach ($subscribers as $index => $email) {
            DB::table('status_page_subscriptions')->insert([
                'id' => $this->id('status-subscription:' . $index),
                'status_page_id' => $pageId,
                'email' => $email,
                'confirmation_token_hash' => null,
                'unsubscribe_token' => hash('sha256', 'demo-unsubscribe-' . $index),
                'verified_at' => $now->copy()->subDays(15),
                'created_at' => $now->copy()->subDays(15),
                'updated_at' => $now,
            ]);
        }

        foreach (['campaign-hub', 'payment-api'] as $index => $monitoringKey) {
            DB::table('status_page_subscribers')->insert([
                'id' => $this->id('monitoring-subscriber:' . $index),
                'monitoring_id' => $this->monitoringIds[$monitoringKey],
                'email' => $index === 0 ? 'campaign-reader@example.test' : 'payments-reader@example.test',
                'confirmation_token_hash' => null,
                'unsubscribe_token' => hash('sha256', 'demo-monitoring-unsubscribe-' . $index),
                'verified_at' => $now->copy()->subDays(12),
                'created_at' => $now->copy()->subDays(12),
                'updated_at' => $now,
            ]);
        }

        DB::table('status_page_announcements')->insert([
            'id' => $this->id('status-announcement'),
            'status_page_id' => $pageId,
            'title' => 'Scheduled regional maintenance',
            'message' => 'The team will update the edge routing layer during the upcoming maintenance window.',
            'notify_subscribers' => false,
            'notified_at' => null,
            'dismissed_at' => null,
            'created_at' => $now->copy()->subDays(1),
            'updated_at' => $now,
        ]);
    }

    private function seedApiReferenceToken(string $userId, Carbon $now): void
    {
        DB::table('personal_access_tokens')->insert([
            'id' => 99991129,
            'tokenable_type' => User::class,
            'tokenable_id' => $userId,
            'name' => 'Screenshot demo API reference (disabled)',
            'token' => hash('sha256', 'not-a-valid-screenshot-demo-api-token'),
            'abilities' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'expires_at' => $now->copy()->addDays(30),
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, array<string, bool|string>> */
    private function notificationChannels(): array
    {
        return [
            'email' => ['enabled' => false],
            'slack' => ['enabled' => false, 'webhook_url' => 'https://hooks.example.test/demo-disabled'],
            'webhook' => ['enabled' => false, 'url' => 'https://events.example.test/demo-disabled'],
            'mobile_push' => ['enabled' => false],
        ];
    }

    private function id(string $key): string
    {
        if (isset($this->ids[$key])) {
            return $this->ids[$key];
        }

        $binary = mb_substr(hash('sha256', 'webguard-screenshot-demo:' . $key, true), 0, 16);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $encoded = '';

        for ($group = 0; $group < 26; $group++) {
            $value = 0;

            for ($bit = 0; $bit < 5; $bit++) {
                $sourceBit = ($group * 5) + $bit - 2;
                $value <<= 1;

                if ($sourceBit >= 0 && $sourceBit < 128) {
                    $byte = ord($binary[intdiv($sourceBit, 8)]);
                    $value |= ($byte >> (7 - ($sourceBit % 8))) & 1;
                }
            }

            $encoded .= $alphabet[$value];
        }

        return $this->ids[$key] = $encoded;
    }

    private function timeOrderedId(string $key, Carbon $timestamp): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $milliseconds = $timestamp->getTimestampMs();
        $time = '';

        for ($index = 0; $index < 10; $index++) {
            $time = $alphabet[$milliseconds % 32] . $time;
            $milliseconds = intdiv($milliseconds, 32);
        }

        return $time . mb_substr($this->id($key), 10);
    }
}
