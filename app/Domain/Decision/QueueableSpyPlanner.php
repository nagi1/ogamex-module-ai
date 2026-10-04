<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Intel\IntelBook;
use Modules\AI\Domain\Perception\ActivityIntelReader;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\FlightFuel;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\GameMissions\EspionageMission;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\ObjectService;
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
    /** Bounded: only this many unscouted, nearest candidate targets are inspected per decision. */
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
        private IntelBook $intelBook,
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
     * known yield, what the target paid before and closeness (INT-003): the
     * closest known-rich neighbour, not the lowest id.
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

        // A scout reads the neighbours nearest to home, not the oldest stamps in the universe: ordering by the
        // owner's last login pushed every cohort account (whose stamp is zero) behind the fixed farms, so
        // the nearest rivals were never read and nothing defended was ever raided. The farm-first ranking
        // below still picks the quiet body among the nearest, and the host's isInactive() stays the authority.
        $home = $idleOrigins[0]->getPlanetCoordinates();
        $candidates = Planet::query()
            ->leftJoin('users', 'users.id', '=', 'planets.user_id')
            ->where('planets.user_id', '!=', $player->getId())
            ->where('planets.destroyed', 0)
            ->where(fn ($owner) => $owner->whereNull('users.vacation_mode')->orWhere('users.vacation_mode', 0))
            ->whereIn('planets.planet_type', [PlanetType::Planet->value, PlanetType::Moon->value])
            ->select('planets.*')
            ->orderByRaw('ABS(CAST(`planets`.`galaxy` AS SIGNED) - ?) * 100000 + ABS(CAST(`planets`.`system` AS SIGNED) - ?) * 20 + ABS(CAST(`planets`.`planet` AS SIGNED) - ?), `planets`.`id`', [$home->galaxy, $home->system, $home->position])
            ->cursor()
            ->reject(static fn (Planet $planet): bool => isset($skipCoordinates["{$planet->galaxy}:{$planet->system}:{$planet->planet}"]))
            ->take(self::MAX_CANDIDATES)
            ->values();

        $intel = $this->knownIntelByCoordinate($candidates);
        $knownYield = $intel['yield'];
        // What the account remembers about these planets: the priority counter, read in one pass so a
        // target that paid before is not priced once per candidate.
        $priorities = $this->intelBook->priorities($player->getId());
        $payoffWeight = $this->intelBook->priorityWeight();
        $fighter = $this->ownsWarFleet($idleOrigins);
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

            // The host's own protected-administrator signal, so the rule survives any operator rename.
            if ($owner?->isAdmin() === true) {
                continue;
            }
            $origin = $this->closestOrigin($player, $idleOrigins, $planet, $fleetMissions);
            if ($origin === null) {
                continue;
            }
            $distance = $fleetMissions->calculateFleetMissionDistance($origin, new Coordinate((int) $planet->galaxy, (int) $planet->system, (int) $planet->planet));
            $scored[] = [
                'planet' => $planet,
                'origin' => $origin,
                'inactive' => $owner?->isInactive() ?? false,
                'quiet' => !$this->activityIntelReader->activityAt($target),
                'defended' => $fighter && isset($intel['defended'][$coordinateKey]),
                'score' => ($knownYield[$coordinateKey] ?? 0.0) - $distance + $payoffWeight * ($priorities[$coordinateKey] ?? 0),
            ];
        }

        if ($scored === []) {
            return null;
        }

        // The farm comes first, then the neighbour away from the keyboard, then the one at it: a player who
        // has read every quiet body still scouts the active ones, since an account that acts every few
        // minutes is the only kind that fights back and leaves debris. Among equals the closest known-rich
        // body wins (INT-003).
        // An account that owns a war fleet reads the bodies known to fight back first: an administrator
        // sees raids that pillage empty planets as a dead universe (LIFE_FIGHTS), and a fleet is built
        // to be flown at something that shoots.
        usort($scored, static fn (array $left, array $right): int => [$right['defended'], $right['inactive'], $right['quiet'], $right['score']] <=> [$left['defended'], $left['inactive'], $left['quiet'], $left['score']]);

        return [$scored[0]['origin'], $scored[0]['planet']];
    }

    /**
     * Whether any scouting base stands a military hull, by the host's own military catalogue.
     *
     * @param list<PlanetService> $origins
     */
    private function ownsWarFleet(array $origins): bool
    {
        $military = array_map(static fn ($ship): string => $ship->machine_name, ObjectService::getMilitaryShipObjects());

        foreach ($origins as $origin) {
            foreach ($origin->getShipUnits()->units as $entry) {
                // A probe is in the military catalogue but carries no real weapon: a war fleet shoots.
                $player = $origin->getPlayer();
                if ($player !== null && in_array($entry->unitObject->machine_name, $military, true) && $entry->unitObject->properties->attack->calculate($player)->totalValue > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The own planet with an idle probe closest to the target that can pay the flight, not the first in
     * collection order: a probe is fuel and time, so the nearest base that has the deuterium sends it.
     * No such base means the target is not scouted now, as a player with an empty tank does not queue it.
     *
     * @param list<PlanetService> $origins
     */
    private function closestOrigin(PlayerService $player, array $origins, Planet $target, FleetMissionService $fleetMissions): PlanetService|null
    {
        $coordinate = new Coordinate((int) $target->galaxy, (int) $target->system, (int) $target->planet);
        $probe = ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]);
        $best = null;
        $bestDistance = null;

        foreach ($origins as $origin) {
            $units = new UnitCollection();
            $units->addUnit($probe, $this->probeCount($target));
            if (!app(FlightFuel::class)->affordable($player, $origin, $units, $coordinate, 10)) {
                continue;
            }
            $distance = $fleetMissions->calculateFleetMissionDistance($origin, $coordinate);
            if ($bestDistance === null || $distance < $bestDistance) {
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
     * Also says which of them the last report found defended (ships or defence standing), since the
     * scout that owns a war fleet reads the bodies that fight before the empty farms.
     *
     * @param iterable<int, Planet> $candidates
     * @return array{yield: array<string, float>, defended: array<string, true>}
     */
    private function knownIntelByCoordinate(iterable $candidates): array
    {
        $planets = is_array($candidates) ? $candidates : iterator_to_array($candidates);
        if ($planets === []) {
            return ['yield' => [], 'defended' => []];
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
            ->get(['id', 'planet_galaxy', 'planet_system', 'planet_position', 'resources', 'ships', 'defense']);

        $yield = [];
        $defended = [];
        foreach ($reports as $report) {
            $key = "{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}";
            if (isset($yield[$key])) {
                continue;
            }

            $yield[$key] = $this->yieldFromResources($report->resources ?? []);
            if ($this->isDefended($report->ships, $report->defense)) {
                $defended[$key] = true;
            }
        }

        return ['yield' => $yield, 'defended' => $defended];
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
