<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use Illuminate\Support\Collection;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\JumpGateService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * A moon with a ready Jump Gate and ships on it, hit by a non-spy fleet that lands within the hour, jumps
 * the fleet to another own moon whose gate is ready. The hostile fleet then meets an empty orbit. With no
 * threat, a fleet stages forward: it jumps to the gated moon nearest an enemy planet, the way a player at
 * war keeps the fleet where the strikes start. The host's gate rules (level, cooldown on both ends,
 * transferable hulls) are asked, not restated.
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

        $ready = array_filter(
            $player->planets->allMoons(),
            fn (PlanetService $moon): bool => $moon->getObjectLevel('jump_gate') >= 1
                && !$this->jumpGateService->isOnCooldown($moon)
                && $this->carries($moon, $transferable)
                && !$this->jumpGateService->hasUnprocessedArrivedFleet($moon),
        );

        return $this->escape($playerId, $player, $ready) ?? $this->stage($playerId, $player, $ready);
    }

    /** @param array<int, PlanetService> $ready */
    private function escape(int $playerId, PlayerService $player, array $ready): ?QueueableJumpGate
    {
        foreach ($ready as $moon) {
            if (!$this->threatened($playerId, $moon->getPlanetId())) {
                continue;
            }

            foreach ($this->jumpGateService->getEligibleTargets($player, $moon) as $target) {
                // A partner that is itself about to be hit is no refuge.
                if ($this->threatened($playerId, $target->getPlanetId()) || $this->jumpGateService->hasUnprocessedArrivedFleet($target)) {
                    continue;
                }

                return $this->jump($moon, $target);
            }
        }

        return null;
    }

    /**
     * Only a strictly nearer partner is a jump, so a fleet already on its forward moon stays put.
     *
     * @param array<int, PlanetService> $ready
     */
    private function stage(int $playerId, PlayerService $player, array $ready): ?QueueableJumpGate
    {
        $enemyIds = array_keys(app(Enemies::class)->of($playerId));
        if ($enemyIds === []) {
            return null;
        }

        $enemyPlanets = Planet::query()->whereIn('user_id', $enemyIds)->get(['galaxy', 'system']);
        if ($enemyPlanets->isEmpty()) {
            return null;
        }

        foreach ($ready as $moon) {
            $here = $this->reach($moon, $enemyPlanets);
            foreach ($this->jumpGateService->getEligibleTargets($player, $moon) as $target) {
                if ($this->reach($target, $enemyPlanets) < $here
                    && !$this->threatened($playerId, $target->getPlanetId())
                    && !$this->jumpGateService->hasUnprocessedArrivedFleet($target)) {
                    return $this->jump($moon, $target);
                }
            }
        }

        return null;
    }

    /**
     * Systems to the nearest enemy planet; another galaxy counts as farther than any system in this one.
     *
     * @param Collection<int, Planet> $enemyPlanets
     */
    private function reach(PlanetService $moon, Collection $enemyPlanets): int
    {
        $at = $moon->getPlanetCoordinates();

        return (int) $enemyPlanets->min(
            static fn (Planet $planet): int => abs($planet->galaxy - $at->galaxy) * 1_000 + abs($planet->system - $at->system),
        );
    }

    /** @param list<string> $transferable */
    private function carries(PlanetService $moon, array $transferable): bool
    {
        foreach ($transferable as $ship) {
            if ($moon->getObjectAmount($ship) > 0) {
                return true;
            }
        }

        return false;
    }

    private function jump(PlanetService $source, PlanetService $target): QueueableJumpGate
    {
        return app()->makeWith(QueueableJumpGate::class, [
            'sourceMoonId' => $source->getPlanetId(),
            'targetMoonId' => $target->getPlanetId(),
        ]);
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
