<?php

namespace Modules\AI\Domain\Decision;

use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Calculations\CalculationType;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\Enums\PlanetType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

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

    /** The moon station the account wants; module taste, never a source of truth for its requirements. */
    private const PHALANX_STATION = 'sensor_phalanx';

    /** The second moon station, only useful as a pair; module taste, requirements host-read. */
    private const JUMP_GATE_STATION = 'jump_gate';

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

        // A planet-capped account — every colony slot it has unlocked is already settled —
        // reaches the technology that raises the planet ceiling, never named here either. The
        // mission's own answer only asks for the level that first makes colonising possible, so
        // without this the account settles its one colony and stops with capacity to spare.
        foreach ($this->planetCeilingResearch($planet) as $machineName => $level) {
            $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'planet-ceiling');
        }

        // A moon the account owns wants its sensor phalanx: the station is module taste, and
        // the host's own recursive requirement graph puts the lunar base first (RV-008).
        if ($planet->getPlanetType() === PlanetType::Moon) {
            $this->addRequirement($planet, self::PHALANX_STATION, 1, $ordered, $producers, 'moon-station');

            foreach (ObjectService::getRecursiveRequirements(self::PHALANX_STATION) as $machineName => $level) {
                $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'moon-station');
            }

            // A jump gate is only a save as a pair, so it is a chain step once the account owns
            // two moons (RV-009).
            if ($this->hasTwoMoons($planet)) {
                $this->addRequirement($planet, self::JUMP_GATE_STATION, 1, $ordered, $producers, 'moon-station');

                foreach (ObjectService::getRecursiveRequirements(self::JUMP_GATE_STATION) as $machineName => $level) {
                    $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'moon-station');
                }
            }
        }

        // A planet that holds no defence at all while a sibling already stands a wall wants the
        // facilities the host's own smallest defence unit needs: the yard cannot take a defence order
        // before those stand, so the planet stays naked beside its walled sibling forever, and the
        // wall keeps growing where it already is (measured live 2 Oct 2026: planets at zero defence
        // beside a sibling holding 21,084 units). The unit is never named: it is the cheapest the
        // host's defence registry offers, and its prerequisites are the host's recursive graph.
        foreach ($this->wallPrerequisites($planet) as $machineName => $level) {
            $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'wall-prerequisite');
        }

        // A prerequisite requested at several levels appears once per level, so the account climbs
        // to the next one it is missing.
        $this->sortOrdered($ordered, $planet);

        return $this->withProducers($ordered, $producers, $planet);
    }

    /**
     * The facilities the host's own smallest defence unit needs, for the planet the rule applies to,
     * as a list of its own.
     *
     * `pending()` already carries them among the chain's steps, but a chain step is one candidate the
     * economy ranks against the mines: a planet whose stock overflows is spending, and the surplus pass
     * asked first, so the wall's prerequisites never got their turn and the planet stayed naked beside
     * its walled sibling (measured live 2 Oct 2026: 7-8 planets at zero defence while one held the
     * wall). The building planner gives this list its own pass ahead of the economy, and nothing is
     * named here: the unit is the cheapest the host registers and the steps are the host's own graph.
     *
     * @return list<BuildCandidate>
     */
    public function wallPending(PlanetService $planet): array
    {
        $ordered = [];
        $producers = [];

        foreach ($this->wallPrerequisites($planet) as $machineName => $level) {
            $this->addRequirement($planet, $machineName, $level, $ordered, $producers, 'wall-prerequisite');
        }

        $this->sortOrdered($ordered, $planet);

        return $this->withProducers($ordered, $producers, $planet);
    }

    /**
     * The chain's stated order: unmet host prerequisites first, then facilities before research
     * (an ordinary player stands the factory before chasing the technology or yard it enables), then
     * by the level asked for and the price of the step.
     *
     * @param array<string, array{level: int, candidate: BuildCandidate}> $ordered
     */
    private function sortOrdered(array &$ordered, PlanetService $planet): void
    {
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
    }

    /**
     * The final step list: the producers first, because a step the planet cannot pay for is
     * unreachable until its producer stands, then the ordered prerequisites.
     *
     * @param array<string, array{level: int, candidate: BuildCandidate}> $ordered
     * @param array<string, GameObject> $producers
     * @return list<BuildCandidate>
     */
    private function withProducers(array $ordered, array $producers, PlanetService $planet): array
    {
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
    /** @return array<string, int> */
    private function missionRequiredResearch(): array
    {
        $required = [];

        foreach (GameMissionFactory::getMissionClasses() as $mission) {
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
     * The technology that raises the planet ceiling, as a chain step only while the account holds as
     * many planets as the host allows it. The object is never named: it is the one the host publishes
     * as carrying `MAX_COLONIES`, so a mod-added technology behind that value takes the step with no
     * edit here. One level is asked for at a time -- the account climbs the technology the way it
     * stands any other prerequisite, and the step disappears the moment the host reports a free slot.
     *
     * @return array<string, int>
     */
    private function planetCeilingResearch(PlanetService $planet): array
    {
        $player = $planet->getPlayer();

        if ($player === null || $player->planets->planetCount() < $player->getMaxPlanetAmount()) {
            return [];
        }

        $ceiling = ObjectService::getObjectByCalculationType(CalculationType::MAX_COLONIES);

        return $ceiling === null ? [] : [$ceiling->machine_name => $player->getResearchLevel($ceiling->machine_name) + 1];
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
        foreach ($this->ambitions($planet) as $ambition) {
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

    /**
     * The facilities the host's own smallest defence unit requires, or none when this planet does not
     * need them.
     *
     * A planet needs them exactly when it holds no defence while a sibling of the same account
     * already stands one: that is the account past its opening, with a wall it built everywhere it
     * could reach, so the reasons the unit planner has for waiting no longer apply and the facility
     * that gates the wall is as much a prerequisite as a laboratory gating a technology.
     *
     * @return array<string, int>
     */
    private function wallPrerequisites(PlanetService $planet): array
    {
        if (! $this->nakedBesideWalled($planet)) {
            return [];
        }

        $unit = $this->smallestDefenceUnit();

        return $unit === null ? [] : ObjectService::getRecursiveRequirements($unit->machine_name);
    }

    /**
     * Whether this planet holds nothing while a sibling of the same account holds something.
     *
     * "Holds" is the unit planner's own answer -- built plus already paid for in the yard -- and not the
     * built-only count: the account has left its opening the moment its first wall order is placed, so the
     * sibling's bare neighbour is reached from that login rather than one login behind a wall that is
     * already standing in the queue (QUAL-003: the two planners disagreed about when a wall stands).
     */
    private function nakedBesideWalled(PlanetService $planet): bool
    {
        $defence = app(DefenseNeedEvaluator::class);

        if ($defence->standingUnits($planet) > 0) {
            return false;
        }

        $player = $planet->getPlayer();
        if ($player === null) {
            return false;
        }

        foreach ($player->planets->all() as $sibling) {
            if ($sibling->getPlanetId() !== $planet->getPlanetId() && $defence->standingUnits($sibling) > 0) {
                return true;
            }
        }

        return false;
    }

    /** The defence unit the host prices lowest: the smallest thing it will accept as a wall. */
    private function smallestDefenceUnit(): ?GameObject
    {
        $cheapest = null;
        $cheapestPrice = INF;

        foreach (ObjectService::getDefenseObjects() as $defence) {
            $price = ObjectService::getObjectRawPrice($defence->machine_name)->sum();
            if ($price <= 0 || $price >= $cheapestPrice) {
                continue;
            }

            $cheapest = $defence;
            $cheapestPrice = $price;
        }

        return $cheapest;
    }

    /**
     * A jump gate moves a fleet between two moons, so it is only ever useful once the account
     * owns a pair. The station is not proposed on a single moon (RV-009).
     */
    private function hasTwoMoons(PlanetService $planet): bool
    {
        $player = $planet->getPlayer();

        return $player !== null && count($player->planets->allMoons()) >= 2;
    }

    /**
     * What this account could produce, cheapest first. An account with no ship that can fly goes for a
     * hull before any technology: a player opens with mines, the robotics factory and the shipyard to
     * get a first cargo ship, and only then chases research. Without it the cheapest technologies
     * come first and the shipyard stays unbuilt, so no fleet, raid or save is ever possible.
     *
     * @return list<GameObject>
     */
    private function ambitions(PlanetService $planet): array
    {
        $objects = [...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()];
        $player = $planet->getPlayer();
        $fleetless = $player !== null && $this->ownsNoMovableShip($player);

        usort(
            $objects,
            fn (GameObject $left, GameObject $right): int => [$fleetless && ! $this->isFlyingHull($left, $player), $left->price->resources->sum()]
                <=> [$fleetless && ! $this->isFlyingHull($right, $player), $right->price->resources->sum()],
        );

        return $objects;
    }

    private function ownsNoMovableShip(PlayerService $player): bool
    {
        foreach ($player->planets->all() as $planet) {
            if (MovableFleet::of($player, $planet->getShipUnits())->units !== []) {
                return false;
            }
        }

        return true;
    }

    private function isFlyingHull(GameObject $object, PlayerService|null $player): bool
    {
        return $object instanceof UnitObject
            && $player !== null
            && $object->properties->speed->calculate($player)->totalValue > 0;
    }
}
