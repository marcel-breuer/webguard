<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_groups', function (Blueprint $table): void {
            $table->decimal('sla_target_percentage', 5, 2)->nullable();
            $table->boolean('sla_report_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_groups', function (Blueprint $table): void {
            $table->dropColumn(['sla_target_percentage', 'sla_report_enabled']);
        });
    }
};
