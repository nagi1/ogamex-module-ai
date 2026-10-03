<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\DarkMatterService;
use OGame\Services\PlanetMoveService;
use OGame\Services\SettingsService;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether this account should move a planet from a poor slot to a better free one in the same system
 * (LOOP-004). A planet on an extreme slot stays small and cold for good; a player with the dark matter
 * to spare buys the move. The host prices and gates it: this only proposes a planet that is on a poor
 * slot, has no pending move or cooldown, and a free preferred slot beside it, and only when the account
 * already holds the cost.
 */
class QueueableRelocationPlanner
{
    private const BEHAVIOR_FILE = '/resources/behavior/planet-relocation.yaml';

    /** @var array<string, list<int>>|null */
    private ?array $slots = null;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private PlanetMoveService $planetMoveService,
        private DarkMatterService $darkMatterService,
        private SettingsService $settingsService,
    ) {
    }

    public function plan(int $playerId): ?QueueableRelocation
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }
        $user = User::query()->find($playerId);
        if ($user === null || !$this->darkMatterService->canAfford($user, (int) $this->settingsService->get('planet_relocation_cost', 240000))) {
            return null;
        }

        $slots = $this->slots();
        $player = $this->playerServiceFactory->make($playerId, true);
        foreach ($player->planets->all() as $planet) {
            if ($planet->getPlanetType() === PlanetType::Moon) {
                continue;
            }

            $here = $planet->getPlanetCoordinates();
            if (!in_array($here->position, $slots['poor_slots'], true)
                || $this->planetMoveService->getActiveMoveForPlanet($planet) !== null
                || $this->planetMoveService->getCooldownSecondsForPlanet($planet) > 0) {
                continue;
            }

            foreach ($slots['preferred_slots'] as $position) {
                $target = new Coordinate($here->galaxy, $here->system, $position);
                if ($this->planetServiceFactory->makePlanetForCoordinate($target, false) === null) {
                    return new QueueableRelocation($planet->getPlanetId(), $target->galaxy, $target->system, $target->position);
                }
            }
        }

        return null;
    }

    /** @return array<string, list<int>> */
    private function slots(): array
    {
        return $this->slots ??= Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);
    }
}
