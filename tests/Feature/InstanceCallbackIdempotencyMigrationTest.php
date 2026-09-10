<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class InstanceCallbackIdempotencyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_safe_when_the_table_exists_from_a_partial_previous_run(): void
    {
        $migration = require base_path('database/migrations/2026_09_09_090000_create_instance_callback_idempotencies_table.php');

        $this->assertTrue(Schema::hasTable('instance_callback_idempotencies'));

        Schema::table('instance_callback_idempotencies', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('instance_callback_idempotencies_key_unique');
            $blueprint->dropIndex('instance_callback_idempotencies_expires_at_index');
        });

        $migration->up();

        $this->assertTrue(Schema::hasTable('instance_callback_idempotencies'));
        $this->assertTrue(Schema::hasIndex('instance_callback_idempotencies', 'instance_callback_idempotencies_key_unique'));
        $this->assertTrue(Schema::hasIndex('instance_callback_idempotencies', 'instance_callback_idempotencies_expires_at_index'));
    }
}
