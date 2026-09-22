<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_operation_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 64);
            $table->unsignedBigInteger('actor_player_id')->nullable();
            $table->string('status', 16);
            $table->text('result')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The panel reads the newest runs only, so an index on the append order keeps that
            // one read bounded.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operation_logs');
    }
};
