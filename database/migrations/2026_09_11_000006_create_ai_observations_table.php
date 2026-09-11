<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedTinyInteger('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedTinyInteger('kind');
            $table->unsignedBigInteger('subject_player_id')->nullable();
            $table->timestamp('source_time');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['player_id', 'source_type', 'source_id']);
            $table->index(['player_id', 'source_time']);
            $table->index(['player_id', 'subject_player_id', 'source_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_observations');
    }
};
