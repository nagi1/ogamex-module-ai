<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Each alliance's asabiyya (diplomacy.rs arm `cycles`): cohesion read from its public wars, 1 at founding, and when
        // a nomad band last rose against it. A missing row is a club at full cohesion.
        Schema::create('ai_alliance_cohesions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->unique();
            $table->double('asabiyya')->default(1.0);
            $table->timestamp('risen_against_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_alliance_cohesions');
    }
};
