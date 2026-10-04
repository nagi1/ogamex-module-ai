<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Actions\QueueAiColonyAction;
use Modules\AI\Domain\Galaxy\GalaxyMap;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\FlightFuel;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\GameMissions\ColonisationMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers whether this account can colonise now, and where.
 *
 * The chain the whole expansion rests on: an account with one homeworld has
 * nowhere to fleetsave, transport or spread, so a colony is the prerequisite for
 * every later fleet behaviour. Nothing here names a slot, a galaxy or a position
 * bonus -- the empty slot is whatever coordinate the host reports as unoccupied,
 * the reach is the account's own astrophysics answer (`canColonizePosition`), and
 * the order is a deterministic per-account walk so a cohort does not all converge
 * on the same first slot.
 *
 * The scan is bounded: an empty slot near the account's seeded start is taken,
 * and the pass stops after a fixed number of checks rather than walking the
 * universe.
 */
class QueueableColonyPlanner
{
    private const POLICY_FILE = '/resources/behavior/colonisation.yaml';

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private SettingsService $settings,
        private GalaxyMap $galaxyMap,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueableColony
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);

        // An account at its planet cap cannot found another colony: the host cancels the
        // mission at arrival, so planning one is pure waste of a colony ship and a fleet slot.
        // The cap is the host's own astrophysics answer, never a module constant.
        if ($player->planets->planetCount() >= $player->getMaxPlanetAmount()) {
            return null;
        }

        // The slot is claimed first, then the body that flies there: the flight's deuterium is the
        // host's own quote for that route, so the origin cannot be chosen before the destination.
        $target = $this->emptySlot($player, $profile->random_seed);
        if ($target === null) {
            return null;
        }

        $origin = $this->originPlanet($player, $target, app(RecentRefusals::class)->refusedOrigins($playerId));
        if ($origin === null) {
            return null;
        }

        return app()->makeWith(QueueableColony::class, [
            'planetId' => $origin->getPlanetId(),
            'galaxy' => $target->galaxy,
            'system' => $target->system,
            'position' => $target->position,
            'missionType' => ColonisationMission::getTypeId(),
        ]);
    }

    /**
     * The planet a colony leaves from: one that can pay the host's own fuel quote for the flight it is
     * offered for, preferring a body that already holds the colony ship.
     *
     * Waiting for the ship before committing to a free slot is what left the cohort never
     * colonising: the slot is claimed by deciding to settle it, and the ship a shipyard builds
     * for that decision belongs to the same decision rather than to its precondition. The host
     * still owns the ship's machine name and the reach of the walk.
     *
     * A body the gate just refused a colony ship from is no origin: the account founds the next colony
     * from a body that can launch, instead of repeating a refusal the gate already recorded.
     *
     * @param array<int, true> $refusedOrigins own bodies the gate just refused a dispatch from
     */
    private function originPlanet(PlayerService $player, Coordinate $target, array $refusedOrigins): ?PlanetService
    {
        $ship = ObjectService::getUnitObjectByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]);

        foreach ($player->planets->all() as $planet) {
            if (! isset($refusedOrigins[$planet->getPlanetId()])
                && $planet->getShipUnits()->getAmountByMachineName($ship->machine_name) > 0
                && $this->canPayFlight($player, $planet, $ship, $target)) {
                return $planet;
            }
        }

        foreach ($player->planets->all() as $planet) {
            if (! isset($refusedOrigins[$planet->getPlanetId()]) && $this->canPayFlight($player, $planet, $ship, $target)) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The host's own quote for the colony ship's flight, asked before the slot is offered: a plan the
     * gate would refuse for its fuel is no colony, and it is the same quote the gate asks.
     */
    private function canPayFlight(PlayerService $player, PlanetService $origin, UnitObject $ship, Coordinate $target): bool
    {
        $units = new UnitCollection();
        $units->addUnit($ship, 1);

        return app(FlightFuel::class)->affordable($player, $origin, $units, $target, QueueAiColonyAction::COLONY_SPEED);
    }

    /**
     * The ceiling on coordinate checks per decision, read by name from the behaviour file: a walk
     * that visits too few systems finds no empty slot in a crowded universe and the account never
     * colonises, while one that visits the whole universe stalls a session. The cap wins.
     */
    private function maxCoordinateChecks(): int
    {
        $parsed = Yaml::parseFile(module_path('AI', self::POLICY_FILE));
        $checks = is_array($parsed) ? ($parsed['colonisation']['max_coordinate_checks'] ?? null) : null;

        if (!is_numeric($checks) || (int) $checks < 1) {
            throw new RuntimeException('colonisation: the file must state max_coordinate_checks.');
        }

        return (int) $checks;
    }

    /**
     * The largest empty, colonisable coordinate from a seeded start, bounded.
     *
     * An experienced player colonises the bigger slots, not the first empty one:
     * the host's own field range for each position is the planet's size, so the
     * walk keeps the largest empty slot it sees and returns it. Among slots of
     * equal size the system this account has seen least military in wins, and the
     * per-account seed offsets the walk so two accounts do not claim the same
     * slot: an equal-size slot in an equally quiet system later in the walk
     * loses. The host answers reach (`canColonizePosition`) and emptiness (one
     * read of the rows occupying the systems the walk visits); the field range is
     * the host's `planetData`, never a position list.
     */
    private function emptySlot(PlayerService $player, int $seed): ?Coordinate
    {
        $maxScans = $this->maxCoordinateChecks();
        $galaxies = max(1, $this->settings->numberOfGalaxies());
        $systems = max(1, $this->settings->numberOfSystems());
        // The walk is bounded by systems rather than by a running counter: one
        // comparison caps the whole decision at the behaviour file's coordinate
        // ceiling, so a full universe cannot stall a session and the bound never
        // needs a per-check branch.
        $systemsPerGalaxy = min($systems, max(1, intdiv($maxScans, 12 * $galaxies)));

        $best = null;
        $bestFields = -1;
        $bestThreat = 0;

        for ($galaxyOffset = 0; $galaxyOffset < $galaxies; $galaxyOffset++) {
            $galaxy = 1 + (($seed + $galaxyOffset) % $galaxies);

            $systemsInWalk = [];
            for ($system = 1; $system <= $systemsPerGalaxy; $system++) {
                $systemsInWalk[] = 1 + (($system + ($seed >> 4) - 1) % $systems);
            }

            // The walk asks about every position of every system it visits, and a
            // point lookup per position costs a round trip each for an answer one
            // read already holds. `destroyed` is deliberately unfiltered: a row
            // occupies its coordinate exactly as the host reports it.
            $occupied = [];
            foreach (Planet::query()
                ->where('galaxy', $galaxy)
                ->whereIn('system', $systemsInWalk)
                ->where('planet_type', PlanetType::Planet->value)
                ->get(['system', 'planet']) as $occupying) {
                $occupied[$occupying->system . ':' . $occupying->planet] = true;
            }

            // What the account has seen of this galaxy, read once: an experienced player does not settle the
            // same size of planet next to a fleet it has already read (architecture step 7, 4.5).
            $threats = $this->galaxyMap->threats($player->getId(), $galaxy);

            foreach ($systemsInWalk as $systemWithOffset) {
                $threat = $threats[$systemWithOffset] ?? 0;

                for ($position = UniverseConstants::MIN_PLANET_POSITION; $position <= UniverseConstants::MAX_PLANET_POSITION; $position++) {
                    if (!$player->canColonizePosition($position)) {
                        continue;
                    }

                    if (isset($occupied[$systemWithOffset . ':' . $position])) {
                        continue;
                    }

                    // The size a planet at this position would get, from the host's
                    // own field range (mid positions report the widest range). The
                    // largest empty slot seen so far wins; among equal sizes the
                    // quieter system wins, and an equal threat keeps the seeded
                    // walk order as the tie-break.
                    $fields = (int) $this->planetServiceFactory->planetData($position, false)['fields'][1];
                    if ($fields < $bestFields) {
                        continue;
                    }

                    if ($fields > $bestFields || $threat < $bestThreat) {
                        $best = new Coordinate($galaxy, $systemWithOffset, $position);
                        $bestFields = $fields;
                        $bestThreat = $threat;
                    }
                }
            }
        }

        return $best;
    }
}
