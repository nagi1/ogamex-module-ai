<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_campaign_consultation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('campaign_id');
            $table->string('trigger');
            $table->string('status');
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->json('evidence_ids');
            $table->string('config_revision');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('usage_reservation_id')->nullable();
            $table->boolean('changed_ranking')->default(false);
            $table->string('request_key');
            $table->timestamps();

            $table->unique('request_key');
            // The per-trigger cooldown is a bounded read over one campaign's attempts.
            $table->index(['campaign_id', 'trigger', 'created_at'], 'consultation_cooldown_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_consultation_receipts');
    }
};
