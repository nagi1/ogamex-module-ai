<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_score_samples', function (Blueprint $table): void {
            $table->id();
            $table->integer('player_id', false, true);
            // The hour the sample describes, so a rerun of the pass inside one hour updates that
            // hour's row instead of adding a second one.
            $table->timestamp('sampled_at');
            // The host's own columns, copied rather than recomputed: the module restates no score
            // rule, and a sample is comparable with what a third party can read from the host.
            $table->bigInteger('general')->default(0);
            $table->bigInteger('economy')->default(0);
            $table->bigInteger('research')->default(0);
            $table->bigInteger('military_built')->default(0);
            $table->bigInteger('military_destroyed')->default(0);
            $table->bigInteger('military_lost')->default(0);
            $table->bigInteger('general_rank')->nullable()->default(null);
            $table->timestamps();

            $table->unique(['player_id', 'sampled_at'], 'ai_score_samples_player_hour_unique');
            // The review reads one window and the pass prunes one boundary, so the index is on the
            // only column both of them filter by.
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_score_samples');
    }
};
