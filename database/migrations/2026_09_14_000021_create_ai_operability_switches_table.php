<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_operability_switches', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled');
            $table->string('reason', 255);
            $table->unsignedBigInteger('actor_player_id')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            // Appending rather than updating keeps who turned the population off, and why,
            // as a record an operator can show afterwards.
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operability_switches');
    }
};
