<?php

namespace Modules\AI\Domain\Login;

use OGame\Services\PlanetService;
use Throwable;

/**
 * The login's blackboard of claims: ships a manager has already promised to an order this login.
 *
 * A login now places several fleet orders (raid waves, probes, a save), and each planner reads the
 * planet's whole stock. Without a claim the second raid from one origin was planned with the hulls the
 * first had already taken, and the host refused it at dispatch. A planner asks `withoutClaims()` for the
 * stock that is still free, and the orchestrator records what each order took (architecture step 3).
 * The container scopes it to one login: `reset()` is called when the login starts.
 */
class LoginReservations
{
    /** @var array<int, array<string, int>> planet id => machine name => units claimed */
    private array $units = [];

    private int $slots = 0;

    public function reset(): void
    {
        $this->units = [];
        $this->slots = 0;
    }

    /**
     * @param array<string, int> $units
     */
    public function claim(int $planetId, array $units): void
    {
        foreach ($units as $machineName => $amount) {
            $this->units[$planetId][$machineName] = ($this->units[$planetId][$machineName] ?? 0) + max(0, (int) $amount);
        }

        $this->slots++;
    }

    /** Units of the planet's ships not yet promised to an order this login. */
    public function freeShips(PlanetService $planet): int
    {
        $free = 0;
        foreach ($planet->getShipUnits()->units as $entry) {
            $free += max(0, $entry->amount - ($this->units[$planet->getPlanetId()][$entry->unitObject->machine_name] ?? 0));
        }

        return $free;
    }

    public function slotsClaimed(): int
    {
        return $this->slots;
    }

    /**
     * Takes the claimed ships out of an in-memory planet, never written: the planner then sees the stock a
     * further order may still use.
     */
    public function withoutClaims(PlanetService $planet): PlanetService
    {
        foreach ($this->units[$planet->getPlanetId()] ?? [] as $machineName => $amount) {
            $free = min($amount, $planet->getObjectAmount($machineName));
            if ($free <= 0) {
                continue;
            }

            try {
                $planet->removeUnit($machineName, $free, false);
            } catch (Throwable) {
                // A unit the planet no longer holds is simply not available.
            }
        }

        return $planet;
    }
}
