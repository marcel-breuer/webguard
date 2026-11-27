<?php

declare(strict_types=1);

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\User;
use Database\Seeders\DemoScreenshotSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Support\Facades\DB;

it('refuses to seed screenshot data in production', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('webguard:seed-screenshot-demo')
        ->expectsOutput('Screenshot demo data cannot be seeded in production.')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'demo.operator@example.test')->exists())->toBeFalse();
});

it('resets the deterministic demo dataset without deleting unrelated rows', function (): void {
    $this->seed(PackageSeeder::class);
    $unrelatedUser = User::factory()->create();
    $unrelatedMonitoring = Monitoring::factory()->create(['user_id' => $unrelatedUser->id]);

    $this->artisan('webguard:seed-screenshot-demo')->assertSuccessful();
    $firstSnapshot = screenshotDemoSnapshot();

    $this->artisan('webguard:seed-screenshot-demo')->assertSuccessful();
    $secondSnapshot = screenshotDemoSnapshot();

    expect($secondSnapshot)->toBe($firstSnapshot);
    $this->assertDatabaseHas('users', ['id' => $unrelatedUser->id, 'email' => $unrelatedUser->email]);
    $this->assertDatabaseHas('monitorings', ['id' => $unrelatedMonitoring->id, 'user_id' => $unrelatedUser->id]);
    $this->assertDatabaseHas('teams', ['id' => DB::table('teams')->where('name', 'Operations Team')->value('id'), 'name' => 'Operations Team']);
});

it('seeds every monitoring type with a 90 day history and a fresh check', function (): void {
    app(DemoScreenshotSeeder::class)->run();

    $seededMonitoringIds = DB::table('monitorings')
        ->where('created_by_user_id', DB::table('users')->where('email', 'demo.operator@example.test')->value('id'))
        ->pluck('id');

    $types = DB::table('monitorings')
        ->whereIn('id', $seededMonitoringIds)
        ->distinct()
        ->orderBy('type')
        ->pluck('type')
        ->all();

    $expectedTypes = MonitoringType::values();
    sort($expectedTypes);

    expect($types)->toBe($expectedTypes);
    expect($seededMonitoringIds)->toHaveCount(count(MonitoringType::cases()) + 1);
    expect(DB::table('monitoring_response_results')->whereIn('monitoring_id', $seededMonitoringIds)->min('created_at'))
        ->toBeLessThan(now()->subDays(90)->toDateTimeString());
    expect(DB::table('monitoring_response_results')->whereIn('monitoring_id', $seededMonitoringIds)->max('created_at'))
        ->toBeGreaterThan(now()->subMinute()->toDateTimeString());
    expect(DB::table('monitoring_response_results')->whereIn('monitoring_id', $seededMonitoringIds)->distinct('location_code')->count('location_code'))
        ->toBe(4);
    expect(DB::table('monitorings')->whereIn('id', $seededMonitoringIds)->where('expected_http_statuses', '200-399')->exists())
        ->toBeTrue();
    expect(DB::table('monitorings')->whereIn('id', $seededMonitoringIds)->where('user_id', DB::table('users')->where('email', 'demo.operator@example.test')->value('id'))->count())
        ->toBe(count($seededMonitoringIds));
    expect(DB::table('monitoring_group_monitoring')->whereIn('monitoring_id', $seededMonitoringIds)->count())
        ->toBe(count($seededMonitoringIds));
    expect(DB::table('monitorings')->whereIn('id', $seededMonitoringIds)->whereNotNull('maintenance_from')->where('maintenance_from', '<=', now())->where('maintenance_until', '>', now())->exists())
        ->toBeTrue();
});

/** @return array<string, mixed> */
function screenshotDemoSnapshot(): array
{
    $userId = DB::table('users')->where('email', 'demo.operator@example.test')->value('id');
    $teamId = DB::table('teams')->where('name', 'Operations Team')->value('id');
    $monitoringIds = DB::table('monitorings')->where('created_by_user_id', $userId)->orderBy('id')->pluck('id');

    return [
        'user' => [$userId],
        'team' => [$teamId],
        'monitorings' => $monitoringIds->all(),
        'responses' => [DB::table('monitoring_response_results')->whereIn('monitoring_id', $monitoringIds)->count()],
        'incidents' => DB::table('incidents')->whereIn('monitoring_id', $monitoringIds)->orderBy('id')->pluck('id')->all(),
        'status_pages' => DB::table('status_pages')->where('user_id', $userId)->orderBy('id')->pluck('id')->all(),
    ];
}
