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
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

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

    /** RAID-011: loot-to-fuel ratio a raid must clear before it flies (metal-equivalent loot : deuterium). */
    private const LOOT_TIER_FARM = 3.0;

    private const LOOT_TIER_DEFENDED = 2.0;

    /** RAID-009: the fleet raids on the storage-fill schedule, so the warehouse must be near full. */
    private const RAID_STORAGE_FILL_RATIO = 0.8;

    /**
     * The fraction of sampled runs the attacking fleet must survive before a
     * raid flies. The fleet-loss rate is its complement (1 - SURVIVAL_FLOOR): a
     * coin-flip that profits on paper still loses the fleet too often. ponytail:
     * one unmeasured floor for every persona; per-archetype tightening is the
     * upgrade path once play data shows it varies.
     */
    private const SURVIVAL_FLOOR = 0.8;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private NativeRaidEstimator $raidEstimator,
    ) {
    }

    /**
     * Whether the account's fleet planet has a warehouse worth flying for.
     *
     * A fleeter raids on the storage-fill schedule (8-12h), not ad hoc every
     * session: the fleet flies when the mines have filled the warehouse
     * (RAID-009). The ratio is the corpus' own near-full threshold (E3); the
     * exact number is persona flavour.
     */
    public function storageReady(int $playerId): bool
    {
        if (!User::query()->whereKey($playerId)->exists()) {
            return true;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->origin($player);
        if ($origin === null) {
            return true;
        }

        $origin = $this->planetServiceFactory->makeForPlayer($player, $origin->getPlanetId(), false);
        $stored = $origin->metal()->get() + $origin->crystal()->get() + $origin->deuterium()->get();
        $capacity = $origin->metalStorage()->get() + $origin->crystalStorage()->get() + $origin->deuteriumStorage()->get();

        if ($capacity <= 0) {
            return false;
        }

        return $stored / $capacity >= self::RAID_STORAGE_FILL_RATIO;
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

        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->origin($player);
        if ($origin === null) {
            return null;
        }

        // The fuel and loot quotes need the origin's owner context, which the
        // planets collection does not carry by itself.
        $origin = $this->planetServiceFactory->makeForPlayer($player, $origin->getPlanetId(), false);

        $target = $this->target($report);
        if ($target === null) {
            return null;
        }

        if (!$this->withinBashingLimit($playerId, $target->getPlanetId())) {
            return null;
        }

        $estimate = $this->raidEstimator->estimate($playerId, $origin->getPlanetId(), $target->getPlanetId(), $profile->random_seed);
        if ($estimate->samples === 0) {
            return null;
        }

        // A fleeter asks "will I survive?" before "does it profit?". Refuse when
        // the sampled fleet is wiped more than 1 - SURVIVAL_FLOOR of the time.
        if ($estimate->pWin < self::SURVIVAL_FLOOR) {
            return null;
        }

        if ($estimate->p20NetProfit <= 0.0) {
            return null;
        }

        // The sampled profit is loot minus losses only. A raid also burns
        // deuterium to fly there and back, so a distant farm that spends more
        // fuel than the tier allows is refused even when it would "profit"
        // (RAID-006, RAID-011).
        $fuel = $this->roundTripFuel($player, $origin, $target);
        $defended = $target->getDefenseUnits()->units !== [];
        if (!$this->clearsLootTier($estimate->p20Loot, $fuel, $defended)) {
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
    private function origin(PlayerService $player): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The deuterium a raid burns flying there and back, host-quoted for the
     * origin's own fleet at the slowest speed (RAID-006).
     */
    private function roundTripFuel(PlayerService $player, PlanetService $origin, PlanetService $target): int
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $oneWay = $fleetMissions->calculateConsumption($origin, $origin->getShipUnits(), $target->getPlanetCoordinates(), 0, 10.0);

        return 2 * (int) $oneWay;
    }

    /**
     * A routine farm must carry three metal-equivalent for each deuterium spent;
     * a defended run is allowed two because the debris subsidises it. Nothing
     * under the floor flies (RAID-011).
     */
    private function clearsLootTier(float $loot, int $fuel, bool $defended): bool
    {
        $tier = $defended ? self::LOOT_TIER_DEFENDED : self::LOOT_TIER_FARM;

        return $loot / max(1, $fuel) >= $tier;
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
