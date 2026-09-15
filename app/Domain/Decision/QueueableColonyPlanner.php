<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\GameMissions\ColonisationMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

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
    /** The ship the host's colonisation mission consumes, referenced by the key the host checks. */
    private const COLONY_SHIP = 'colony_ship';

    /** Hard ceiling on coordinate checks per decision, so a full universe can never stall a session. */
    private const MAX_SCANS = 600;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private SettingsService $settings,
    ) {
    }

    public function plan(int $playerId): ?QueueableColony
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $origin = $this->colonyShipPlanet($player->planets->all());
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
     * The planet carrying an idle colony ship.
     *
     * @param array<int, PlanetService> $planets
     */
    private function colonyShipPlanet(array $planets): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName(self::COLONY_SHIP) > 0) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The first empty, colonisable coordinate from a seeded start, bounded.
     *
     * The per-account seed offsets the walk so two accounts do not claim the
     * same slot; the host answers both reach (`canColonizePosition`) and
     * emptiness (`makeForCoordinate` returns null).
     */
    private function emptySlot(PlayerService $player, int $seed): ?Coordinate
    {
        $galaxies = max(1, $this->settings->numberOfGalaxies());
        $systems = max(1, $this->settings->numberOfSystems());
        // The walk is bounded by systems rather than by a running counter: one
        // comparison caps the whole decision at MAX_SCANS coordinates, so a full
        // universe cannot stall a session and the bound never needs a per-check
        // branch.
        $systemsPerGalaxy = min($systems, max(1, intdiv(self::MAX_SCANS, 12 * $galaxies)));

        for ($galaxyOffset = 0; $galaxyOffset < $galaxies; $galaxyOffset++) {
            $galaxy = 1 + (($seed + $galaxyOffset) % $galaxies);

            for ($system = 1; $system <= $systemsPerGalaxy; $system++) {
                $systemWithOffset = 1 + (($system + ($seed >> 4) - 1) % $systems);

                for ($position = UniverseConstants::MIN_PLANET_POSITION; $position <= UniverseConstants::MAX_PLANET_POSITION; $position++) {
                    if (!$player->canColonizePosition($position)) {
                        continue;
                    }

                    $coordinate = new Coordinate($galaxy, $systemWithOffset, $position);
                    if ($this->planetServiceFactory->makeForCoordinate($coordinate, false, PlanetType::Planet) === null) {
                        return $coordinate;
                    }
                }
            }
        }

        return null;
    }
}
