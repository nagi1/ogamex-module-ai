<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One living mind per AI account (SOCIAL-ENGINES-CONTRACT.md): persona, memories and the engines' carried state
        // (FAtiMA character, AgentOS recall, PsychSim beliefs). The engines keep nothing; the state travels in each request.
        Schema::create('ai_social_minds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_social_minds');
    }
};
