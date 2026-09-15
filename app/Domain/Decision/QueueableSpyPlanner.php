<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\ActivityIntelReader;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

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
 *
 * A target the account already holds fresh intel on, or already has a probe in
 * flight toward, is skipped. Scouting is bounded: each neighbour is probed once
 * per intel window, and the account waits for its own probe to come back before
 * sending another instead of re-probing the same planets it cannot act on.
 */
class QueueableSpyPlanner
{
    /** Bounded: only this many candidate targets are inspected per decision. */
    private const MAX_CANDIDATES = 20;

    /** A report stays fresh this long; scouting and raiding agree on the window. */
    private const INTEL_TTL_HOURS = 24;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private ActivityIntelReader $activityIntelReader,
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

        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->origin($player);
        if ($origin === null) {
            return null;
        }

        $skip = $this->freshIntelCoordinates($playerId)
            + $this->inFlightCoordinates($playerId)
            + $this->openSpyIntentCoordinates($playerId);
        $target = $this->target($player, $origin, $skip);
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
    private function origin(PlayerService $player): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName(EspionageMission::getRequiredShipMachineNames()[0]) > 0) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The best legal foreign target, scored instead of the first one in id order.
     *
     * Own, destroyed, vacationing and administrator-protected planets are all
     * skipped — the module restates no rule, it only declines candidates the
     * host's own mission would refuse. A planet the account already knows, or
     * already has a probe travelling toward, is skipped too. Among what remains,
     * a just-touched target is skipped (INT-009) and the rest are ranked by
     * known yield and closeness (INT-003): the closest known-rich neighbour, not
     * the lowest id.
     *
     * @param array<string, true> $skipCoordinates
     */
    private function target(PlayerService $player, PlanetService $origin, array $skipCoordinates): ?Planet
    {
        $candidates = Planet::query()
            ->where('user_id', '!=', $player->getId())
            ->where('destroyed', 0)
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $knownYield = $this->knownYieldByCoordinate($candidates);
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $scored = [];

        foreach ($candidates as $planet) {
            $coordinateKey = "{$planet->galaxy}:{$planet->system}:{$planet->planet}";
            if (isset($skipCoordinates[$coordinateKey])) {
                continue;
            }

            $target = $this->planetServiceFactory->make($planet->id, true);
            $owner = $target->getPlayer();

            if ($owner->isInVacationMode()) {
                continue;
            }
            if ($owner->getUsername(false) === 'Legor') {
                continue;
            }
            if ($this->activityIntelReader->activityAt($target)) {
                continue;
            }

            $distance = $fleetMissions->calculateFleetMissionDistance($origin, new Coordinate((int) $planet->galaxy, (int) $planet->system, (int) $planet->planet));
            $scored[] = ['planet' => $planet, 'score' => ($knownYield[$coordinateKey] ?? 0.0) - $distance];
        }

        if ($scored === []) {
            return null;
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $scored[0]['planet'];
    }

    /**
     * The last-known loot of each candidate, as metal-equivalent, from its most
     * recent report of any age. A never-probed planet scores zero here and is
     * ranked by closeness alone.
     *
     * @param iterable<int, Planet> $candidates
     * @return array<string, float>
     */
    private function knownYieldByCoordinate(iterable $candidates): array
    {
        $yield = [];
        foreach ($candidates as $planet) {
            $report = EspionageReport::query()
                ->where('planet_galaxy', $planet->galaxy)
                ->where('planet_system', $planet->system)
                ->where('planet_position', $planet->planet)
                ->orderByDesc('id')
                ->first(['resources']);
            if ($report === null) {
                continue;
            }

            $resources = $report->resources ?? [];
            $yield["{$planet->galaxy}:{$planet->system}:{$planet->planet}"] = (int) ($resources['metal'] ?? 0)
                + 1.5 * (int) ($resources['crystal'] ?? 0)
                + 2.0 * (int) ($resources['deuterium'] ?? 0);
        }

        return $yield;
    }

    /**
     * The coordinates the account already holds fresh intel on, keyed g:s:p.
     *
     * A report is reached through the account's own messages, the host's link
     * from a probe to the report it produced, and only reports inside the intel
     * window count. The window matches the observation service's TTL so scouting
     * and raiding agree on what "recently probed" means.
     *
     * @return array<string, true>
     */
    private function freshIntelCoordinates(int $playerId): array
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::INTEL_TTL_HOURS))
            ->pluck('espionage_report_id');

        $coordinates = [];
        foreach (EspionageReport::query()->whereIn('id', $reportIds)->get(['planet_galaxy', 'planet_system', 'planet_position']) as $report) {
            $coordinates["{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}"] = true;
        }

        return $coordinates;
    }

    /**
     * The coordinates this account already has an espionage probe travelling to,
     * keyed g:s:p. A probe that has not returned yet yields no report, so it has
     * to be skipped on its own signal rather than through fresh intel: firing a
     * second probe at the same planet before the first is back is the other half
     * of the re-probing loop the freshness guard alone cannot stop.
     *
     * @return array<string, true>
     */
    private function inFlightCoordinates(int $playerId): array
    {
        $coordinates = [];
        foreach (FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', EspionageMission::getTypeId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->get(['galaxy_to', 'system_to', 'position_to']) as $mission) {
            $coordinates["{$mission->galaxy_to}:{$mission->system_to}:{$mission->position_to}"] = true;
        }

        return $coordinates;
    }

    /**
     * The coordinates this account has already decided to probe but not yet
     * dispatched, keyed g:s:p. A queued or retried intent sits between the
     * decision and the mission, and a report does not exist for it yet, so it
     * has to be skipped on its own signal: deciding to probe the same target
     * again every session while the earlier intent is still waiting is the
     * third leg of the re-probing loop, after fresh intel and the in-flight
     * mission.
     *
     * @return array<string, true>
     */
    private function openSpyIntentCoordinates(int $playerId): array
    {
        $coordinates = [];
        foreach (AiWorkItem::query()
            ->where('player_id', $playerId)
            ->where('kind', AiWorkKind::Spy)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Leased, AiWorkState::Retry])
            ->get(['payload']) as $item) {
            $payload = $item->payload ?? [];
            if (isset($payload['target_galaxy'], $payload['target_system'], $payload['target_position'])) {
                $coordinates["{$payload['target_galaxy']}:{$payload['target_system']}:{$payload['target_position']}"] = true;
            }
        }

        return $coordinates;
    }
}
