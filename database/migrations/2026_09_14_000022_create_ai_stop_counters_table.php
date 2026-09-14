<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_stop_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('reason', 32);
            $table->string('scope', 64);
            $table->date('observed_on');
            // A day of counters is a handful of rows per reason, so the pilot report can read
            // a whole window without scanning an event log that grows with the population.
            $table->unsignedInteger('occurrences')->default(0);
            $table->json('last_context')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['reason', 'scope', 'observed_on'], 'ai_stop_counters_reason_scope_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_stop_counters');
    }
};
