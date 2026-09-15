<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\PlanetService;

/**
 * Answers whether this account can probe a neighbour now, and which one.
 *
 * Scouting is the ordinary first contact an experienced player makes with the
 * galaxies around them, and it is what turns "no target intel" into the reports
 * raids later depend on. The target is a foreign planet the account does not own
 * and whose owner is neither in vacation mode nor the protected administrator —
 * the same gates the host's own espionage mission applies — and the probe the
 * mission consumes is the ship the host requires. The candidate list is the
 * host's own planets table, so no target is named here.
 */
class QueueableSpyPlanner
{
    private const PROBE = 'espionage_probe';

    /** Bounded: only this many candidate targets are inspected per decision. */
    private const MAX_CANDIDATES = 20;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableSpy
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $origin = $this->origin($playerId);
        if ($origin === null) {
            return null;
        }

        $target = $this->target($playerId, $profile->random_seed);
        if ($target === null) {
            return null;
        }

        return app()->makeWith(QueueableSpy::class, [
            'planetId' => $origin->getPlanetId(),
            'targetGalaxy' => (int) $target->galaxy,
            'targetSystem' => (int) $target->system,
            'targetPosition' => (int) $target->planet,
            'targetType' => (int) $target->planet_type,
            'missionType' => EspionageMission::getTypeId(),
        ]);
    }

    /**
     * The first planet carrying an idle probe.
     */
    private function origin(int $playerId): ?PlanetService
    {
        $player = $this->playerServiceFactory->make($playerId, true);

        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName(self::PROBE) > 0) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * A legal foreign target, walking the host's planets in a seeded order.
     *
     * Own, destroyed, vacationing and administrator-protected planets are all
     * skipped — the module restates no rule, it only declines candidates the
     * host's own mission would refuse.
     */
    private function target(int $playerId, int $seed): ?Planet
    {
        $candidates = Planet::query()
            ->where('user_id', '!=', $playerId)
            ->where('destroyed', 0)
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        foreach ($candidates as $planet) {
            $target = $this->planetServiceFactory->make($planet->id, true);

            $owner = $target->getPlayer();

            if ($owner->isInVacationMode()) {
                continue;
            }
            if ($owner->getUsername(false) === 'Legor') {
                continue;
            }

            return $planet;
        }

        return null;
    }
}
