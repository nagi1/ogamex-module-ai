<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_commitments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('counterparty_player_id');
            $table->unsignedBigInteger('source_observation_id');
            $table->json('terms');
            $table->unsignedTinyInteger('state');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('fulfillment_observation_id')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->unique(['player_id', 'source_observation_id']);
            $table->index(['player_id', 'counterparty_player_id', 'state', 'due_at'], 'ai_commitments_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_commitments');
    }
};
