<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Models\Monitoring;
use App\Models\Package;
use App\Models\User;
use App\Queries\MonitoringCardQuery;
use App\Queries\MonitoringDataQuery;
use App\Queries\MonitoringDetailQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class MonitoringReadQueriesTest extends TestCase
{
    public function test_detail_query_only_resolves_monitorings_visible_to_the_actor(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $visible = Monitoring::factory()->for($user)->create();
        $hidden = Monitoring::factory()->for($otherUser)->create();

        $monitoringDetailQuery = resolve(MonitoringDetailQuery::class);

        $detail = $monitoringDetailQuery->findVisible($user, $visible->id);

        $this->assertTrue($detail->is($visible));
        $this->assertNotNull($detail->created_at);
        $this->assertFalse((bool) $detail->getAttribute('has_enabled_maintenance_windows'));
        $this->expectException(ModelNotFoundException::class);

        $monitoringDetailQuery->findVisible($user, $hidden->id);
    }

    public function test_card_query_only_returns_requested_monitorings_visible_to_the_actor(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $visible = Monitoring::factory()->for($user)->create();
        $hidden = Monitoring::factory()->for($otherUser)->create();

        $monitorings = resolve(MonitoringCardQuery::class)->for($user, [$visible->id, $hidden->id]);

        $this->assertSame([$visible->id], $monitorings->modelKeys());
        $this->assertTrue($monitorings->first()->relationLoaded('latestIncident'));
        $this->assertTrue($monitorings->first()->relationLoaded('latestResponseResult'));
    }

    public function test_data_query_allows_actor_visible_and_public_monitorings_without_leaking_private_ones(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $visible = Monitoring::factory()->for($user)->create();
        $public = Monitoring::factory()->for($otherUser)->create(['public_label_enabled' => true]);
        $private = Monitoring::factory()->for($otherUser)->create(['public_label_enabled' => false]);

        $monitoringDataQuery = resolve(MonitoringDataQuery::class);

        $this->assertTrue($monitoringDataQuery->findAccessible($user, $visible->id)->is($visible));
        $this->assertTrue($monitoringDataQuery->findAccessible($user, $public->id)->is($public));
        $this->expectException(ModelNotFoundException::class);

        $monitoringDataQuery->findAccessible($user, $private->id);
    }
}
