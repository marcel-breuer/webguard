<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ServerInstance;
use App\Support\MonitoringLocationLabel;
use Tests\TestCase;

class MonitoringLocationLabelTest extends TestCase
{
    public function test_descriptive_display_name_is_used(): void
    {
        $serverInstance = new ServerInstance([
            'code' => 'nl-ams-1',
            'display_name' => 'Amsterdam West',
            'country_code' => 'NL',
            'region' => 'Europe',
        ]);

        $this->assertSame('Amsterdam West', (new MonitoringLocationLabel)->for($serverInstance));
    }

    public function test_generic_display_name_uses_region_and_country_code(): void
    {
        $serverInstance = new ServerInstance([
            'code' => 'de-1',
            'display_name' => 'Location 1',
            'country_code' => 'DE',
            'region' => 'Frankfurt',
        ]);

        $this->assertSame('Frankfurt, DE', (new MonitoringLocationLabel)->for($serverInstance));
    }

    public function test_missing_location_metadata_falls_back_to_code(): void
    {
        $serverInstance = new ServerInstance(['code' => 'eu-1', 'display_name' => 'Location 1']);

        $this->assertSame('eu-1', (new MonitoringLocationLabel)->for($serverInstance));
    }
}
