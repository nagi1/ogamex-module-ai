<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_campaign_consultation_signals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->string('trigger', 32);
            $table->timestamp('fired_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'consumed_at'], 'ai_campaign_signals_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_consultation_signals');
    }
};
