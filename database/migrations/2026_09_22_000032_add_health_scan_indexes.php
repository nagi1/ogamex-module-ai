<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The Health tab scans these three append-only tables over a window, and the storage
        // section reads each table's oldest row. Without these indexes every read is a full table
        // scan, which on a live pilot (hundreds of thousands of rows) made the page take seconds.
        // The trailing columns make the windowed aggregations covering so the scan never leaves
        // the index.
        Schema::table('ai_action_receipts', function (Blueprint $table): void {
            $table->index(['created_at', 'state']);
            $table->index(['action_type', 'created_at', 'state']);
        });

        Schema::table('ai_decision_traces', function (Blueprint $table): void {
            $table->index(['observed_at', 'selected_reason']);
            $table->index('created_at');
        });

        Schema::table('ai_work_items', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index(['state', 'updated_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_receipts', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'state']);
            $table->dropIndex(['action_type', 'created_at', 'state']);
        });

        Schema::table('ai_decision_traces', function (Blueprint $table): void {
            $table->dropIndex(['observed_at', 'selected_reason']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('ai_work_items', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['state', 'updated_at', 'due_at']);
        });
    }
};
