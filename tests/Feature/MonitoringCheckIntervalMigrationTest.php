<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitoringCheckIntervalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_existing_website_monitorings_to_fifteen_minutes(): void
    {
        Package::factory()->create();
        $user = User::factory()->create();
        $http = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::HTTP,
            'check_interval_minutes' => 60,
        ]);
        $keyword = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::KEYWORD,
            'check_interval_minutes' => 1,
        ]);
        $ping = Monitoring::factory()->for($user)->create([
            'type' => MonitoringType::PING,
            'check_interval_minutes' => null,
        ]);

        Schema::table('monitorings', function (Blueprint $table): void {
            $table->dropColumn('check_interval_minutes');
        });

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_10_24_090000_add_check_interval_minutes_to_monitorings_table.php');
        $migration->up();

        $this->assertSame(15, $http->fresh()->check_interval_minutes);
        $this->assertSame(15, $keyword->fresh()->check_interval_minutes);
        $this->assertNull($ping->fresh()->check_interval_minutes);
    }
}
