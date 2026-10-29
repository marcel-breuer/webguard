<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MonitoringStatus;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\MonitoringResponse;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalConsensusIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_incident_and_authenticated_monitoring_detail_keep_the_event_time_consensus_evidence(): void
    {
        Date::setTestNow('2026-10-29 12:00:00');
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create([
            'type' => 'ping',
            'preferred_location' => 'de-1',
            'preferred_locations' => ['us-1', 'jp-1', 'sg-1', 'au-1'],
        ]);

        $staleResponse = $this->storeResponse($monitoring, 'sg-1', MonitoringStatus::DOWN);
        DB::table('monitoring_response_results')
            ->where('id', $staleResponse->id)
            ->update(['created_at' => Date::now()->subMinutes(20)]);
        $this->storeResponse($monitoring, 'de-1', MonitoringStatus::DOWN);
        $this->storeResponse($monitoring, 'us-1', MonitoringStatus::DOWN);
        $this->storeResponse($monitoring, 'jp-1', MonitoringStatus::DOWN, diagnostics: ['auth_token' => 'private-token']);

        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        $snapshot = $incident->consensus_snapshot;

        $this->assertSame('regional', $snapshot['status']);
        $this->assertSame(5, $snapshot['total_locations']);
        $this->assertSame(3, $snapshot['reporting_locations']);
        $this->assertSame(3, $snapshot['required_failures']);
        $affectedLocations = $snapshot['affected_locations'];
        sort($affectedLocations);
        $this->assertSame(['de-1', 'jp-1', 'us-1'], $affectedLocations);
        $this->assertSame('stale', $snapshot['locations'][3]['availability']);
        $this->assertSame('unknown', $snapshot['locations'][3]['status']);
        $this->assertSame('missing', $snapshot['locations'][4]['availability']);
        $this->assertSame('unknown', $snapshot['locations'][4]['status']);
        $this->assertStringNotContainsString('private-token', json_encode($snapshot, JSON_THROW_ON_ERROR));

        $notification = MonitoringNotification::query()->where('monitoring_id', $monitoring->id)->sole();
        $this->assertSame($snapshot, $notification->metadata['regional_consensus']);

        $this->storeResponse($monitoring, 'de-1', MonitoringStatus::UP);
        $this->assertSame($snapshot, $incident->refresh()->consensus_snapshot);

        $this->actingAs($user)
            ->getJson(route('app.monitorings.detail-data', $monitoring))
            ->assertOk()
            ->assertJsonPath('data.incidents.0.consensus_snapshot.status', 'regional')
            ->assertJsonPath('data.incidents.0.consensus_snapshot.required_failures', 3)
            ->assertJsonPath('data.incidents.0.consensus_snapshot.locations.3.availability', 'stale')
            ->assertJsonPath('data.incidents.0.consensus_snapshot.locations.4.availability', 'missing');
    }

    public function test_single_location_incidents_keep_the_existing_unexplained_event_shape(): void
    {
        Package::factory()->create();
        $monitoring = Monitoring::factory()->for(User::factory())->create([
            'type' => 'ping',
            'failure_confirmation_threshold' => 1,
        ]);

        $this->storeResponse($monitoring, 'de-1', MonitoringStatus::DOWN);

        $incident = Incident::query()->where('monitoring_id', $monitoring->id)->sole();
        $notification = MonitoringNotification::query()->where('monitoring_id', $monitoring->id)->sole();

        $this->assertNull($incident->consensus_snapshot);
        $this->assertNull($notification->metadata);
    }

    private function storeResponse(
        Monitoring $monitoring,
        string $location,
        MonitoringStatus $status,
        array $diagnostics = [],
    ): MonitoringResponse {
        return MonitoringResponse::query()->create([
            'monitoring_id' => $monitoring->id,
            'location_code' => $location,
            'status' => $status,
            'vital_values' => $diagnostics,
        ]);
    }
}
