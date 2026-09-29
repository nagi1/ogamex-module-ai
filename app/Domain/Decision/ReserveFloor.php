<?php

namespace Modules\AI\Domain\Decision;

use OGame\Models\Resources;
use OGame\Services\PlanetService;

/**
 * The per-resource floor a planet must keep after a spend, so one purchase cannot starve the next.
 *
 * A build that consumes the last metal stops the next save, the next commitment and the next research
 * step, so a player reserves first: keep a small fraction of what the planet holds and let the
 * production that arrives while saving refill it (SP5). The floor is the buffer reduced by that
 * production, and it is never negative. Each resource's floor guards that resource alone, so a planet
 * saving deuterium for a drive is not frozen out of spending its surplus metal and crystal: a purchase
 * that spends no deuterium must keep no deuterium floor. The buffer, the economy horizon and the
 * research horizon are the published defaults the corpus documents; none of them names a game object,
 * so nothing here encodes the host's universe.
 */
class ReserveFloor
{
    /** Keep this fraction of what the planet holds per resource (SP5, `keep_resources_buffer`). */
    public const BUFFER = 0.10;

    /** How many hours of production may refill the floor for an economy spend (SP5). */
    public const ECONOMY_HOURS = 4.0;

    /** How many hours of production may refill the floor for a research spend (SP5). */
    public const RESEARCH_HOURS = 6.0;

    /**
     * The floor that must survive a purchase, per resource, at this planet.
     *
     * The buffer is a fraction of the balance, never of the storage capacity. A fresh planet holding
     * 529 metal with room for 10,000 has almost nothing to protect, and a reserve charged on capacity
     * refuses every purchase it could ever make -- including the mine that earns the reserve back --
     * so the account stands still for good. Capping the buffer by the balance keeps the reserve real
     * for a rich planet and harmless for a poor one.
     *
     * @param float $savingHours how long the account is willing to wait for production to refill
     */
    public function floor(PlanetService $planet, float $savingHours): Resources
    {
        $held = $planet->getResources();

        return new Resources(
            $this->perResource($held->metal->get(), $planet->getMetalProductionPerHour(), $savingHours),
            $this->perResource($held->crystal->get(), $planet->getCrystalProductionPerHour(), $savingHours),
            $this->perResource($held->deuterium->get(), $planet->getDeuteriumProductionPerHour(), $savingHours),
        );
    }

    private function perResource(float $held, float $productionPerHour, float $savingHours): float
    {
        return max(0.0, $held * self::BUFFER - $productionPerHour * $savingHours);
    }
}
