<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_profiles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('activity_band')->nullable()->after('skill_band');
            $table->unsignedTinyInteger('defense_doctrine')->nullable()->after('activity_band');
            $table->unsignedTinyInteger('stockpile_strategy')->nullable()->after('defense_doctrine');
            $table->unsignedTinyInteger('economic_role')->nullable()->after('stockpile_strategy');
            $table->unsignedInteger('persona_version')->default(1)->after('economic_role');
        });

        $this->backfill();
    }

    /**
     * One deterministic default per archetype for the rows that already exist, matching the modal
     * choice AiPersonaFactory makes for a new profile of the same archetype. Values are the enum
     * ints, kept as raw literals so the migration never depends on module enum classes:
     * activity (Casual=1, Regular=2, Active=3, Hardcore=4),
     * defense (Minimalist=1, ProductionShell=2, FodderHeavy=3, RocketPlasma=4, BalancedMixed=5,
     *          HeavyMixed=6, Bunker=7, Adaptive=8),
     * stockpile (ImmediateSpender=1, ScheduledSpender=2, GoalSaver=3, FleetSaveBanker=4,
     *            BunkerBanker=5, CarelessHoarder=6),
     * economy (SelfSufficient=1, DeutSeller=2, DeutBuyer=3, ActiveTrader=4, AllianceSupplier=5).
     */
    private function backfill(): void
    {
        $defaults = [
            // archetype => [activity_band, defense_doctrine, stockpile_strategy, economic_role]
            1 => [2, 2, 2, 1], // Miner   -> Regular, ProductionShell, ScheduledSpender, SelfSufficient
            2 => [2, 7, 5, 1], // Turtle  -> Regular, Bunker, BunkerBanker, SelfSufficient
            3 => [3, 1, 4, 1], // Fleeter -> Active, Minimalist, FleetSaveBanker, SelfSufficient
            4 => [2, 1, 2, 4], // Trader  -> Regular, Minimalist, ScheduledSpender, ActiveTrader
            5 => [1, 1, 1, 1], // Casual  -> Casual, Minimalist, ImmediateSpender, SelfSufficient
        ];

        foreach ($defaults as $archetype => $values) {
            DB::table('ai_profiles')->where('archetype', $archetype)->update([
                'activity_band' => $values[0],
                'defense_doctrine' => $values[1],
                'stockpile_strategy' => $values[2],
                'economic_role' => $values[3],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_profiles', function (Blueprint $table): void {
            $table->dropColumn(['activity_band', 'defense_doctrine', 'stockpile_strategy', 'economic_role', 'persona_version']);
        });
    }
};
