<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_advisor_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->string('area', 16);
            $table->string('moment', 64);
            $table->string('mode', 16);
            $table->string('rule_choice', 64);
            $table->string('advice', 64)->nullable();
            $table->boolean('applied')->default(false);
            $table->string('status', 16);
            $table->string('reason', 255)->nullable();
            $table->json('evidence_ids');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 16, 8)->nullable();
            $table->unsignedBigInteger('usage_reservation_id')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'area', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_advisor_receipts');
    }
};
