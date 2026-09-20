<?php

declare(strict_types=1);

use App\Models\StatusPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignIdFor(StatusPage::class)->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('message');
            $table->boolean('notify_subscribers')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['status_page_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_announcements');
    }
};
