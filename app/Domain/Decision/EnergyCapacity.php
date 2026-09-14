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
        if (!$this->outdrawn($planet)) {
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
     * Whether the planet is short already, or would be after the next level of something it runs.
     *
     * A consumer is any production object whose next level draws more than it makes, so the planet is
     * short when one of those upgrades would land it below zero.
     */
    private function outdrawn(PlanetService $planet): bool
    {
        $energy = (float) $planet->energy()->get();

        if ($energy < 0) {
            return true;
        }

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            $gain = $this->energyGainOfNextLevel($planet, $object->machine_name);

            if ($gain < 0 && $energy + $gain < 0) {
                return true;
            }
        }

        return false;
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
