<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The host has no diplomacy (OGame keeps wars and pacts on boards and in messages), so the stance one alliance holds
        // toward another is module memory (alliance-politics.md). One open row per pair; ended_at closes it.
        Schema::create('ai_alliance_relations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            $table->unsignedBigInteger('other_alliance_id');
            $table->unsignedTinyInteger('stance');
            // Why it began, in the module's words: "grievance:2.4", "pact:offered", "betrayal".
            $table->string('cause', 64);
            $table->unsignedBigInteger('declared_by')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['alliance_id', 'ended_at'], 'ai_alliance_relations_open');
            $table->index(['other_alliance_id', 'ended_at'], 'ai_alliance_relations_other_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_alliance_relations');
    }
};
