<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_work_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedTinyInteger('kind');
            $table->timestamp('due_at');
            $table->json('payload')->nullable();
            $table->unsignedInteger('schedule_generation')->default(1);
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedTinyInteger('state')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();

            $table->index(['state', 'due_at']);
            $table->index(['player_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_work_items');
    }
};
