<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_intel', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            // The target, as the three numbers every report and fleet mission carries: "g:s:p".
            $table->string('coordinate', 24);
            // The bounded priority counter: what this target has paid the account, minus what it cost.
            $table->integer('priority')->default(0);
            // When the counter last moved, so a read can tell a fresh memory from an old one.
            $table->timestamp('last_outcome_at')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'coordinate'], 'ai_intel_player_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_intel');
    }
};
