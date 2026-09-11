<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AiCommitmentDirection;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_social_exchanges', function (Blueprint $table): void {
            $table->unsignedTinyInteger('protocol_depth')->default(1)->after('source_observation_id');
        });

        Schema::table('ai_commitments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('direction')->default(AiCommitmentDirection::PromisedByPlayer->value)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('ai_commitments', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });

        Schema::table('ai_social_exchanges', function (Blueprint $table): void {
            $table->dropColumn('protocol_depth');
        });
    }
};
