<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('faction_momentum')->default(0)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table): void {
            $table->dropColumn('faction_momentum');
        });
    }
};
