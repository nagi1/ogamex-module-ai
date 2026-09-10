<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id')->unique();
            $table->unsignedTinyInteger('archetype');
            $table->unsignedTinyInteger('skill_band');
            $table->unsignedBigInteger('random_seed');
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_profiles');
    }
};
