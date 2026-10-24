<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitorings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('check_interval_minutes')->nullable()->after('timeout');
        });

        DB::table('monitorings')
            ->whereIn('type', ['http', 'keyword'])
            ->update(['check_interval_minutes' => 15]);
    }

    public function down(): void
    {
        Schema::table('monitorings', function (Blueprint $table): void {
            $table->dropColumn('check_interval_minutes');
        });
    }
};
