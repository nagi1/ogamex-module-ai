<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_memory_facts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('subject_player_id');
            $table->unsignedBigInteger('source_observation_id');
            $table->unsignedTinyInteger('predicate');
            $table->unsignedTinyInteger('evidence_kind');
            $table->unsignedBigInteger('speaker_player_id')->nullable();
            $table->json('value');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->unique(['player_id', 'source_observation_id', 'predicate']);
            $table->index(['player_id', 'subject_player_id', 'predicate', 'valid_from'], 'ai_memory_facts_current_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memory_facts');
    }
};
