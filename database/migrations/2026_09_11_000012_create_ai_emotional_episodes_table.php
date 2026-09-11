<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_emotional_episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('source_observation_id');
            $table->unsignedTinyInteger('emotion');
            $table->decimal('intensity', 5, 4);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['player_id', 'source_observation_id', 'emotion'], 'ai_emotional_episodes_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_emotional_episodes');
    }
};
