<?php

namespace Modules\AI\Domain\Decision;

use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Calculations\CalculationType;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * The building this planet still needs before the next thing the account wants to produce is
 * possible at all.
 *
 * A seeded account owns nothing, so without this the account grows resources forever and never owns
 * a laboratory or a shipyard -- which is how six of its capabilities became permanently unreachable.
 * The chain is what connects the account's ambitions to the buildings that gate them.
 *
 * Nothing here names an object. The ambitions are whatever the host offers as research or as a unit,
 * and the requirements are the host's own recursive requirement graph, so a module or an expansion
 * that adds a ship, a technology or a building is part of the plan the moment the host knows about
 * it -- and a host that changes a prerequisite changes this plan with no edit here.
 *
 * The module's only policy is the order, and it is the level the host asks for: the easiest unlock
 * first. A player picks up the small prerequisites as they go, and ordering by the requirement
 * rather than by the building is what keeps the plan from rushing the largest shipyard in the game
 * while the account still has no laboratory. Only the level the host asks for counts, because level
 * one of a robotics factory never unlocks a shipyard and treating "something is built" as done would
 * stall the chain one step below its goal.
 *
 * The account works on one ambition at a time: the cheapest thing it cannot yet produce. Satisfying
 * the union of every ambition's prerequisites instead is a ladder with no top -- the catalogue always
 * names one more deep unlock, so the chain never finishes, the economy never gets a turn, and the
 * account ends the day with a level-seven shipyard standing over level-three mines. A player picks a
 * goal, stands what it needs, and goes back to mining; the next goal gets its turn on a later
 * session, and the economy ranks in between.
 *
 * A step the planet cannot pay for because it produces none of a resource the step costs is blocked
 * the same way a missing level is, so the producer of that resource is a chain step too: a player
 * mines what they are short of, and the synthesizer stands before the robotics factory exactly the
 * way the laboratory does.
 *
 * A step is whatever queue accepts it: the host's catalogue holds buildings and technologies beside
 * each other, and a technology gating a laboratory is as much a prerequisite as the laboratory
 * itself. Which queue takes the step is the planner's question, answered from the host's object type.
 */
class FacilityChain
{
    /** The resources a planet mines; energy is absent because capacity has its own rule ahead of the chain. */
    private const MINED_RESOURCES = ['metal', 'crystal', 'deuterium'];

    /** @return list<BuildCandidate> the unmet prerequisites of the one ambition in hand, easiest unlock first */
    public function pending(PlanetService $planet): array
    {
        $ordered = [];
        $producers = [];

        $ambition = $this->nextAmbition($planet);
        if ($ambition !== null) {
            foreach (ObjectService::getRecursiveRequirements($ambition->machine_name) as $machineName => $level) {
                $this->addRequirement($planet, $machineName, $level, $ordered, $producers);
            }
        }

        // Capability research (R2): a research a host mission waits on is a step in its own right,
        // whether or not any unit needs it. The chain is the module's only research source, so
        // without this a leaf technology that unlocks a mission -- astrophysics for colonise and
        // expedition -- is never reached, and that mission's whole capability stays unreachable
        // however long the account plays. The technology is never named here: it comes from the
        // mission's own answer, so a mission a mod adds is climbed once the host knows it.
        foreach ($this->missionRequiredResearch() as $machineName => $level) {
            $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'capability');

            foreach (ObjectService::getRecursiveRequirements($machineName) as $prerequisite => $prerequisiteLevel) {
                $this->addRequirement($planet, $prerequisite, $prerequisiteLevel, $ordered, $producers);
            }
        }

        // SP8: a slot-bound account — zero free fleet slots — reaches the technology that
        // raises the ceiling, never named here (R11 publishes the object behind the value).
        foreach ($this->fleetSlotCeilingResearch($planet) as $machineName => $level) {
            $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'slot-ceiling');
        }

        // A prerequisite requested at several levels appears once per level, so the account climbs
        // to the next one it is missing. Unmet host prerequisites come first, then facilities before
        // research: an ordinary player stands the factory before chasing the technology or yard it
        // enables.
        usort($ordered, function (array $left, array $right) use ($planet): int {
            $leftObject = ObjectService::getObjectById($left['candidate']->buildingId);
            $rightObject = ObjectService::getObjectById($right['candidate']->buildingId);
            $dependencies = $this->unmetDependencyCount($leftObject->machine_name, $planet)
                <=> $this->unmetDependencyCount($rightObject->machine_name, $planet);
            if ($dependencies !== 0) {
                return $dependencies;
            }

            $type = ((int) ($leftObject->type === GameObjectType::Research))
                <=> ((int) ($rightObject->type === GameObjectType::Research));
            if ($type !== 0) {
                return $type;
            }

            return [$left['level'], ObjectService::getObjectPrice($leftObject->machine_name, $planet)->sum()]
                <=> [$right['level'], ObjectService::getObjectPrice($rightObject->machine_name, $planet)->sum()];
        });

        // The producers come first: a step the planet cannot pay for is unreachable until its
        // producer stands, so the producer is the easier unlock by definition.
        return [...$this->producerSteps($producers, $planet), ...array_map(static fn (array $entry): BuildCandidate => $entry['candidate'], $ordered)];
    }

    /**
     * The research every host mission waits on, merged: machine name => the highest level any mission
     * asks for.
     *
     * The module names no technology. It asks the host's own mission catalogue what each mission
     * requires, so a mission a mod or an expansion adds makes its technology a chain step the moment
     * the host knows about it (gate 1). This is what makes a capability the account cannot yet run
     * -- colonise, expedition -- reachable at all: the research gate is a step like any other.
     *
     * @return array<string, int>
     */
    private function missionRequiredResearch(): array
    {
        $required = [];

        foreach (GameMissionFactory::getAllMissions() as $mission) {
            foreach ($mission::getRequiredResearch() as $machineName => $level) {
                $required[$machineName] = max($required[$machineName] ?? 0, $level);
            }
        }

        return $required;
    }

    /**
     * The technology that raises the fleet-slot ceiling, as a chain step only when the account is
     * slot-bound (zero free slots). The object is never named: it is the one the host publishes as
     * carrying `MAX_FLEET_SLOTS` (host obligation R11).
     *
     * @return array<string, int>
     */
    private function fleetSlotCeilingResearch(PlanetService $planet): array
    {
        $player = $planet->getPlayer();

        if ($player === null || $player->getFleetSlotsMax() - $player->getFleetSlotsInUse() > 0) {
            return [];
        }

        $ceiling = ObjectService::getObjectByCalculationType(CalculationType::MAX_FLEET_SLOTS);

        return $ceiling === null ? [] : [$ceiling->machine_name => 1];
    }

    /**
     * Add one unmet requirement as a chain step, and remember the producer of a resource the step
     * cannot pay for.
     *
     * The same prerequisite can be named twice -- by the ambition in hand and by a mission's
     * technology -- so the list is keyed by machine name and keeps the highest level asked for, and a
     * step is never offered twice.
     *
     * @param array<string, array{level: int, candidate: BuildCandidate}> $ordered
     * @param array<string, GameObject> $producers
     */
    private function addRequirement(PlanetService $planet, string $machineName, int $level, array &$ordered, array &$producers, string $reason = 'chain'): void
    {
        if ($this->currentLevel($planet, $machineName) >= $level) {
            return;
        }

        if (($ordered[$machineName]['level'] ?? 0) >= $level) {
            return;
        }

        $ordered[$machineName] = ['level' => $level, 'candidate' => app()->makeWith(BuildCandidate::class, [
            'buildingId' => ObjectService::getObjectByMachineName($machineName)->id,
            'reason' => $reason . ':' . $machineName,
        ])];

        foreach ($this->producersOfShortResources($machineName, $planet) as $object) {
            $producers[$object->machine_name] = $object;
        }
    }

    /**
     * The cheapest thing this account cannot yet produce, or null once it can produce everything.
     *
     * The host's own catalogue ordered cheapest-first is the goal list, so the goal is the easiest
     * unlock still available and no object is named here. An ambition whose prerequisites all stand
     * is not a goal -- there is nothing left to build towards -- so it is skipped rather than
     * rebuilt, which is what lets the chain finish instead of asking for the same shipyard forever.
     */
    private function nextAmbition(PlanetService $planet): ?GameObject
    {
        foreach ($this->ambitions() as $ambition) {
            foreach (ObjectService::getRecursiveRequirements($ambition->machine_name) as $machineName => $level) {
                if ($this->currentLevel($planet, $machineName) < $level) {
                    return $ambition;
                }
            }
        }

        return null;
    }

    private function unmetDependencyCount(string $machineName, PlanetService $planet): int
    {
        $missing = 0;

        foreach (ObjectService::getRecursiveRequirements($machineName) as $requiredName => $requiredLevel) {
            if ($this->currentLevel($planet, $requiredName) < $requiredLevel) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * The producers of the resources a step costs that this planet has no income of.
     *
     * A step short on a resource the planet cannot make can never be paid for, however the economy
     * ranking amortises it, so the producer of that resource is as much a prerequisite as a missing
     * level is. A resource with income is not short: the account is merely saving up, and waiting
     * for income is what a player does then.
     *
     * @return list<GameObject>
     */
    private function producersOfShortResources(string $machineName, PlanetService $planet): array
    {
        $price = ObjectService::getObjectPrice($machineName, $planet);
        $producers = [];

        foreach (self::MINED_RESOURCES as $resource) {
            if ($price->{$resource}->get() <= $planet->{$resource}()->get() || $this->incomeOf($planet, $resource) > 0.0) {
                continue;
            }

            foreach ($this->producersOf($resource, $planet) as $object) {
                $producers[$object->machine_name] = $object;
            }
        }

        return array_values($producers);
    }

    private function incomeOf(PlanetService $planet, string $resource): float
    {
        return match ($resource) {
            'metal' => $planet->getMetalProductionPerHour(),
            'crystal' => $planet->getCrystalProductionPerHour(),
            'deuterium' => $planet->getDeuteriumProductionPerHour(),
            default => 0.0,
        };
    }

    /** @return list<GameObject> the objects the host itself reports as producing this resource */
    private function producersOf(string $resource, PlanetService $planet): array
    {
        $producers = [];

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            if (!BuildingQueueObject::accepts($object->machine_name)) {
                continue;
            }

            if ($planet->getObjectProduction($object->machine_name, 1, true)->{$resource}->get() <= 0.0) {
                continue;
            }

            $producers[] = $object;
        }

        return $producers;
    }

    /**
     * @param array<string, GameObject> $producers
     * @return list<BuildCandidate> cheapest first, the same convention as the capacity rule
     */
    private function producerSteps(array $producers, PlanetService $planet): array
    {
        $objects = array_values($producers);

        usort($objects, static fn (GameObject $left, GameObject $right): int =>
            ObjectService::getObjectPrice($left->machine_name, $planet)->sum()
            <=> ObjectService::getObjectPrice($right->machine_name, $planet)->sum());

        return array_map(static fn (GameObject $object): BuildCandidate => app()->makeWith(BuildCandidate::class, [
            'buildingId' => $object->id,
            'reason' => 'chain:' . $object->machine_name,
        ]), $objects);
    }

    /**
     * How far this account already is with one prerequisite.
     *
     * The host keeps a planet's levels on the planet and a technology's on the player, so asking the
     * planet for a technology answers zero every time and would offer the same step forever.
     */
    private function currentLevel(PlanetService $planet, string $machineName): int
    {
        $isResearch = ObjectService::getObjectByMachineName($machineName)->type === GameObjectType::Research;

        if (!$isResearch) {
            return $planet->getObjectLevel($machineName);
        }

        // A planet always has an owner on the host; the nullable signature is the
        // host's, so a missing player reads as level zero rather than crashing a
        // decision on data the host itself would not normally be without.
        return $planet->getPlayer()?->getResearchLevel($machineName) ?? 0;
    }

    /** @return list<GameObject> what this account could produce, cheapest first */
    private function ambitions(): array
    {
        $objects = [...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()];

        usort(
            $objects,
            static fn (GameObject $left, GameObject $right): int => $left->price->resources->sum() <=> $right->price->resources->sum(),
        );

        return $objects;
    }
}
