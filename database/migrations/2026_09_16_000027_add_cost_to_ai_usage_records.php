<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_usage_reservations', function (Blueprint $table): void {
            $table->decimal('cost', 16, 8)->nullable()->after('settled_at');
        });

        Schema::table('ai_language_requests', function (Blueprint $table): void {
            $table->decimal('cost', 16, 8)->nullable()->after('latency_milliseconds');
        });

        Schema::table('ai_campaign_consultation_receipts', function (Blueprint $table): void {
            $table->decimal('cost', 16, 8)->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_reservations', function (Blueprint $table): void {
            $table->dropColumn('cost');
        });

        Schema::table('ai_language_requests', function (Blueprint $table): void {
            $table->dropColumn('cost');
        });

        Schema::table('ai_campaign_consultation_receipts', function (Blueprint $table): void {
            $table->dropColumn('cost');
        });
    }
};
