<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account should move a mine's percentage, and to what.
 *
 * A player who cannot yet afford the plant does not leave a stalled planet
 * mining into a deficit: he turns the worst mine down to what the power covers,
 * and turns it back up once the plant lands. The mine is chosen from the host's
 * own production numbers — the energy consumer that yields the least output per
 * energy unit — never a name the module keeps, so a mod-added consumer with a
 * percentage column is picked up with no edit (gate 1).
 *
 * Throttle and restore are the same lever, never two: the percentage is lowered
 * only to the highest point the remaining power covers, and raised only when the
 * balance still covers the restored draw, so the two cannot oscillate over the
 * same shortfall.
 */
class QueueableMinePercentPlanner
{
    /** The host's 100%: ten percentage units (setBuildingPercent scales by ten). */
    private const FULL_PERCENT = 10;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueableMinePercent
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);

        foreach ($player->planets->all() as $planet) {
            $planet->updateResources(false);
            $energy = (float) $planet->energy()->get();

            if ($energy < 0.0) {
                $throttle = $this->throttle($planet, -$energy);
                if ($throttle !== null) {
                    return $throttle;
                }

                continue;
            }

            $restore = $this->restore($planet, $energy);
            if ($restore !== null) {
                return $restore;
            }
        }

        return null;
    }

    /**
     * Lower the worst-output-per-energy consumer to the highest percentage the
     * remaining power covers. Null when nothing can be lowered further.
     */
    private function throttle(PlanetService $planet, float $deficit): ?QueueableMinePercent
    {
        $worst = null;
        $worstRatio = null;

        foreach ($this->consumers($planet) as $consumer) {
            $ratio = $consumer['output'] / $consumer['draw'];
            if ($worstRatio === null || $ratio < $worstRatio) {
                $worst = $consumer;
                $worstRatio = $ratio;
            }
        }

        if ($worst === null) {
            return null;
        }

        $current = $planet->getBuildingPercent($worst['machine_name']);
        $lowered = max(0, (int) floor($current - self::FULL_PERCENT * $deficit / $worst['draw']));

        if ($lowered >= $current) {
            return null;
        }

        return $this->intent($planet, $worst['machine_name'], $lowered, 'throttle');
    }

    /**
     * Turn a throttled consumer back to full only when the balance still covers
     * the extra draw, so a restore never recreates the deficit it just cleared.
     */
    private function restore(PlanetService $planet, float $energy): ?QueueableMinePercent
    {
        foreach ($this->consumers($planet) as $consumer) {
            $current = $planet->getBuildingPercent($consumer['machine_name']);
            if ($current >= self::FULL_PERCENT) {
                continue;
            }

            $extra = $consumer['draw'] * (self::FULL_PERCENT - $current) / self::FULL_PERCENT;
            if ($energy - $extra < 0.0) {
                continue;
            }

            return $this->intent($planet, $consumer['machine_name'], self::FULL_PERCENT, 'restore');
        }

        return null;
    }

    /**
     * The energy consumers the planet actually runs, each with its 100%-scale
     * draw and raw output, read from the host's own production calculation.
     *
     * @return list<array{machine_name: string, draw: float, output: float}>
     */
    private function consumers(PlanetService $planet): array
    {
        $consumers = [];

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            $level = $planet->getObjectLevel($object->machine_name);
            if ($level <= 0) {
                continue;
            }

            $production = $planet->getObjectProduction($object->machine_name, $level, true);
            $draw = -(float) $production->energy->get();
            $output = (float) ($production->metal->get() + $production->crystal->get() + $production->deuterium->get());

            if ($draw <= 0.0 || $output <= 0.0) {
                continue;
            }

            $consumers[] = ['machine_name' => $object->machine_name, 'draw' => $draw, 'output' => $output];
        }

        return $consumers;
    }

    private function intent(PlanetService $planet, string $machineName, int $percentage, string $direction): QueueableMinePercent
    {
        return app()->makeWith(QueueableMinePercent::class, [
            'planetId' => $planet->getPlanetId(),
            'buildingId' => ObjectService::getObjectByMachineName($machineName)->id,
            'percentage' => $percentage,
            'reason' => $direction . ':' . $machineName,
        ]);
    }
}
