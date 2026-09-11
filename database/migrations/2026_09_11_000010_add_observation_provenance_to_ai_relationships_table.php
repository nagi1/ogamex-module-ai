<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_relationships', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_observation_id')->nullable()->after('last_interaction_at');
            $table->index('last_observation_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_relationships', function (Blueprint $table): void {
            $table->dropIndex(['last_observation_id']);
            $table->dropColumn('last_observation_id');
        });
    }
};
