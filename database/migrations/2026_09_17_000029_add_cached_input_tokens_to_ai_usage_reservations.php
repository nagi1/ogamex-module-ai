<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cached share of a settled request's input.
 *
 * The providers bill cached reads at their own `cached_input` rate and the SDK reports the count,
 * but this column is what carries it into the ledger: without it the cached input is invisible
 * (the SDK already subtracts it from `promptTokens`) and the cost is priced as if it never
 * happened. Cache *write* tokens are deliberately not plumbed: neither supported provider bills
 * them, so a column for them would be a third token category nothing charges.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_usage_reservations', function (Blueprint $table): void {
            $table->unsignedInteger('actual_cached_input_tokens')->nullable()->after('actual_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_reservations', function (Blueprint $table): void {
            $table->dropColumn('actual_cached_input_tokens');
        });
    }
};
