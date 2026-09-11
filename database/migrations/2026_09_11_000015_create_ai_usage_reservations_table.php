<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('scope');
            $table->string('scope_key', 128);
            $table->date('reserved_for');
            $table->unsignedInteger('reserved_attempts');
            $table->unsignedInteger('reserved_input_tokens');
            $table->unsignedInteger('reserved_output_tokens');
            $table->timestamps();
            $table->unique(['scope', 'scope_key', 'reserved_for'], 'ai_usage_budgets_scope_day_unique');
        });

        Schema::create('ai_usage_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('universe_scope', 64);
            $table->unsignedBigInteger('player_id');
            $table->string('conversation_key', 128);
            $table->string('request_key', 128);
            $table->date('reserved_for');
            $table->unsignedInteger('reserved_input_tokens');
            $table->unsignedInteger('reserved_output_tokens');
            $table->unsignedInteger('actual_input_tokens')->nullable();
            $table->unsignedInteger('actual_output_tokens')->nullable();
            $table->unsignedTinyInteger('state');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique('request_key', 'ai_usage_reservations_request_key_unique');
            $table->index(['universe_scope', 'player_id', 'conversation_key', 'reserved_for'], 'ai_usage_reservations_scope_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_reservations');
        Schema::dropIfExists('ai_usage_budgets');
    }
};
