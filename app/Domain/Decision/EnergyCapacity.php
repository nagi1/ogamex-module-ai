<?php

namespace Modules\AI\Domain\Decision;

use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * The capacity a planet wants before its next production upgrade outdraws it.
 *
 * A player builds the plant before the mine that would run the planet short, not after the mines have
 * already stalled: the host multiplies a planet's whole production by the ratio of the energy it can
 * cover, so a planet in deficit mines at a fraction of what its mines say and everything it builds
 * afterwards is worth less. That is why the canonical opening in every guide starts with the solar
 * plant rather than with a mine, and why this asks about the *next* level instead of about the balance
 * as it stands.
 *
 * Which buildings can answer it is the host's decision, not a list of names: the candidates are the
 * objects the host itself reports as producing, filtered to those the building queue accepts whose next
 * level puts energy out. Whatever an extension adds to that set is offered here the moment the host
 * knows about it. The cheapest capacity is tried first, which is why a player builds a solar plant long
 * before a fusion reactor.
 *
 * Only capacity is produced here. When the account cannot pay for it yet the plan falls through to
 * whatever else the host accepts, and the account keeps playing -- which is what a player does while
 * saving.
 */
class EnergyCapacity
{
    /** @return list<BuildCandidate> the cheapest capacity, or nothing when the planet is not short */
    public function pending(PlanetService $planet): array
    {
        if ($this->shortfall($planet) <= 0.0) {
            return [];
        }

        $candidates = [];

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            if (!BuildingQueueObject::accepts($object->machine_name)) {
                continue;
            }

            if ($this->energyGainOfNextLevel($planet, $object->machine_name) <= 0) {
                continue;
            }

            $candidates[] = [
                'price' => ObjectService::getObjectPrice($object->machine_name, $planet)->sum(),
                'candidate' => app()->makeWith(BuildCandidate::class, [
                    'buildingId' => $object->id,
                    'reason' => 'energy:' . $object->machine_name,
                ]),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => $left['price'] <=> $right['price']);

        return array_map(static fn (array $entry): BuildCandidate => $entry['candidate'], $candidates);
    }

    /**
     * How much power the planet wants: the deficit it is already in, or the one its next production
     * upgrade would create, whichever is larger. Zero when the balance covers the next step.
     *
     * The magnitude is public because a planet that cannot buy the capacity has one other route --
     * the yard -- and how many power units that route needs is this question's answer, not a second
     * calculation over the same host numbers.
     */
    public function shortfall(PlanetService $planet): float
    {
        $energy = (float) $planet->energy()->get();
        $deficit = -$energy;

        // A consumer is any production object whose next level draws more than it makes, so the
        // planet is short when one of those upgrades would land it below zero.
        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            $gain = $this->energyGainOfNextLevel($planet, $object->machine_name);

            if ($gain < 0.0) {
                $deficit = max($deficit, -($energy + $gain));
            }
        }

        return max(0.0, $deficit);
    }

    /**
     * What the host's own production calculation says the next level adds to the energy balance.
     *
     * The host scales a building's reported output by the planet's current energy factor, so a planet
     * already in deficit reports nothing at all for the very plant that would fix it. Comparing raw
     * output is what makes the question answerable, so both levels are asked for at 100%.
     */
    private function energyGainOfNextLevel(PlanetService $planet, string $machineName): float
    {
        $level = $planet->getObjectLevel($machineName);

        return (float) $planet->getObjectProduction($machineName, $level + 1, true)->energy->get()
            - (float) $planet->getObjectProduction($machineName, $level, true)->energy->get();
    }
}
