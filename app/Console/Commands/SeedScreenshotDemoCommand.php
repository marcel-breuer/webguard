<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoScreenshotSeeder;
use Illuminate\Console\Command;

class SeedScreenshotDemoCommand extends Command
{
    protected $signature = 'webguard:seed-screenshot-demo';

    protected $description = 'Reset and seed the anonymized screenshot demo workspace';

    public function handle(DemoScreenshotSeeder $seeder): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Screenshot demo data cannot be seeded in production.');

            return self::FAILURE;
        }

        $seeder->run();

        $this->info('Screenshot demo workspace seeded. Sign in as demo.operator@example.test with password "password".');

        return self::SUCCESS;
    }
}
