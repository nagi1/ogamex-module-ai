<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id')->unique();
            $table->string('timezone', 64);
            $table->timestamp('next_due_at');
            $table->timestamp('session_ends_at')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('next_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_schedules');
    }
};
