<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_language_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_reply_id')->unique();
            $table->unsignedBigInteger('usage_reservation_id')->unique();
            $table->string('request_key', 128)->unique();
            $table->string('state', 16);
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('provider_request_id', 128)->nullable()->unique();
            $table->char('context_hash', 64);
            $table->string('interpretation', 32)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_milliseconds')->nullable();
            $table->timestamps();
            $table->index(['state', 'created_at'], 'ai_language_requests_state_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_language_requests');
    }
};
