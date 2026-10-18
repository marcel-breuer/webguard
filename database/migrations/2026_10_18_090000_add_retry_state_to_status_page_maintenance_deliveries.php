<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_page_maintenance_deliveries', function (Blueprint $table): void {
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->index(['sent_at', 'cancelled_at', 'available_at'], 'spm_delivery_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('status_page_maintenance_deliveries', function (Blueprint $table): void {
            $table->dropIndex('spm_delivery_pending_index');
            $table->dropColumn(['payload', 'attempts', 'available_at', 'queued_at', 'cancelled_at']);
        });
    }
};
