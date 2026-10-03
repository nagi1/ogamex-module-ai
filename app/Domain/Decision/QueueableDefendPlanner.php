<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BattleReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\GameMissions\AcsDefendMission;
use OGame\Services\ObjectService;

/**
 * Whether this account should send part of its combat fleet to hold at an ally just seen attacked.
 *
 * The consumer DEF-010 asked for: the AllyUnderAttack observation names the report, the report names
 * the defending co-member. The account helps only a member it still shares an alliance with, only once
 * per ally per window, and only from a body that owns combat hulls. The host's ACS-defend gate (buddy
 * or alliance, ACS switched on, target exists) is the final word at dispatch.
 */
class QueueableDefendPlanner
{
    private const WINDOW_HOURS = 6;

    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function plan(int $playerId): ?QueueableDefend
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        $myAlliance = User::query()->whereKey($playerId)->value('alliance_id');
        if ($myAlliance === null) {
            return null;
        }

        $reportIds = AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::AllyUnderAttack)
            ->where('observed_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('observed_at')
            ->pluck('source_id');

        if ($reportIds->isEmpty()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $military = array_map(static fn ($ship) => $ship->machine_name, ObjectService::getMilitaryShipObjects());

        foreach ($reportIds as $reportId) {
            $allyId = BattleReport::query()->whereKey($reportId)->value('planet_user_id');
            if ($allyId === null || User::query()->whereKey($allyId)->value('alliance_id') !== $myAlliance) {
                continue;
            }

            $targetId = Planet::query()->where('user_id', $allyId)->orderBy('id')->value('id');
            if ($targetId === null || $this->alreadyHelping($playerId, (int) $targetId)) {
                continue;
            }

            foreach ($player->planets->all() as $source) {
                $owned = $source->getShipUnits()->toArray();
                foreach ($military as $machine) {
                    if (intdiv($owned[$machine] ?? 0, 2) > 0) {
                        return app()->makeWith(QueueableDefend::class, [
                            'sourcePlanetId' => $source->getPlanetId(),
                            'targetPlanetId' => (int) $targetId,
                        ]);
                    }
                }
            }
        }

        return null;
    }

    /** A defence already sent (and not yet home) to this planet is not sent twice. */
    private function alreadyHelping(int $playerId, int $targetPlanetId): bool
    {
        return FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', AcsDefendMission::getTypeId())
            ->where('planet_id_to', $targetPlanetId)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->exists();
    }
}
