<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_conversation_replies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('counterparty_player_id');
            $table->unsignedBigInteger('source_first_message_id');
            $table->unsignedBigInteger('source_last_message_id');
            $table->text('message');
            $table->unsignedTinyInteger('state');
            $table->string('delivery_key', 128)->nullable()->unique();
            $table->unsignedBigInteger('delivered_chat_message_id')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->index(['player_id', 'counterparty_player_id', 'state'], 'ai_conversation_replies_pending_index');
            $table->index(['state', 'expires_at'], 'ai_conversation_replies_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_replies');
    }
};
