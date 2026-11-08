<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentDiagnosisTrigger;
use App\Enums\MonitoringStatus;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Models\Package;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentDiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IncidentDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_diagnosis_is_persisted_when_an_incident_opens(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $monitoring = $this->httpMonitoring(User::factory()->create());

        $this->fail503($monitoring);

        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();

        $this->assertNotNull($incident->diagnosed_at);
        $this->assertSame(1, $incident->diagnosis_snapshot['version']);
        $this->assertSame('incident_opened', $incident->diagnosis_snapshot['trigger']);
        $this->assertSame('server_error', $incident->diagnosis_snapshot['primary_category']);
        $this->assertSame('server_error', $incident->diagnosis_snapshot['hypotheses'][0]['category']);
        $this->assertSame(1, $incident->diagnosis_snapshot['hypotheses'][0]['rank']);
        $this->assertSame(503, $incident->diagnosis_snapshot['signals']['failing_checks'][0]['http_status_code']);
    }

    public function test_expired_certificate_and_recent_config_change_are_collected(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $monitoring = $this->httpMonitoring(User::factory()->create());
        MonitoringSslResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'is_valid' => false,
            'expires_at' => Date::now()->subDay(),
        ]);
        Date::setTestNow('2026-11-08 11:30:00');
        $monitoring->update(['target' => 'https://example.test/new', 'auth_password' => 'super-secret-value']);
        Date::setTestNow('2026-11-08 12:00:00');

        $this->fail503($monitoring);

        $snapshot = Incident::query()->where('monitoring_id', $monitoring->id)->sole()->diagnosis_snapshot;
        $categories = array_column($snapshot['hypotheses'], 'category');

        $this->assertSame('certificate', $snapshot['hypotheses'][0]['category']);
        $this->assertContains('config_change', $categories);
        $this->assertSame(30, $snapshot['signals']['config_changes'][0]['minutes_before']);
        $this->assertContains('target', $snapshot['signals']['config_changes'][0]['fields']);
        $this->assertStringNotContainsString('super-secret-value', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('https://example.test/new', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_related_dns_mismatch_and_same_host_incidents_are_correlated(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $user = User::factory()->create();
        $monitoring = $this->httpMonitoring($user);
        $dns = Monitoring::factory()->for($user)->create([
            'type' => 'dns_record',
            'target' => 'example.test',
            'dns_record_type' => 'A',
            'dns_expected_values' => ['203.0.113.10'],
        ]);
        MonitoringResponse::query()->create([
            'monitoring_id' => $dns->id,
            'location_code' => 'de-1',
            'vital_values' => ['observed_values' => ['203.0.113.99']],
        ]);
        $other = $this->httpMonitoring($user, 'https://shop.example.test/');
        $this->fail503($other);
        $outsider = $this->httpMonitoring(User::factory()->create());
        $this->fail503($outsider);

        $this->fail503($monitoring);

        $snapshot = Incident::query()->where('monitoring_id', $monitoring->id)->sole()->diagnosis_snapshot;
        $categories = array_column($snapshot['hypotheses'], 'category');

        $this->assertContains('dns', $categories);
        $this->assertContains('shared_dependency', $categories);
        $this->assertSame($dns->id, $snapshot['signals']['dns_mismatches'][0]['monitoring_id']);
        $this->assertCount(1, $snapshot['signals']['correlated_incidents']);
        $this->assertSame($other->id, $snapshot['signals']['correlated_incidents'][0]['monitoring_id']);
    }

    public function test_diagnosis_failure_never_blocks_incident_creation(): void
    {
        $this->mock(IncidentDiagnosisService::class)
            ->shouldReceive('diagnoseAndStore')
            ->andThrow(new RuntimeException('boom'));

        $monitoring = $this->httpMonitoring(User::factory()->create());
        $this->fail503($monitoring);

        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        $this->assertNull($incident->diagnosis_snapshot);
    }

    public function test_recompute_replaces_the_stored_snapshot(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $monitoring = $this->httpMonitoring(User::factory()->create());
        $this->fail503($monitoring);
        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        MonitoringSslResult::query()->create(['monitoring_id' => $monitoring->id, 'is_valid' => false, 'expires_at' => Date::now()->subDay()]);

        resolve(IncidentDiagnosisService::class)->diagnoseAndStore($incident, IncidentDiagnosisTrigger::RECOMPUTED);

        $snapshot = $incident->refresh()->diagnosis_snapshot;
        $this->assertSame('recomputed', $snapshot['trigger']);
        $this->assertSame('certificate', $snapshot['primary_category']);
    }

    public function test_first_party_api_returns_localized_diagnosis_and_enforces_visibility(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $owner = User::factory()->create();
        $monitoring = $this->httpMonitoring($owner);
        $this->fail503($monitoring);
        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();

        $this->actingAs($owner)
            ->getJson(route('app.incidents.diagnosis.show', $incident))
            ->assertOk()
            ->assertJsonPath('data.incident.status', 'open')
            ->assertJsonPath('data.diagnosis.primary.category', 'server_error')
            ->assertJsonPath('data.diagnosis.primary.confidence_level', 'high')
            ->assertJsonPath('data.diagnosis.primary.label', 'Server error')
            ->assertJsonPath('data.diagnosis.primary.evidence.0.type', 'check')
            ->assertJsonPath('data.diagnosis.signals.failing_checks.0.http_status_code', 503);

        $owner->forceFill(['locale' => 'de'])->save();
        $this->actingAs($owner->refresh())
            ->getJson(route('app.incidents.diagnosis.show', $incident))
            ->assertOk()
            ->assertJsonPath('data.diagnosis.primary.label', 'Serverfehler');

        $this->actingAs(User::factory()->create())
            ->getJson(route('app.incidents.diagnosis.show', $incident))
            ->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->postJson(route('app.incidents.diagnosis.recompute', $incident))
            ->assertNotFound();
    }

    public function test_incident_without_snapshot_is_backfilled_on_demand_and_recompute_works(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $owner = User::factory()->create();
        $monitoring = $this->httpMonitoring($owner);
        $this->fail503($monitoring);
        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        $incident->forceFill(['diagnosis_snapshot' => null, 'diagnosed_at' => null])->saveQuietly();

        $this->actingAs($owner)
            ->getJson(route('app.incidents.diagnosis.show', $incident))
            ->assertOk()
            ->assertJsonPath('data.diagnosis.trigger', 'on_demand');

        $this->actingAs($owner)
            ->postJson(route('app.incidents.diagnosis.recompute', $incident))
            ->assertOk()
            ->assertJsonPath('data.diagnosis.trigger', 'recomputed');
        $this->assertSame('recomputed', $incident->refresh()->diagnosis_snapshot['trigger']);
    }

    public function test_team_members_can_read_team_incident_diagnoses_and_open_list_is_scoped(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['created_by_user_id' => $admin->id]);
        $team->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $team->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $teamMonitoring = Monitoring::factory()->create([
            'user_id' => null, 'team_id' => $team->id, 'type' => 'http', 'target' => 'https://team.example.test/',
            'expected_http_statuses' => '200-299', 'timeout' => 10, 'failure_confirmation_threshold' => 1,
        ]);
        $this->fail503($teamMonitoring);
        $this->fail503($this->httpMonitoring(User::factory()->create()));
        $incident = Incident::query()->where('monitoring_id', $teamMonitoring->id)->sole();

        $this->actingAs($member)
            ->getJson(route('app.incidents.diagnosis.show', $incident))
            ->assertOk();
        $this->actingAs($member)
            ->getJson(route('app.incidents.diagnoses.open'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.incident.id', $incident->id);
    }

    public function test_monitoring_detail_data_includes_incident_ids_and_diagnosis(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $owner = User::factory()->create();
        $monitoring = $this->httpMonitoring($owner);
        $this->fail503($monitoring);
        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();

        $this->actingAs($owner)
            ->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.incidents.0.id', $incident->id)
            ->assertJsonPath('data.incidents.0.diagnosis.primary.category', 'server_error')
            ->assertJsonMissingPath('data.incidents.0.diagnosis.signals');
    }

    public function test_mobile_namespace_exposes_the_same_diagnosis(): void
    {
        Date::setTestNow('2026-11-08 12:00:00');
        $owner = User::factory()->create();
        $monitoring = $this->httpMonitoring($owner);
        $this->fail503($monitoring);
        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        $token = $owner->createToken('ios-app:test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/incidents/' . $incident->id . '/diagnosis')
            ->assertOk()
            ->assertJsonPath('data.diagnosis.primary.category', 'server_error');
        $this->withToken($token)
            ->getJson('/api/mobile/incidents/open/diagnoses')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function httpMonitoring(User $user, string $target = 'https://example.test/'): Monitoring
    {
        return Monitoring::factory()->for($user)->create([
            'type' => 'http',
            'target' => $target,
            'expected_http_statuses' => '200-299',
            'timeout' => 10,
            'failure_confirmation_threshold' => 1,
        ]);
    }

    private function fail503(Monitoring $monitoring): MonitoringResponse
    {
        $response = MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'location_code' => 'de-1',
            'status' => MonitoringStatus::DOWN,
            'http_status_code' => 503,
            'response_time' => 120.0,
        ]);
        DB::table('monitoring_response_results')->where('id', $response->id)->update(['created_at' => Date::now()->subSeconds(30)]);

        return $response;
    }
}
