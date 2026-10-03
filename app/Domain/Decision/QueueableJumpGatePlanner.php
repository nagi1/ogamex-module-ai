<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Services\JumpGateService;

/**
 * A moon with a ready Jump Gate and ships on it, hit by a non-spy fleet that lands within the hour, jumps
 * the fleet to another own moon whose gate is ready. The hostile fleet then meets an empty orbit. The
 * host's gate rules (level, cooldown on both ends, transferable hulls) are asked, not restated.
 */
class QueueableJumpGatePlanner
{
    private const LOOKAHEAD_SECONDS = 3600;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private JumpGateService $jumpGateService,
    ) {
    }

    public function plan(int $playerId): ?QueueableJumpGate
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $transferable = $this->jumpGateService->getTransferableShips();

        foreach ($player->planets->allMoons() as $moon) {
            if ($moon->getObjectLevel('jump_gate') < 1 || $this->jumpGateService->isOnCooldown($moon)) {
                continue;
            }
            if (!$this->threatened($playerId, $moon->getPlanetId())) {
                continue;
            }

            $carries = false;
            foreach ($transferable as $ship) {
                if ($moon->getObjectAmount($ship) > 0) {
                    $carries = true;
                    break;
                }
            }
            if (!$carries || $this->jumpGateService->hasUnprocessedArrivedFleet($moon)) {
                continue;
            }

            foreach ($this->jumpGateService->getEligibleTargets($player, $moon) as $target) {
                // A partner that is itself about to be hit is no refuge.
                if ($this->threatened($playerId, $target->getPlanetId()) || $this->jumpGateService->hasUnprocessedArrivedFleet($target)) {
                    continue;
                }

                return app()->makeWith(QueueableJumpGate::class, [
                    'sourceMoonId' => $moon->getPlanetId(),
                    'targetMoonId' => $target->getPlanetId(),
                ]);
            }
        }

        return null;
    }

    private function threatened(int $playerId, int $planetId): bool
    {
        return FleetMission::query()
            ->where('planet_id_to', $planetId)
            ->where('user_id', '!=', $playerId)
            ->where('mission_type', '!=', EspionageMission::getTypeId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('time_arrival', '<=', now()->timestamp + self::LOOKAHEAD_SECONDS)
            ->exists();
    }
}
