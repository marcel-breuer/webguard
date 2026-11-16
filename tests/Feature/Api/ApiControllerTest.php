<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['external_api.rate_limit_per_minute' => 5]);
    }

    public function test_returns_the_correct_interval_in_the_status_endpoint(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
        ]);

        $token = $user->createToken('status-rate-limit')->plainTextToken;
        $testResponse = $this->withToken($token)->getJson('/api/monitorings/' . $monitoring->id . '/status');

        $testResponse->assertOk();
        $testResponse->assertJson(['interval' => 900]);
        $testResponse->assertHeader('X-RateLimit-Limit', '5');
        $testResponse->assertHeader('X-RateLimit-Remaining', '4');
        $this->assertGreaterThan(time(), (int) $testResponse->headers->get('X-RateLimit-Reset'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $testResponse->headers->get('X-Request-Id')
        );
    }

    public function test_returns_status_metadata_and_translation_keys_in_status_endpoint(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN,
            'http_status_code' => 503,
            'response_time' => 220.0,
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/status');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('status_code', 503);
        $testResponse->assertJsonPath('status_identifier', 'status.server_error');
        $testResponse->assertJsonPath('status_key', 'notifications.status.server_error');
        $testResponse->assertJsonPath('monitoring.name', $monitoring->name);
        $testResponse->assertJsonPath('monitoring.target', $monitoring->target);
    }

    public function test_token_api_requests_are_rate_limited(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $token = $user->createToken('rate-limited-api')->plainTextToken;

        foreach (range(1, 5) as $_) {
            $this->withToken($token)->getJson('/api/monitorings/' . $monitoring->id . '/status')->assertOk();
        }

        $testResponse = $this->withToken($token)->getJson('/api/monitorings/' . $monitoring->id . '/status');

        $testResponse->assertTooManyRequests();

        $retryAfter = (int) $testResponse->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $testResponse->assertHeader('X-RateLimit-Limit', '5');
        $testResponse->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(time(), (int) $testResponse->headers->get('X-RateLimit-Reset'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $testResponse->headers->get('X-Request-Id')
        );
    }

    public function test_api_access_tokens_are_rate_limited_and_logged(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $token = $user->createToken('api-access')->plainTextToken;

        foreach (range(1, 5) as $_) {
            $this->withToken($token)
                ->getJson('/api/monitorings/' . $monitoring->id . '/status')
                ->assertOk();
        }

        $this->withToken($token)
            ->getJson('/api/monitorings/' . $monitoring->id . '/status')
            ->assertTooManyRequests();

        $this->assertDatabaseCount('api_logs', 5);
    }

    public function test_mobile_app_tokens_are_not_rate_limited_or_logged_as_api_usage(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $token = $user->createToken('ios-app:Marcel iPhone')->plainTextToken;

        foreach (range(1, 6) as $_) {
            $this->withToken($token)
                ->getJson('/api/monitorings/' . $monitoring->id . '/status')
                ->assertOk();
        }

        $this->assertDatabaseCount('api_logs', 0);
    }

    public function test_external_token_abilities_can_be_enforced_without_breaking_existing_wildcard_tokens(): void
    {
        config()->set('external_api.enforce_token_abilities', true);

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        $readToken = $user->createToken('read-only', ['external:read'])->plainTextToken;
        $legacyToken = $user->createToken('legacy')->plainTextToken;

        $this->withToken($readToken)
            ->getJson('/api/monitorings/' . $monitoring->id . '/status')
            ->assertOk();

        $this->withToken($readToken)
            ->deleteJson('/api/monitorings/' . $monitoring->id)
            ->assertForbidden();

        $this->withToken($legacyToken)
            ->getJson('/api/monitorings/' . $monitoring->id . '/status')
            ->assertOk();
    }

    public function test_all_endpoint_returns_combined_monitoring_payload_without_nested_controller_responses(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-10 00:00:00'),
        ]);

        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 200,
            'response_time' => 150.0,
            'created_at' => Date::parse('2026-04-12 11:00:00'),
            'updated_at' => Date::parse('2026-04-12 11:00:00'),
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/analytics?' . http_build_query([
            'days' => 1,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
        ]));

        $testResponse->assertOk();
        $testResponse->assertJsonPath('status_since.status', MonitoringStatus::UP->value);
        $testResponse->assertJsonPath('status_now.status', MonitoringStatus::UP->value);
        $testResponse->assertJsonPath('ssl.valid', null);
        $testResponse->assertJsonCount(24, 'heatmap');
        $testResponse->assertJsonStructure([
            'status_since',
            'status_now',
            'uptime_downtime' => ['uptime', 'downtime', 'unknown'],
            'response_times' => ['data', 'aggregated'],
            'incidents',
            'heatmap',
            'ssl',
            'uptime_calendar',
        ]);
    }

    public function test_all_endpoint_validates_calendar_dates_through_request_object(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/analytics');

        $testResponse->assertUnprocessable();
        $testResponse->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_uptime_calendar_endpoint_returns_stable_month_payload_contract(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::parse('2026-04-01 00:00:00'),
        ]);

        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => '2026-04-10',
            'uptime_total' => 1,
            'downtime_total' => 1,
            'unknown_total' => 0,
            'uptime_percentage' => 75.0,
            'downtime_percentage' => 25.0,
            'unknown_percentage' => 0.0,
            'uptime_minutes' => 90,
            'downtime_minutes' => 30,
            'unknown_minutes' => 0,
            'avg_response_time' => 100.0,
            'min_response_time' => 100,
            'max_response_time' => 100,
            'incidents_count' => 0,
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/uptime-calendar?' . http_build_query([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
        ]));

        $testResponse->assertOk();
        $testResponse->assertJsonStructure([
            '2026-04' => [
                'days' => [
                    '*' => ['date', 'uptime_percentage'],
                ],
                'monthly_average_uptime',
            ],
        ]);
        $testResponse->assertJsonCount(30, '2026-04.days');
        $testResponse->assertJsonPath('2026-04.days.9.uptime_percentage', 75);
        $testResponse->assertJsonPath('2026-04.monthly_average_uptime', 75);
    }

    public function test_response_times_endpoint_returns_monitoring_response_payload(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'created_at' => Date::now()->subDay(),
        ]);
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 200,
            'response_time' => 123.4,
            'created_at' => Date::now()->subHour(),
            'updated_at' => Date::now()->subHour(),
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/response-times?days=1');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('aggregated.avg', 123.4);
        $testResponse->assertJsonPath('data.0.avg', 123.4);
    }

    public function test_incidents_endpoint_returns_incident_payload(): void
    {
        Date::setTestNow('2026-04-20 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        Incident::query()->create([
            'monitoring_id' => $monitoring->id,
            'down_at' => Date::now()->subHours(2),
            'up_at' => Date::now()->subHour(),
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/incidents?days=1');

        $testResponse->assertOk();
        $this->assertNotNull($testResponse->json('0.down_at'));
        $this->assertNotNull($testResponse->json('0.up_at'));
    }

    public function test_ssl_status_endpoint_returns_ssl_payload(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();
        MonitoringSslResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'is_valid' => true,
            'expires_at' => '2026-12-01 00:00:00',
            'issuer' => 'Example CA',
            'issued_at' => '2026-01-01 00:00:00',
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/ssl');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('valid', true);
        $testResponse->assertJsonPath('issuer', 'Example CA');
    }

    public function test_results_endpoint_exposes_http_status_code_for_historical_entries(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $liveCheckedAt = now()->subMinutes(3);
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 204,
            'response_time' => 123.4,
            'created_at' => $liveCheckedAt,
            'updated_at' => $liveCheckedAt,
        ]);

        $archivedCheckedAt = now()->subDay();
        DB::table('monitoring_response_archived')->insert([
            'id' => (string) Str::ulid(),
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN->value,
            'http_status_code' => 503,
            'response_time' => 222.0,
            'created_at' => $archivedCheckedAt,
            'updated_at' => $archivedCheckedAt,
        ]);

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?limit=10');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('meta.count', 2);
        $testResponse->assertJsonPath('data.0.http_status_code', 204);
        $testResponse->assertJsonPath('data.0.status_identifier', 'status.success');
        $testResponse->assertJsonPath('data.1.http_status_code', 503);
        $testResponse->assertJsonPath('data.1.status_identifier', 'status.server_error');
    }

    public function test_results_endpoint_skips_archived_history_query_when_live_rows_fill_the_page(): void
    {
        Date::setTestNow('2026-04-06 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach (range(1, 12) as $minuteOffset) {
            $checkedAt = Date::now()->subMinutes($minuteOffset);

            MonitoringResponse::query()->forceCreate([
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP,
                'http_status_code' => 200,
                'response_time' => 100 + $minuteOffset,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);
        }

        DB::table('monitoring_response_archived')->insert([
            'id' => (string) Str::ulid(),
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN->value,
            'http_status_code' => 503,
            'response_time' => 222.0,
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?limit=10');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('meta.count', 10);
        $testResponse->assertJsonCount(10, 'data');
        $testResponse->assertJsonMissingPath('data.10');
        $testResponse->assertJsonPath('data.0.source', 'live');

        $historyQueries = $this->historyQueries();

        $this->assertCount(1, $historyQueries);
        $this->assertStringContainsString('monitoring_response_results', $historyQueries[0]);
        $this->assertStringNotContainsString('monitoring_response_archived', $historyQueries[0]);
    }

    public function test_results_endpoint_uses_only_live_history_for_recent_day_filters(): void
    {
        Date::setTestNow('2026-04-06 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $recentCheck = Date::now()->subDay();
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::UP,
            'http_status_code' => 204,
            'response_time' => 150.0,
            'created_at' => $recentCheck,
            'updated_at' => $recentCheck,
        ]);

        DB::table('monitoring_response_archived')->insert([
            'id' => (string) Str::ulid(),
            'monitoring_id' => $monitoring->id,
            'status' => MonitoringStatus::DOWN->value,
            'http_status_code' => 503,
            'response_time' => 250.0,
            'created_at' => Date::now()->subDays(14),
            'updated_at' => Date::now()->subDays(14),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?days=2&limit=10');

        $testResponse->assertOk();
        $testResponse->assertJsonPath('meta.count', 1);
        $testResponse->assertJsonPath('data.0.source', 'live');

        $historyQueries = $this->historyQueries();

        $this->assertCount(1, $historyQueries);
        $this->assertStringContainsString('monitoring_response_results', $historyQueries[0]);
        $this->assertStringNotContainsString('monitoring_response_archived', $historyQueries[0]);
    }

    public function test_results_endpoint_supports_offset_pagination_for_recent_checks(): void
    {
        Date::setTestNow('2026-04-06 12:00:00');

        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        foreach (range(1, 8) as $minuteOffset) {
            $checkedAt = Date::now()->subMinutes($minuteOffset);

            MonitoringResponse::query()->forceCreate([
                'monitoring_id' => $monitoring->id,
                'status' => MonitoringStatus::UP,
                'http_status_code' => 200,
                'response_time' => 100 + $minuteOffset,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);
        }

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?days=1&limit=5');

        $testResponse->assertOk();
        $testResponse->assertJsonCount(5, 'data');
        $testResponse->assertJsonPath('meta.count', 5);
        $testResponse->assertJsonPath('meta.offset', 0);
        $testResponse->assertJsonPath('meta.has_more', true);
        $testResponse->assertJsonPath('meta.next_offset', 5);
        $this->assertSame(101.0, (float) $testResponse->json('data.0.response_time'));

        $secondPageResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?days=1&limit=5&offset=5');

        $secondPageResponse->assertOk();
        $secondPageResponse->assertJsonCount(3, 'data');
        $secondPageResponse->assertJsonPath('meta.count', 3);
        $secondPageResponse->assertJsonPath('meta.offset', 5);
        $secondPageResponse->assertJsonPath('meta.has_more', false);
        $secondPageResponse->assertJsonPath('meta.next_offset', null);
        $this->assertSame(106.0, (float) $secondPageResponse->json('data.0.response_time'));
    }

    public function test_results_endpoint_validates_pagination_bounds_through_request_object(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create();

        $testResponse = $this->actingAs($user)->getJson('/api/monitorings/' . $monitoring->id . '/checks?limit=1001&offset=-1');

        $testResponse->assertUnprocessable();
        $testResponse->assertJsonValidationErrors(['limit', 'offset']);
    }

    public function test_history_response_tables_have_indexes_for_timeline_pagination(): void
    {
        $this->assertTableHasIndexColumns(
            'monitoring_response_results',
            ['monitoring_id', 'created_at', 'id']
        );

        $this->assertTableHasIndexColumns(
            'monitoring_response_archived',
            ['monitoring_id', 'created_at', 'id']
        );
    }

    /**
     * @return list<string>
     */
    private function historyQueries(): array
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->map(static fn (string $query): string => mb_strtolower($query))
            ->filter(static fn (string $query): bool => str_contains($query, 'monitoring_response_results')
                || str_contains($query, 'monitoring_response_archived'))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertTableHasIndexColumns(string $table, array $columns): void
    {
        $indexColumns = collect(Schema::getIndexes($table))
            ->map(static fn (array $index): array => $index['columns'])
            ->values();

        $this->assertTrue(
            $indexColumns->contains($columns),
            sprintf(
                'Expected %s to have an index on (%s). Existing index columns: %s',
                $table,
                implode(', ', $columns),
                $indexColumns->map(static fn (array $indexedColumns): string => '(' . implode(', ', $indexedColumns) . ')')->implode(', ')
            )
        );
    }
}
