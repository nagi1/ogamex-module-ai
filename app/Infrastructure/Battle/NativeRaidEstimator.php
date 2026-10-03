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
    private function screen(int $playerId, int $originPlanetId, int|PlanetService $target, ?UnitCollection $fleet, int $seed, int $samples): RaidEstimate
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

        $attacker = new AttackerFleet();
        $attacker->units = $ships;
        $attacker->player = $player;
        $attacker->fleetMissionId = 0;
        $attacker->ownerId = $playerId;
        $attacker->cargoResources = new Resources();
        $attacker->isInitiator = true;
        $attacker->fleetMission = null;

        $defender = DefenderFleet::fromPlanet($target);

        // The Rust engine is the one the universe fights with, so it is the only one the estimate runs.
        $engine = new RustBattleEngine([$attacker], $target, [$defender], $this->settings);

        // The screen is the one bounded pass the decision path may afford; the
        // confirmation is a wider sample on the winner only.
        $sampled = $this->sample($engine, $seed, $samples);

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
    private function sample(BattleEngine $engine, int $seed, int $samples): array
    {
        $netProfits = [];
        $loots = [];
        $debris = [];
        $survived = 0;

        for ($i = 0; $i < $samples; $i++) {
            // One shared stream: seed + i, so a candidate is always compared
            // through the same sequence of draws.
            $result = $engine->simulateBattle($seed + $i, true);

            $loots[] = $this->metalEquivalent($result->loot);
            $debris[] = isset($result->debris) ? $this->metalEquivalent($result->debris) : 0.0;
            $netProfits[] = $loots[$i] - $this->metalEquivalent($result->attackerResourceLoss);

            // getAmount(), not ->units === []: the round sanitizer keeps
            // zero-amount entries, so a wiped fleet reads as an empty amount.
            $survived += $result->attackerUnitsResult->getAmount() > 0 ? 1 : 0;
        }

        return ['netProfits' => $netProfits, 'loots' => $loots, 'debris' => $debris, 'survived' => $survived];
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
