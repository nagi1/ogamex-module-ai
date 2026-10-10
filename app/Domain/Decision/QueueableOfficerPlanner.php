<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\DarkMatterService;
use OGame\Services\OfficerService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether this account should buy a premium officer now (premium-officers.md). The host sells them and prices them
 * (OfficerService); this proposes one whose benefit the account already uses (resources/behavior/officers.yaml), that
 * is not already held, and only when the dark matter above the reserve pays for it. An officer the doctrine does not
 * name is still proposed last, so one the host adds is reachable with no module edit.
 */
class QueueableOfficerPlanner
{
    private const BEHAVIOR_FILE = '/resources/behavior/officers.yaml';

    /** @var array<string, mixed>|null */
    private ?array $doctrine = null;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private OfficerService $officerService,
        private DarkMatterService $darkMatterService,
        private BuildingQueueService $buildingQueueService,
        private ResearchQueueService $researchQueueService,
    ) {
    }

    public function plan(int $playerId): ?QueueableOfficer
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        $user = User::query()->find($playerId);
        if ($user === null) {
            return null;
        }

        $spare = $this->darkMatterService->getBalance($user) - (int) $this->doctrine()['reserve_floor'];
        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = array_values($player->planets->all());
        $planetId = ($planets[0] ?? null)?->getPlanetId();
        if ($planetId === null || $spare <= 0) {
            return null;
        }

        foreach ($this->prospects() as $key => $when) {
            if (!isset(OfficerService::COSTS[$key]) || !$this->wanted($user, $key) || ($when !== null && !$this->holds($player, $when))) {
                continue;
            }

            $days = $this->affordableDays($key, $spare);
            if ($days !== null) {
                return new QueueableOfficer($key, $days, (int) $planetId);
            }
        }

        return null;
    }

    /**
     * The doctrine's officers in its order, then every other officer the host sells with no condition.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function prospects(): array
    {
        $doctrine = $this->doctrine();
        $prospects = [];
        foreach ((array) $doctrine['order'] as $entry) {
            $prospects[(string) $entry['officer']] = (array) $entry['when'];
        }

        foreach (array_keys(OfficerService::COSTS) as $key) {
            if (!array_key_exists($key, $prospects) && !in_array($key, (array) $doctrine['ignore'], true)) {
                $prospects[$key] = null;
            }
        }

        return $prospects;
    }

    /** Not held, or about to lapse: a player who keeps an officer renews her rather than going without. */
    private function wanted(User $user, string $key): bool
    {
        if (!$this->officerService->isActive($user, $key)) {
            return true;
        }

        $until = $this->officerService->getOfficer($user)->getAttribute($key . '_until');

        return $until !== null && $until->lessThan(now()->addDays((int) $this->doctrine()['renew_days']));
    }

    /** The long term when the reserve survives it, else the short one, else nothing. */
    private function affordableDays(string $key, int $spare): int|null
    {
        $doctrine = $this->doctrine();
        foreach ([(int) $doctrine['long_days'], (int) $doctrine['default_days']] as $days) {
            $cost = OfficerService::COSTS[$key][$days] ?? null;
            if (in_array($days, OfficerService::DURATIONS, true) && $cost !== null && $cost <= $spare) {
                return $days;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $when */
    private function holds(PlayerService $player, array $when): bool
    {
        foreach ($when as $fact => $needed) {
            $holds = match ($fact) {
                'queue_items' => $this->mostQueued($player) >= (int) $needed,
                'building' => $this->highestLevel($player, (string) $needed) >= (int) ($when['level'] ?? 1),
                'ships' => $this->standing($player, false) >= (int) $needed,
                'defence_units' => $this->standing($player, true) >= (int) $needed,
                'research_queued' => $this->researchQueueService->activeResearchQueueItemCount($player) >= (int) $needed,
                'level' => true,
                default => false,
            };

            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    private function mostQueued(PlayerService $player): int
    {
        return (int) collect($player->planets->all())->max(fn ($planet): int => $this->buildingQueueService->retrieveQueueItems($planet)->count());
    }

    private function highestLevel(PlayerService $player, string $machineName): int
    {
        return (int) collect($player->planets->all())->max(static fn ($planet): int => $planet->getObjectLevel($machineName));
    }

    private function standing(PlayerService $player, bool $defence): int
    {
        return (int) collect($player->planets->all())->sum(static fn ($planet): int => ($defence ? $planet->getDefenseUnits() : $planet->getShipUnits())->getAmount());
    }

    /** @return array<string, mixed> */
    private function doctrine(): array
    {
        return $this->doctrine ??= Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);
    }
}
