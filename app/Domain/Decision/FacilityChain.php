<?php

namespace Modules\AI\Domain\Decision;

use OGame\GameObjects\Models\Abstracts\GameObject;
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
 * A step is whatever queue accepts it: the host's catalogue holds buildings and technologies beside
 * each other, and a technology gating a laboratory is as much a prerequisite as the laboratory
 * itself. Which queue takes the step is the planner's question, answered from the host's object type.
 */
class FacilityChain
{
    /** @return list<BuildCandidate> every unmet prerequisite, easiest unlock first */
    public function pending(PlanetService $planet): array
    {
        $ordered = [];

        foreach ($this->ambitions() as $ambition) {
            foreach (ObjectService::getRecursiveRequirements($ambition->machine_name) as $machineName => $level) {
                if ($this->currentLevel($planet, $machineName) >= $level) {
                    continue;
                }

                $ordered[] = ['level' => $level, 'candidate' => app()->makeWith(BuildCandidate::class, [
                    'buildingId' => ObjectService::getObjectByMachineName($machineName)->id,
                    'reason' => 'chain:' . $machineName,
                ])];
            }
        }

        // A building asked for at several levels appears once per level, so the account climbs to the
        // next one it is missing rather than being handed the highest number any ambition mentions.
        usort($ordered, static fn (array $left, array $right): int => $left['level'] <=> $right['level']);

        return array_map(static fn (array $entry): BuildCandidate => $entry['candidate'], $ordered);
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
