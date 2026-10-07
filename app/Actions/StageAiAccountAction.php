<?php

namespace Modules\AI\Actions;

use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Training data only: gives an account the levels a player has after spending a resource budget, so a run can start in
 * the mid or late game instead of playing the early game first. It reads the host's catalogue and levels, never a
 * list of objects: the cheapest next level whose requirements the account already meets is bought until the budget is gone.
 */
class StageAiAccountAction
{
    /** Share of the planet's fields kept for the objects that add fields, as a player keeps the last ones for them. */
    private const STATION_FIELD_SHARE = 0.1;

    /** A budget this large is a late-game account, and a late-game account owns a moon by then (battle debris made it). */
    private const MOON_BUDGET = 1e10;

    public function handle(int $playerId, float $budget): int
    {
        $lateGame = $budget >= self::MOON_BUDGET;
        $player = app(PlayerServiceFactory::class)->make($playerId, true);
        $planet = $player->planets->first();
        $steps = 0;

        while (($next = $this->cheapestNextLevel($player, $planet)) !== null && $next['cost'] <= $budget) {
            $budget -= $next['cost'];
            $this->apply($player, $planet, $next['machine_name'], $next['level']);
            $steps++;
        }

        // The account stopped with full stores, as a player who has just saved up does; a staged planet with an empty one waits
        // days before it can afford its first late-game level.
        $planet->updateResourceStorageStats(false);
        $planet->addResources(new Resources($planet->metalStorage()->get(), $planet->crystalStorage()->get(), $planet->deuteriumStorage()->get()), false);
        $planet->save();

        if ($lateGame) {
            app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20, 15);
        }

        return $steps;
    }

    /**
     * @return array{machine_name: string, level: int, cost: float}|null
     */
    private function cheapestNextLevel(PlayerService $player, PlanetService $planet): array|null
    {
        $best = null;
        $fieldsFree = $planet->getPlanetFieldMax() - $planet->getBuildingCount();
        $reserve = (int) ceil($planet->getPlanetFieldMax() * self::STATION_FIELD_SHARE);
        $owed = $this->owedToFieldRaisers($planet);

        foreach (ObjectService::getObjects() as $object) {
            $isResearch = $object->type === GameObjectType::Research;
            if (!$isResearch && $object->type !== GameObjectType::Building && $object->type !== GameObjectType::Station) {
                continue;
            }

            $level = $isResearch ? $player->getResearchLevel($object->machine_name) : $planet->getObjectLevel($object->machine_name);
            $fieldsNeeded = ($owed[$object->machine_name] ?? 0) > $level ? 1 : $reserve + 1;
            if ((!$isResearch && $fieldsFree < $fieldsNeeded) || !ObjectService::objectValidPlanetType($object->machine_name, $planet)
                || !ObjectService::objectRequirementsWithLevelsMet($object->machine_name, $level + 1, $planet)) {
                continue;
            }

            $price = ObjectService::getObjectRawPrice($object->machine_name, $level + 1);
            $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get() + $price->energy->get();
            if ($best === null || $cost < $best['cost']) {
                $best = ['machine_name' => $object->machine_name, 'level' => $level + 1, 'cost' => $cost];
            }
        }

        return $best;
    }

    /**
     * The levels the objects that add fields still wait on, themselves included: the reserve is theirs.
     *
     * @return array<string, int>
     */
    private function owedToFieldRaisers(PlanetService $planet): array
    {
        $owed = [];
        foreach (ObjectService::getObjects() as $object) {
            if (!in_array($object->type, [GameObjectType::Building, GameObjectType::Station], true)
                || !$this->raisesFields($planet, $object->id, $planet->getObjectLevel($object->machine_name))) {
                continue;
            }

            $owed[$object->machine_name] = PHP_INT_MAX;
            foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
                $owed[$machineName] = max($owed[$machineName] ?? 0, $level);
            }
        }

        return $owed;
    }

    /** Whether one more level adds fields, asked of the host's own field formula rather than by name. */
    private function raisesFields(PlanetService $planet, int $objectId, int $level): bool
    {
        $before = $planet->getPlanetFieldMax();
        $planet->setObjectLevel($objectId, $level + 1, false);
        $after = $planet->getPlanetFieldMax();
        $planet->setObjectLevel($objectId, $level, false);

        return $after > $before;
    }

    private function apply(PlayerService $player, PlanetService $planet, string $machineName, int $level): void
    {
        $object = ObjectService::getObjectByMachineName($machineName);
        if ($object->type === GameObjectType::Research) {
            $player->setResearchLevel($machineName, $level);

            return;
        }

        $planet->setObjectLevel($object->id, $level, false);
    }
}
