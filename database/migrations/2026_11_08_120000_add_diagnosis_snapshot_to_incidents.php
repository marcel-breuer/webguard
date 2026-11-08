<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->json('diagnosis_snapshot')->nullable()->after('consensus_snapshot');
            $table->timestamp('diagnosed_at')->nullable()->after('diagnosis_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn(['diagnosis_snapshot', 'diagnosed_at']);
        });
    }
};
