<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_social_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('counterparty_player_id');
            $table->unsignedBigInteger('source_observation_id');
            $table->unsignedTinyInteger('type');
            $table->json('terms');
            $table->unsignedTinyInteger('state');
            $table->unsignedTinyInteger('response')->nullable();
            $table->json('response_terms')->nullable();
            $table->string('response_reason')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->unique(['player_id', 'source_observation_id', 'type'], 'ai_social_exchanges_source_unique');
            $table->index(['player_id', 'counterparty_player_id', 'state', 'due_at'], 'ai_social_exchanges_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_social_exchanges');
    }
};
