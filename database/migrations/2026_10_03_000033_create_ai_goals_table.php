<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_goals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            // A goal is a free name for an intention ("colonies", "plasma"), never an object list:
            // what reaching it means stays the planners' business.
            $table->string('goal');
            $table->unsignedInteger('target');
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('started_at');
            // A goal holds until it is met or clearly failing (hysteresis): past this it is abandoned.
            $table->timestamp('abandon_after');
            $table->timestamps();

            // One intention per account: re-committing a goal re-affirms it instead of duplicating it.
            $table->unique(['player_id', 'goal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_goals');
    }
};
