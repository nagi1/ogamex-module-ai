<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The account's skill quantile (skill.yaml, the simulator's arm `skill`): drawn once from its seed, null until
        // the skill spread is first switched on.
        Schema::table('ai_profiles', function (Blueprint $table): void {
            $table->double('skill_quantile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_profiles', function (Blueprint $table): void {
            $table->dropColumn('skill_quantile');
        });
    }
};
