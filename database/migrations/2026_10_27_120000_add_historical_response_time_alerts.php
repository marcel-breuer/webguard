<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('monitorings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('response_time_degradation_percent')->nullable()->after('response_time_confirmation_threshold');
        });

        Schema::table('monitoring_notifications', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('message');
        });

        Schema::table('monitoring_response_archived', function (Blueprint $table): void {
            $table->index(['monitoring_id', 'location_code', 'created_at'], 'monitoring_archived_location_history_idx');
        });

        Schema::create('monitoring_performance_location_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('monitoring_id')->constrained()->cascadeOnDelete();
            $table->string('location_code');
            $table->string('status')->default('normal');
            $table->unsignedTinyInteger('consecutive_breaches')->default(0);
            $table->timestamp('degraded_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
            $table->unique(['monitoring_id', 'location_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_performance_location_states');

        Schema::table('monitoring_response_archived', function (Blueprint $table): void {
            $table->dropIndex('monitoring_archived_location_history_idx');
        });

        Schema::table('monitoring_notifications', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });

        Schema::table('monitorings', function (Blueprint $table): void {
            $table->dropColumn('response_time_degradation_percent');
        });
    }
};
