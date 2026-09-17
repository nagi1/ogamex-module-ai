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

    /** A partial report on a valuable target gets this many probes; the host redacts ships below two and defence below three probes, so one probe re-reads the same redacted report. */
    private const ESCALATED_PROBES = 5;

    /** Metal-equivalent loot that makes a redacted report worth a probe volley. */
    private const RICH_YIELD = 10_000.0;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private ActivityIntelReader $activityIntelReader,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueableSpy
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);

        $skip = $this->freshIntelCoordinates($playerId)
            + $this->inFlightCoordinates($playerId)
            + $this->openSpyIntentCoordinates($playerId);
        $selection = $this->target($player, $skip);
        if ($selection === null) {
            return null;
        }
        [$origin, $target] = $selection;

        return app()->makeWith(QueueableSpy::class, [
            'planetId' => $origin->getPlanetId(),
            'targetGalaxy' => (int) $target->galaxy,
            'targetSystem' => (int) $target->system,
            'targetPosition' => (int) $target->planet,
            'targetType' => (int) $target->planet_type,
            'missionType' => EspionageMission::getTypeId(),
            'probeCount' => $this->probeCount($target),
        ]);
    }

    /**
     * Every own planet whose idle probes still exceed what its own pending spy intents
     * have committed (N6). A probe already promised to a queued or retried intent is not a
     * second probe, so two pending probes for different targets each plan from the budget
     * that remains after the other is committed.
     *
     * @return list<PlanetService>
     */
    private function idleProbePlanets(PlayerService $player): array
    {
        $committed = $this->committedProbesByPlanet($player->getId());
        $probeName = EspionageMission::getRequiredShipMachineNames()[0];
        $planets = [];

        foreach ($player->planets->all() as $planet) {
            $idle = $planet->getShipUnits()->getAmountByMachineName($probeName);

            if ($idle > ($committed[$planet->getPlanetId()] ?? 0)) {
                $planets[] = $planet;
            }
        }

        return $planets;
    }

    /** @return array<int, int> probes committed to open spy intents, keyed by the origin planet */
    private function committedProbesByPlanet(int $playerId): array
    {
        $committed = [];

        foreach (AiWorkItem::query()
            ->where('player_id', $playerId)
            ->where('kind', AiWorkKind::Spy)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Leased, AiWorkState::Retry])
            ->get(['payload']) as $item) {
            $payload = $item->payload ?? [];
            $planetId = (int) ($payload['planet_id'] ?? 0);

            if ($planetId > 0) {
                $committed[$planetId] = ($committed[$planetId] ?? 0) + 1;
            }
        }

        return $committed;
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
     * @return array{0: PlanetService, 1: Planet}|null the origin and its target
     */
    private function target(PlayerService $player, array $skipCoordinates): ?array
    {
        $idleOrigins = $this->idleProbePlanets($player);
        if ($idleOrigins === []) {
            return null;
        }

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

            // The candidate model is already in hand; building a PlanetService
            // from it skips the per-candidate Planet::find + refreshUser that
            // make($id, true) would pay (20 candidates × 2 round trips).
            $target = $this->planetServiceFactory->makeFromModel($planet);

            $owner = $target->getPlayer();

            if ($owner?->isInVacationMode() === true) {
                continue;
            }
            if ($owner?->getUsername(false) === 'Legor') {
                continue;
            }
            if ($this->activityIntelReader->activityAt($target)) {
                continue;
            }

            $origin = $this->closestOrigin($idleOrigins, $planet, $fleetMissions);
            $distance = $fleetMissions->calculateFleetMissionDistance($origin, new Coordinate((int) $planet->galaxy, (int) $planet->system, (int) $planet->planet));
            $scored[] = ['planet' => $planet, 'origin' => $origin, 'score' => ($knownYield[$coordinateKey] ?? 0.0) - $distance];
        }

        if ($scored === []) {
            return null;
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return [$scored[0]['origin'], $scored[0]['planet']];
    }

    /**
     * The own planet with an idle probe closest to the target, not the first in
     * collection order: a probe is fuel and time, so the nearest base sends it.
     *
     * @param list<PlanetService> $origins
     */
    private function closestOrigin(array $origins, Planet $target, FleetMissionService $fleetMissions): PlanetService
    {
        $coordinate = new Coordinate((int) $target->galaxy, (int) $target->system, (int) $target->planet);
        $best = $origins[0];
        $bestDistance = $fleetMissions->calculateFleetMissionDistance($best, $coordinate);

        foreach ($origins as $origin) {
            $distance = $fleetMissions->calculateFleetMissionDistance($origin, $coordinate);
            if ($distance < $bestDistance) {
                $best = $origin;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * The probes this target is worth. A complete report refreshes with one; a
     * partial report on a defended or known-rich body gets a volley — the host
     * redacts ships below two probes and defence below three, so one probe is
     * how the account keeps re-reading the same redacted report forever. An
     * empty redaction stays at one: the probes are not worth it.
     */
    private function probeCount(Planet $target): int
    {
        $report = EspionageReport::query()
            ->where('planet_galaxy', $target->galaxy)
            ->where('planet_system', $target->system)
            ->where('planet_position', $target->planet)
            ->orderByDesc('id')
            ->first(['resources', 'ships', 'defense']);

        if ($report === null) {
            return 1;
        }

        if ($this->activityIntelReader->completenessFactor($report->ships, $report->defense) >= 1.0) {
            return 1;
        }

        if (!$this->isDefended($report->ships, $report->defense) && $this->yieldFromResources($report->resources ?? []) < self::RICH_YIELD) {
            return 1;
        }

        return self::ESCALATED_PROBES;
    }

    /**
     * @param array<string, int>|null $ships
     * @param array<string, int>|null $defense
     */
    private function isDefended(array|null $ships, array|null $defense): bool
    {
        return ($ships ?? []) !== [] || ($defense ?? []) !== [];
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
        $planets = is_array($candidates) ? $candidates : iterator_to_array($candidates);
        if ($planets === []) {
            return [];
        }

        // One pass over the candidate coordinates instead of one report read per
        // candidate: the newest report per coordinate wins, so keep the first
        // (highest id) seen on the descending-id sweep.
        $reports = EspionageReport::query()
            ->where(function ($query) use ($planets): void {
                foreach ($planets as $planet) {
                    $query->orWhere(function ($coordinate) use ($planet): void {
                        $coordinate->where('planet_galaxy', $planet->galaxy)
                            ->where('planet_system', $planet->system)
                            ->where('planet_position', $planet->planet);
                    });
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'planet_galaxy', 'planet_system', 'planet_position', 'resources']);

        $yield = [];
        foreach ($reports as $report) {
            $key = "{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}";
            if (isset($yield[$key])) {
                continue;
            }

            $yield[$key] = $this->yieldFromResources($report->resources ?? []);
        }

        return $yield;
    }

    /**
     * Metal-equivalent of a report's visible resources, on the estimator's own weights.
     *
     * @param array<string, mixed> $resources
     */
    private function yieldFromResources(array $resources): float
    {
        return (float) ($resources['metal'] ?? 0)
            + 1.5 * (float) ($resources['crystal'] ?? 0)
            + 2.0 * (float) ($resources['deuterium'] ?? 0);
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
