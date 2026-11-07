<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // A pact cancelled by notice (DIPLOMACY-BETRAYAL P1) still binds until notice_ends_at; then it closes and the
        // alliance that cancelled is at war with the other.
        Schema::table('ai_alliance_relations', function (Blueprint $table): void {
            $table->timestamp('notice_ends_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_alliance_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_alliance_relations', function (Blueprint $table): void {
            $table->dropColumn(['notice_ends_at', 'cancelled_by_alliance_id']);
        });
    }
};
