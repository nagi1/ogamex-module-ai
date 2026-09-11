<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_relationships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('other_player_id');
            $table->decimal('trust', 5, 4)->default(0);
            $table->decimal('threat', 5, 4)->default(0);
            $table->decimal('affinity', 5, 4)->default(0);
            $table->decimal('respect', 5, 4)->default(0);
            $table->decimal('social_importance', 5, 4)->default(0);
            $table->timestamp('last_interaction_at')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->unique(['player_id', 'other_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_relationships');
    }
};
