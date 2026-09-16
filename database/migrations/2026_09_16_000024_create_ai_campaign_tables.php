<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('state');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_campaign_objectives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('planet_id');
            $table->timestamps();

            $table->unique(['campaign_id', 'planet_id']);
            $table->index('campaign_id');
        });

        Schema::create('ai_campaign_contributions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('player_id');
            $table->unsignedTinyInteger('kind');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->timestamps();

            $table->unique(['campaign_id', 'player_id', 'kind', 'source_type', 'source_id'], 'ai_contribution_credit_unique');
            $table->index(['campaign_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_contributions');
        Schema::dropIfExists('ai_campaign_objectives');
        Schema::dropIfExists('ai_campaigns');
    }
};
