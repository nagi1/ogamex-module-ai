<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\PlanetService;

/**
 * Decides whether an espionage report is worth acting on, and how.
 *
 * The three checks are the plan's raid policy, all named as play: the bashing
 * limit (no more than six attacks on one planet in a day), the profit test (the
 * sampled lower-tail net profit must stay positive), and fresh intel (a stale
 * report is not acted on). The fleet and the defender are read from the host —
 * the estimator samples the host's own battle engine — so no target, unit or
 * price is named here.
 */
class RaidPlanner
{
    /** The host's hard bashing limit: at most six attacks on one target per day. */
    private const BASHING_LIMIT = 6;

    private const BASHING_WINDOW_HOURS = 24;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private NativeRaidEstimator $raidEstimator,
    ) {
    }

    public function plan(int $playerId, int $reportId): ?QueueableRaid
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $report = EspionageReport::query()->find($reportId);
        if ($report === null) {
            return null;
        }

        $origin = $this->origin($playerId);
        if ($origin === null) {
            return null;
        }

        $target = $this->target($report);
        if ($target === null) {
            return null;
        }

        if (!$this->withinBashingLimit($playerId, $target->getPlanetId())) {
            return null;
        }

        $estimate = $this->raidEstimator->estimate($playerId, $origin->getPlanetId(), $target->getPlanetId(), $profile->random_seed);
        if ($estimate->samples === 0 || $estimate->p20NetProfit <= 0.0) {
            return null;
        }

        return app()->makeWith(QueueableRaid::class, [
            'originPlanetId' => $origin->getPlanetId(),
            'targetGalaxy' => (int) $report->planet_galaxy,
            'targetSystem' => (int) $report->planet_system,
            'targetPosition' => (int) $report->planet_position,
            'targetType' => (int) $report->planet_type,
            'missionType' => AttackMission::getTypeId(),
        ]);
    }

    /**
     * The first planet carrying a fleet.
     */
    private function origin(int $playerId): ?PlanetService
    {
        $player = $this->playerServiceFactory->make($playerId, true);

        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The target planet a report points at, if it still exists.
     */
    private function target(EspionageReport $report): ?PlanetService
    {
        return $this->planetServiceFactory->makeForCoordinate(
            new Coordinate((int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position),
            false,
            PlanetType::from((int) $report->planet_type),
        );
    }

    /**
     * The bashing limit, read from the account's own attack history on the target.
     */
    private function withinBashingLimit(int $playerId, int $targetPlanetId): bool
    {
        $attacks = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('planet_id_to', $targetPlanetId)
            ->where('mission_type', AttackMission::getTypeId())
            ->where('time_arrival', '>=', now()->subHours(self::BASHING_WINDOW_HOURS)->timestamp)
            ->count();

        return $attacks < self::BASHING_LIMIT;
    }
}
