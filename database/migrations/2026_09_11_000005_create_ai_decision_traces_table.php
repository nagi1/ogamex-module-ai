<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_decision_traces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('work_item_id')->nullable();
            $table->unsignedTinyInteger('selected_action');
            $table->string('selected_reason', 255);
            $table->json('candidates');
            $table->json('score_components');
            $table->json('source_timestamps');
            $table->string('input_hash', 64);
            $table->timestamp('observed_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['player_id', 'observed_at']);
            $table->index('work_item_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decision_traces');
    }
};
