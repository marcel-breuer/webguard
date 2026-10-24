<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MonitoringGroupSlaReportMail;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringGroup;
use App\Models\Package;
use App\Models\User;
use App\Services\MonitoringGroupSlaReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonitoringGroupSlaReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_report_excludes_unknown_and_planned_maintenance_minutes(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoringGroup = MonitoringGroup::factory()->for($user)->create([
            'sla_target_percentage' => 99.5,
            'sla_report_enabled' => false,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create([
            'maintenance_from' => '2026-08-01 00:00:00',
            'maintenance_until' => '2026-08-01 01:00:00',
        ]);
        $monitoringGroup->monitorings()->attach($monitoring);
        DB::table('monitoring_daily_results')->insert([
            'monitoring_id' => $monitoring->id,
            'date' => '2026-08-01',
            'uptime_total' => 10,
            'downtime_total' => 0,
            'unknown_total' => 2,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 1320,
            'downtime_minutes' => 0,
            'unknown_minutes' => 120,
            'incidents_count' => 0,
        ]);

        $report = resolve(MonitoringGroupSlaReportService::class)->build(
            $monitoringGroup,
            Date::parse('2026-08-01'),
            Date::parse('2026-08-01'),
        );

        $this->assertEquals(100.0, $report['uptime_percentage']);
        $this->assertTrue($report['meets_target']);
        $this->assertSame(1320, $report['uptime_minutes']);
        $this->assertSame(0, $report['downtime_minutes']);
        $this->assertSame(60, $report['unknown_minutes']);
    }

    public function test_owner_can_download_the_previous_calendar_month_report_only_for_an_owned_configured_group(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $monitoringGroup = MonitoringGroup::factory()->for($user)->create(['sla_target_percentage' => 99.9]);
        $monitoring = Monitoring::factory()->for($user)->create();
        $monitoringGroup->monitorings()->attach($monitoring);
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => '2026-08-01',
            'uptime_total' => 1,
            'downtime_total' => 0,
            'unknown_total' => 0,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 1440,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'incidents_count' => 0,
        ]);

        $this->actingAs($user)
            ->get('/api/monitoring-groups/' . $monitoringGroup->id . '/sla-report')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('webguard-sla-' . $monitoringGroup->id . '-2026-09.csv');

        $foreignGroup = MonitoringGroup::factory()->for(User::factory()->create())->create(['sla_target_percentage' => 99.9]);
        $this->actingAs($user)->get('/api/monitoring-groups/' . $foreignGroup->id . '/sla-report')->assertNotFound();

        $unconfiguredGroup = MonitoringGroup::factory()->for($user)->create();
        $this->actingAs($user)->get('/api/monitoring-groups/' . $unconfiguredGroup->id . '/sla-report')->assertNotFound();
    }

    public function test_monthly_command_sends_reports_only_to_verified_group_owners(): void
    {
        Mail::fake();
        Package::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $monitoringGroup = MonitoringGroup::factory()->for($user)->create([
            'sla_target_percentage' => 99.9,
            'sla_report_enabled' => true,
        ]);
        $monitoring = Monitoring::factory()->for($user)->create();
        $monitoringGroup->monitorings()->attach($monitoring);
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => '2026-08-01',
            'uptime_total' => 1,
            'downtime_total' => 0,
            'unknown_total' => 0,
            'uptime_percentage' => 100,
            'downtime_percentage' => 0,
            'unknown_percentage' => 0,
            'uptime_minutes' => 1440,
            'downtime_minutes' => 0,
            'unknown_minutes' => 0,
            'incidents_count' => 0,
        ]);

        $this->artisan('monitoring:send-sla-reports --period-end=2026-08-31')->assertSuccessful();

        Mail::assertSent(MonitoringGroupSlaReportMail::class, fn (MonitoringGroupSlaReportMail $monitoringGroupSlaReportMail): bool => $monitoringGroupSlaReportMail->monitoringGroup->is($monitoringGroup));
    }
}
