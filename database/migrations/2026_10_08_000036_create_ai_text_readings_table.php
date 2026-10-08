<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One classifier reading per purpose and text: the cache that keeps a repeated text free, and the labelled set
        // a small in-process model is later distilled from (llm-classifier-everywhere.md, the distillation ladder).
        Schema::create('ai_text_readings', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 48);
            $table->char('text_hash', 64);
            $table->text('text');
            $table->json('answers');
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->timestamps();

            $table->unique(['purpose', 'text_hash'], 'ai_text_readings_purpose_text');
            $table->index('created_at', 'ai_text_readings_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_text_readings');
    }
};
