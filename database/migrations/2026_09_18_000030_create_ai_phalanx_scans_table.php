<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_phalanx_scans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('moon_planet_id');
            $table->unsignedBigInteger('target_planet_id');
            // Total ships the scan saw arriving at the target. The raid decision reads
            // only whether a recent scan saw anything incoming, so one number is enough.
            $table->unsignedInteger('incoming_ship_count')->default(0);
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['target_planet_id', 'observed_at'], 'phalanx_scan_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_phalanx_scans');
    }
};
