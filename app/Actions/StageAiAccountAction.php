<?php

namespace Modules\AI\Actions;

use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
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

    /** Dark matter a veteran holds by then (expeditions, a few purchases): enough for an officer or two above the reserve. */
    private const LATE_GAME_DARK_MATTER = 60_000;

    /** Share of a late-game budget each moon has had spent on it: a veteran's moons carry their base, phalanx and gate. */
    private const MOON_SHARE = 0.01;

    public function handle(int $playerId, float $budget): int
    {
        $lateGame = $budget >= self::MOON_BUDGET;
        if ($lateGame) {
            User::query()->whereKey($playerId)->where('dark_matter', '<', self::LATE_GAME_DARK_MATTER)->update(['dark_matter' => self::LATE_GAME_DARK_MATTER]);
        }
        $player = app(PlayerServiceFactory::class)->make($playerId, true);
        $planet = $player->planets->first();
        $moonBudget = $lateGame ? $budget * self::MOON_SHARE : 0.0;
        $steps = $this->spend($player, $planet, $budget - 2 * $moonBudget);

        // The account stopped with full stores, as a player who has just saved up does; a staged planet with an empty one waits
        // days before it can afford its first late-game level.
        $planet->updateResourceStorageStats(false);
        $planet->addResources(new Resources($planet->metalStorage()->get(), $planet->crystalStorage()->get(), $planet->deuteriumStorage()->get()), false);
        $planet->save();

        if ($lateGame) {
            app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20, 15);
            $this->colonyWithMoon($player, $planet);
            // Every moon started bare while its planets stood at seventeen robotics, and a gate on a bare moon is thirty hours
            // of building at speed 8: no day-long late world ever saw a jump (9393-9412).
            foreach (app(PlayerServiceFactory::class)->make($player->getId(), true)->planets->allMoons() as $moon) {
                $steps += $this->spend($player, $moon, $moonBudget);
            }
        }

        return $steps;
    }

    /** Buys the cheapest next level on this body until the budget is gone; the number of levels bought. */
    private function spend(PlayerService $player, PlanetService $body, float $budget): int
    {
        $steps = 0;
        while (($next = $this->cheapestNextLevel($player, $body)) !== null && $next['cost'] <= $budget) {
            $budget -= $next['cost'];
            $this->apply($player, $body, $next['machine_name'], $next['level']);
            $steps++;
        }
        $body->save();

        return $steps;
    }

    /**
     * A late-game account holds a second moon on a colony beside its home, so the jump gate (which joins two moons)
     * is reachable at all; one moon alone left it missing in every late-game test. The first free slot of the home
     * system takes the colony, and a full system leaves the account with its one moon.
     */
    private function colonyWithMoon(PlayerService $player, PlanetService $home): void
    {
        $coordinates = $home->getPlanetCoordinates();
        foreach (range(1, 15) as $position) {
            $taken = Planet::query()->where('galaxy', $coordinates->galaxy)->where('system', $coordinates->system)->where('planet', $position)->exists();
            if ($taken) {
                continue;
            }

            $colony = app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, new Coordinate($coordinates->galaxy, $coordinates->system, $position));
            app(PlanetServiceFactory::class)->createMoonForPlanet($colony, 2_000_000, 20, 15);

            return;
        }
    }

    /**
     * @return array{machine_name: string, level: int, cost: float}|null
     */
    private function cheapestNextLevel(PlayerService $player, PlanetService $planet): array|null
    {
        $best = null;
        $fieldsFree = $planet->getPlanetFieldMax() - $planet->getBuildingCount();
        // A moon keeps the field its base needs to add three, and one more for its own stations: a tenth of its fields kept the
        // phalanx and the gate off it while cheaper robotics and store levels took the rest.
        $reserve = $planet->getPlanetType() === PlanetType::Moon ? 2 : (int) ceil($planet->getPlanetFieldMax() * self::STATION_FIELD_SHARE);
        $owed = $this->owedToFieldRaisers($planet);

        foreach (ObjectService::getObjects() as $object) {
            $isResearch = $object->type === GameObjectType::Research;
            // A moon has no laboratory: its share goes to its own buildings.
            if ($isResearch && $planet->getPlanetType() === PlanetType::Moon) {
                continue;
            }
            if (!$isResearch && $object->type !== GameObjectType::Building && $object->type !== GameObjectType::Station) {
                continue;
            }

            $level = $isResearch ? $player->getResearchLevel($object->machine_name) : $planet->getObjectLevel($object->machine_name);
            // A station only this kind of body holds is what the body is for (a moon's phalanx and gate), so it keeps only the
            // raiser's field: under the full reserve the moon bought fourteen base levels and no gate.
            $ownStation = $object->valid_planet_types === [$planet->getPlanetType()];
            // The raiser itself may take the last field, since it gives fields back; a level it still waits on keeps that last
            // field for it, or the body fills on the way (the mid-game test filled every field when the planet drew a small size).
            $fieldsNeeded = match (true) {
                ($owed[$object->machine_name] ?? 0) === PHP_INT_MAX => 1,
                ($owed[$object->machine_name] ?? 0) > $level => 2,
                $ownStation => 2,
                default => $reserve + 1,
            };
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
            // A raiser the body cannot hold owes it nothing: a moon's one field went to robotics for the terraformer.
            if (!in_array($object->type, [GameObjectType::Building, GameObjectType::Station], true)
                || !ObjectService::objectValidPlanetType($object->machine_name, $planet)
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
