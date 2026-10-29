<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('first_run_notification_tested_at')->nullable();
            $table->timestamp('first_run_setup_completed_at')->nullable();
            $table->timestamp('first_run_setup_dismissed_at')->nullable();
        });

        DB::table('users')->update(['first_run_setup_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'first_run_notification_tested_at',
                'first_run_setup_completed_at',
                'first_run_setup_dismissed_at',
            ]);
        });
    }
};
