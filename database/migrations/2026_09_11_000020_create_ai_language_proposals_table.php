<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_language_proposals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('language_request_id');
            $table->unsignedBigInteger('source_message_id');
            $table->string('type', 16);
            $table->string('state', 16);
            $table->string('rejection_reason', 32)->nullable();
            $table->json('terms');
            $table->timestamps();
            $table->unique(['language_request_id', 'source_message_id', 'type'], 'ai_language_proposals_request_source_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_language_proposals');
    }
};
