<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_memory_facts', function (Blueprint $table): void {
            $table->timestamp('redacted_at')->nullable()->after('expires_at');
            $table->index('redacted_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_memory_facts', function (Blueprint $table): void {
            $table->dropIndex(['redacted_at']);
            $table->dropColumn('redacted_at');
        });
    }
};
