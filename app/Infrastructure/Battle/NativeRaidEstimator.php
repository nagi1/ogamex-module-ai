<?php

namespace Modules\AI\Infrastructure\Battle;

use Modules\AI\Domain\Raid\RaidEstimate;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\Models\Resources;
use OGame\Services\SettingsService;

/**
 * The native raid estimator: samples the host's own battle engine as a read-only
 * question.
 *
 * It is not a second battle implementation. Every run asks
 * `PhpBattleEngine::simulateBattle($seed, true)` — the pure, seeded shape R1
 * added — so the engine stays the authority on the rules and a "what if" never
 * touches the world. The PHP engine is used deliberately: the Rust FFI has its
 * own RNG and cannot be seeded yet.
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

    public function estimate(int $playerId, int $originPlanetId, int $targetPlanetId, int $seed): RaidEstimate
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);
        $target = $this->planetServiceFactory->make($targetPlanetId, true);

        if ($target === null) {
            return app()->makeWith(RaidEstimate::class, [
                'samples' => 0,
                'losingRuns' => 0,
                'p20NetProfit' => 0.0,
            ]);
        }

        $ships = $origin->getShipUnits();
        if ($ships->units === []) {
            return app()->makeWith(RaidEstimate::class, [
                'samples' => 0,
                'losingRuns' => 0,
                'p20NetProfit' => 0.0,
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

        $engine = new PhpBattleEngine([$attacker], $target, [$defender], $this->settings);

        // The screen is the one bounded pass the decision path may afford; the
        // confirmation is a wider sample on the winner only.
        $netProfits = $this->sample($engine, $seed, self::SCREEN_SAMPLES);

        return app()->makeWith(RaidEstimate::class, [
            'samples' => count($netProfits),
            'losingRuns' => count(array_filter($netProfits, static fn (float $net): bool => $net < 0.0)),
            'p20NetProfit' => $this->lowerQuantile($netProfits, 0.2),
        ]);
    }

    /**
     * @return list<float>
     */
    private function sample(PhpBattleEngine $engine, int $seed, int $samples): array
    {
        $netProfits = [];

        for ($i = 0; $i < $samples; $i++) {
            // One shared stream: seed + i, so a candidate is always compared
            // through the same sequence of draws.
            $result = $engine->simulateBattle($seed + $i, true);

            $loot = $this->metalEquivalent($result->loot);
            $loss = $this->metalEquivalent($result->attackerResourceLoss);
            $netProfits[] = $loot - $loss;
        }

        return $netProfits;
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

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }
}
