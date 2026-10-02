<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\GameMissions\ColonisationMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
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

        $origin = $this->originPlanet($player->planets->all());
        if ($origin === null) {
            return null;
        }

        $target = $this->emptySlot($player, $profile->random_seed);
        if ($target === null) {
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
     * The planet a colony leaves from: the one holding a colony ship, else the account's first.
     *
     * Waiting for the ship before committing to a free slot is what left the cohort never
     * colonising: the slot is claimed by deciding to settle it, and the ship a shipyard builds
     * for that decision belongs to the same decision rather than to its precondition. The host
     * still owns the ship's machine name and the reach of the walk.
     *
     * @param array<int, PlanetService> $planets
     */
    private function originPlanet(array $planets): ?PlanetService
    {
        $ship = ColonisationMission::getRequiredShipMachineNames()[0];

        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName($ship) > 0) {
                return $planet;
            }
        }

        $first = array_key_first($planets);

        return $first === null ? null : $planets[$first];
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
     * walk keeps the largest empty slot it sees and returns it. The per-account
     * seed offsets the walk so two accounts do not claim the same slot, and it
     * stays the tie-break: an equal-size slot later in the walk loses. The host
     * answers reach (`canColonizePosition`) and emptiness (one read of the rows
     * occupying the systems the walk visits); the field range is the host's
     * `planetData`, never a position list.
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
        $bestFields = -1.0;

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

            foreach ($systemsInWalk as $systemWithOffset) {
                for ($position = UniverseConstants::MIN_PLANET_POSITION; $position <= UniverseConstants::MAX_PLANET_POSITION; $position++) {
                    if (!$player->canColonizePosition($position)) {
                        continue;
                    }

                    if (isset($occupied[$systemWithOffset . ':' . $position])) {
                        continue;
                    }

                    // The size a planet at this position would get, from the host's
                    // own field range (mid positions report the widest range). The
                    // largest empty slot seen so far wins; strict > keeps the
                    // seeded walk order as the tie-break.
                    $fields = $this->planetServiceFactory->planetData($position, false)['fields'][1];
                    if ($fields > $bestFields) {
                        $best = new Coordinate($galaxy, $systemWithOffset, $position);
                        $bestFields = (float) $fields;
                    }
                }
            }
        }

        return $best;
    }
}
