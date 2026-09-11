<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_affect_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedTinyInteger('emotion');
            $table->decimal('intensity', 5, 4);
            $table->timestamp('updated_for');
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->unique(['player_id', 'emotion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_affect_states');
    }
};
