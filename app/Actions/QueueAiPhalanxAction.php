<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\QueueAiPhalanx;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Models\AiPhalanxScan;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PhalanxService;
use Throwable;

/**
 * Module-owned adapter over the host's sensor-phalanx path.
 *
 * The decision and the target are the module's; the range, cost and the scan
 * itself are the host's. A scan the host refuses records the refusal and writes
 * no game state — the module only keeps the one number the raid decision
 * consumes (incoming ships) after a scan actually ran.
 */
class QueueAiPhalanxAction implements QueueAiPhalanx
{
    public function __construct(
        private PhalanxService $phalanx,
        private PlanetServiceFactory $planetServiceFactory,
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $moonPlanetId, int $targetPlanetId): AiActionResult
    {
        $moon = Planet::query()->whereKey($moonPlanetId)->where('user_id', $playerId)->first();
        if ($moon === null) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        $target = Planet::query()->find($targetPlanetId);
        if ($target === null) {
            return AiActionResult::rejected(AiQueueActionReason::PhalanxScanRefused);
        }

        try {
            $player = $this->playerServiceFactory->make($playerId, true);
            $moonService = $this->planetServiceFactory->makeForPlayer($player, $moonPlanetId, false);
            $coordinate = new Coordinate($target->galaxy, $target->system, $target->planet);

            // The host's own legality gate, respected exactly: an out-of-range scan is a ban
            // risk, not a profit, and a moon too short on deuterium cannot scan.
            if (!$this->phalanx->canScanTarget($moon->galaxy, $moon->system, $moonService->getObjectLevel('sensor_phalanx'), $coordinate, $playerId)
                || !$this->phalanx->hasEnoughDeuterium($moonService->deuterium()->get())) {
                return AiActionResult::rejected(AiQueueActionReason::PhalanxScanRefused);
            }

            $incomingShips = $this->incomingShipCount($this->phalanx->scanPlanetFleets($targetPlanetId, $playerId));

            AiPhalanxScan::query()->create([
                'player_id' => $playerId,
                'moon_planet_id' => $moonPlanetId,
                'target_planet_id' => $targetPlanetId,
                'incoming_ship_count' => $incomingShips,
                'observed_at' => now(),
            ]);

            return AiActionResult::succeeded(AiQueueActionReason::PhalanxScanned);
        } catch (Throwable $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The ships the scan saw arriving at the target. Only incoming entries count: an
     * outgoing fleet is the defender leaving, not one the raid would fly into.
     *
     * @param array<int, array<string, mixed>> $fleets
     */
    private function incomingShipCount(array $fleets): int
    {
        $ships = 0;

        foreach ($fleets as $entry) {
            if (($entry['is_incoming'] ?? false) === true) {
                $ships += (int) ($entry['ship_count'] ?? 0);
            }
        }

        return $ships;
    }
}
