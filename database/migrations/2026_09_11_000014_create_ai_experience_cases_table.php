<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_experience_cases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('outcome_observation_id');
            $table->unsignedTinyInteger('family');
            $table->unsignedTinyInteger('outcome');
            $table->string('feature_version', 32);
            $table->string('ruleset_version', 32);
            $table->json('features');
            $table->decimal('utility', 5, 4);
            $table->decimal('uncertainty', 5, 4);
            $table->timestamps();
            $table->unique(['player_id', 'outcome_observation_id', 'family'], 'ai_experience_cases_outcome_unique');
            $table->index(['player_id', 'family', 'feature_version', 'ruleset_version'], 'ai_experience_cases_recall_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_experience_cases');
    }
};
