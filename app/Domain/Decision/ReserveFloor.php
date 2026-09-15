<?php

namespace Modules\AI\Domain\Decision;

use OGame\Models\Resources;
use OGame\Services\PlanetService;

/**
 * The per-resource floor a planet must keep after a spend, so one purchase cannot starve the next.
 *
 * A build that consumes the last metal stops the next save, the next commitment and the next research
 * step, so a player reserves first: keep a small fraction of the planet's storage and let the
 * production that arrives while saving refill it (SP5). The floor is the buffer reduced by that
 * production, and it is never negative. Each resource's floor guards that resource alone, so a planet
 * saving deuterium for a drive is not frozen out of spending its surplus metal and crystal: a purchase
 * that spends no deuterium must keep no deuterium floor. The buffer, the economy horizon and the
 * research horizon are the published defaults the corpus documents; none of them names a game object,
 * so nothing here encodes the host's universe.
 */
class ReserveFloor
{
    /** Keep this fraction of storage per resource (SP5, `keep_resources_buffer`). */
    public const BUFFER = 0.10;

    /** How many hours of production may refill the floor for an economy spend (SP5). */
    public const ECONOMY_HOURS = 4.0;

    /** How many hours of production may refill the floor for a research spend (SP5). */
    public const RESEARCH_HOURS = 6.0;

    /**
     * The floor that must survive a purchase, per resource, at this planet.
     *
     * @param float $savingHours how long the account is willing to wait for production to refill
     */
    public function floor(PlanetService $planet, float $savingHours): Resources
    {
        return new Resources(
            $this->perResource($planet->metalStorage()->get(), $planet->getMetalProductionPerHour(), $savingHours),
            $this->perResource($planet->crystalStorage()->get(), $planet->getCrystalProductionPerHour(), $savingHours),
            $this->perResource($planet->deuteriumStorage()->get(), $planet->getDeuteriumProductionPerHour(), $savingHours),
        );
    }

    private function perResource(float $storage, float $productionPerHour, float $savingHours): float
    {
        return max(0.0, $storage * self::BUFFER - $productionPerHour * $savingHours);
    }
}
