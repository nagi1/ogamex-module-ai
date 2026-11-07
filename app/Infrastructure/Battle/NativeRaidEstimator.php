<?php

namespace Modules\AI\Infrastructure\Battle;

use Modules\AI\Domain\Raid\RaidEstimate;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * The native raid estimator: samples the host's own battle engine as a read-only
 * question.
 *
 * It is not a second battle implementation. Every run asks
 * `simulateBattle($seed, true)` — the pure, seeded shape R1 added — on the engine
 * the host itself fights with (`SettingsService::battleEngine()`), so the engine
 * stays the authority on the rules and a "what if" never touches the world. Both
 * engines are seeded: the Rust FFI carries the seed in its input and derives one
 * stream per side per round, so a screen replays identically whichever engine the
 * universe runs. Rust is roughly 7x faster per simulation, which is why the
 * module no longer pins the PHP engine.
 *
 * The fleet is the account's own ships on the origin planet, and the defender is
 * the host's stationary picture of the target planet, so no unit, price or
 * defence figure is named here.
 */
class NativeRaidEstimator
{
    private const SCREEN_SAMPLES = 50;

    /** `players::forecast`: both sides scaled so the larger holds about this many units. */
    private const FORECAST_UNITS = 200.0;

    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private SettingsService $settings,
    ) {
    }

    public function estimate(int $playerId, int $originPlanetId, int|PlanetService $target, int $seed): RaidEstimate
    {
        return $this->screen($playerId, $originPlanetId, $target, null, $seed, self::SCREEN_SAMPLES);
    }

    /**
     * One simulation of a specific fleet against the target — the launch-subset screen (U6). The
     * fleet is what the caller counter-selected; the engine and the defender stay the host's. The
     * sample count is the caller's ladder rung: one draw for a candidate screen, the wide pass for
     * the winner's confirmation.
     */
    public function estimateFleet(int $playerId, int $originPlanetId, int|PlanetService $target, UnitCollection $fleet, int $seed, int $samples = 1): RaidEstimate
    {
        return $this->screen($playerId, $originPlanetId, $target, $fleet, $seed, $samples);
    }

    /**
     * The target is either the planet as the account's report saw it (the raid planner's case: a player
     * simulates what the report says, never the live planet), or an id, which loads the host's planet for
     * callers that judge their own bodies.
     */
    private function screen(int $playerId, int $originPlanetId, int|PlanetService $target, UnitCollection|null $fleet, int $seed, int $samples): RaidEstimate
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);
        $target = is_int($target) ? $this->planetServiceFactory->make($target, true) : $target;

        if ($target === null) {
            return app()->makeWith(RaidEstimate::class, [
                'samples' => 0,
                'p20NetProfit' => 0.0,
                'p20Loot' => 0.0,
                'pWin' => 0.0,
            ]);
        }

        $ships = $fleet ?? $origin->getShipUnits();
        if ($ships->units === []) {
            return app()->makeWith(RaidEstimate::class, [
                'samples' => 0,
                'p20NetProfit' => 0.0,
                'p20Loot' => 0.0,
                'pWin' => 0.0,
            ]);
        }

        $defender = DefenderFleet::fromPlanet($target);
        [$shrunk, $defender->units, $scale] = $this->shrink($ships, $defender->units);
        $attacker = $this->attacker($shrunk, $player, $playerId);

        // The Rust engine is the one the universe fights with, so it is the only one the estimate runs.
        $engine = new RustBattleEngine([$attacker], $target, [$defender], $this->settings);

        // The screen is the one bounded pass the decision path may afford; the
        // confirmation is a wider sample on the winner only.
        $sampled = $this->sample($engine, $seed, $samples, $scale, $scale > 1.0 ? $this->lootIfWon($ships, $player, $playerId, $target) : null);

        return app()->makeWith(RaidEstimate::class, [
            'samples' => count($sampled['netProfits']),
            'p20NetProfit' => $this->lowerQuantile($sampled['netProfits'], 0.2),
            'p20Loot' => $this->lowerQuantile($sampled['loots'], 0.2),
            'pWin' => $sampled['survived'] / max(1, count($sampled['netProfits'])),
            'p20Debris' => $this->lowerQuantile($sampled['debris'], 0.2),
        ]);
    }

    /**
     * One bounded screen: the same seed + i stream yields the P20 net (survival),
     * the P20 loot (worth-flying) and the survived-run count (fleet survival)
     * from identical draws — never a second pass.
     *
     * @return array{netProfits: list<float>, loots: list<float>, debris: list<float>, survived: int}
     */
    private function sample(BattleEngine $engine, int $seed, int $samples, float $scale, float|null $lootIfWon): array
    {
        $netProfits = [];
        $loots = [];
        $debris = [];
        $survived = 0;

        for ($i = 0; $i < $samples; $i++) {
            // One shared stream: seed + i, so a candidate is always compared
            // through the same sequence of draws.
            $result = $engine->simulateBattle($seed + $i, true);

            // A shrunk fight carries a shrunk hold: a win takes what the whole fleet would carry home.
            $loots[] = $lootIfWon === null ? $this->metalEquivalent($result->loot) : ($result->loot->sum() > 0 ? $lootIfWon : 0.0);
            $debris[] = isset($result->debris) ? $scale * $this->metalEquivalent($result->debris) : 0.0;
            $netProfits[] = $loots[$i] - $scale * $this->metalEquivalent($result->attackerResourceLoss);

            // getAmount(), not ->units === []: the round sanitizer keeps
            // zero-amount entries, so a wiped fleet reads as an empty amount.
            $survived += $result->attackerUnitsResult->getAmount() > 0 ? 1 : 0;
        }

        return ['netProfits' => $netProfits, 'loots' => $loots, 'debris' => $debris, 'survived' => $survived];
    }

    /**
     * Both sides scaled down so the larger holds about FORECAST_UNITS units (at least one of each), and the factor that
     * scales losses and debris back: the engine's cost grows with the unit count, and a late-game stack of millions
     * would hold a login inside one native call for minutes.
     *
     * @return array{0: UnitCollection, 1: UnitCollection, 2: float}
     */
    public function shrink(UnitCollection $attacking, UnitCollection $defending): array
    {
        $scale = max(1.0, max($attacking->getAmount(), $defending->getAmount()) / self::FORECAST_UNITS);

        return [$this->shrunk($attacking, $scale), $this->shrunk($defending, $scale), $scale];
    }

    private function shrunk(UnitCollection $units, float $scale): UnitCollection
    {
        $out = new UnitCollection();
        foreach ($units->units as $entry) {
            if ($entry->amount > 0) {
                $out->addUnit($entry->unitObject, (int) ceil($entry->amount / $scale));
            }
        }

        return $out;
    }

    /**
     * The whole fleet's haul from a won fight, asked of the host's engine against an empty defence: the loot share and
     * the hold are the host's rules, not restated here.
     */
    private function lootIfWon(UnitCollection $ships, PlayerService $player, int $playerId, PlanetService $target): float
    {
        $defender = DefenderFleet::fromPlanet($target);
        $defender->units = new UnitCollection();

        return $this->metalEquivalent((new RustBattleEngine([$this->attacker($ships, $player, $playerId)], $target, [$defender], $this->settings))->simulateBattle(0, true)->loot);
    }

    private function attacker(UnitCollection $ships, PlayerService $player, int $playerId): AttackerFleet
    {
        $attacker = new AttackerFleet();
        $attacker->units = $ships;
        $attacker->player = $player;
        $attacker->fleetMissionId = 0;
        $attacker->ownerId = $playerId;
        $attacker->cargoResources = new Resources();
        $attacker->isInitiator = true;
        $attacker->fleetMission = null;

        return $attacker;
    }

    /**
     * @param list<float> $values
     */
    private function lowerQuantile(array $values, float $quantile): float
    {
        sort($values);
        $rank = (int) ceil($quantile * count($values)) - 1;

        return $values[max(0, min($rank, count($values) - 1))];
    }

    public function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }
}
