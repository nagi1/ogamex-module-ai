<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Login\GamePhaseMachine;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Enums\GamePhase;
use Modules\AI\Models\AiProfile;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Highscore;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use OGame\Services\SettingsService;

/**
 * Turns an economy choice into numbers a policy can rank. Every number is read from the host at the
 * moment of the choice (prices, times, production, storage, requirements) or from the account's own
 * profile, and no object is identified by id or name, so an object a mod adds is described like any
 * other (Gate 1). The schema version travels with every recorded row; bump it when a feature changes.
 */
class EconomyChoiceEncoder
{
    public const VERSION = 2;

    /** The planner's pass names, in its order (QueueableBuildingPlanner::passes). */
    private const PASSES = ['wall', 'doctrine', 'storage', 'surplus', 'routine', 'ambition'];

    private const MAX_ROWS = 32;

    /** @var array<string, int>|null how many objects name each object as a requirement */
    private static ?array $unlocks = null;

    public function __construct(
        private GamePhaseMachine $phases,
        private BuildingQueueService $buildingQueue,
        private ResearchQueueService $researchQueue,
        private SettingsService $settings,
    ) {
    }

    /** @return list<string> */
    public static function stateNames(): array
    {
        return [
            'acct_planets', 'acct_resources', 'acct_production', 'acct_score', 'acct_rank_pct', 'acct_age_days',
            'acct_research_levels', 'acct_lab_busy', 'speed_economy',
            ...array_map(static fn (GamePhase $phase): string => 'phase_' . $phase->value, GamePhase::cases()),
            ...array_map(static fn (AiArchetype $archetype): string => 'archetype_' . strtolower($archetype->name), AiArchetype::cases()),
            'skill',
            ...array_map(static fn (AiStockpileStrategy $strategy): string => 'stockpile_' . strtolower($strategy->name), AiStockpileStrategy::cases()),
            'pl_metal', 'pl_crystal', 'pl_deuterium', 'pl_metal_fill', 'pl_crystal_fill', 'pl_deuterium_fill',
            'pl_metal_ph', 'pl_crystal_ph', 'pl_deuterium_ph', 'pl_energy_max', 'pl_energy_used', 'pl_factor',
            'pl_temperature', 'pl_fields_used', 'pl_fields_max', 'pl_is_moon', 'pl_position', 'pl_queue_length',
            'kind_research', 'kind_yard', 'kind_errand',
            ...YardChoiceEncoder::STATE,
        ];
    }

    /** @return list<string> */
    public static function candidateNames(): array
    {
        return [
            'is_wait', 'type_building', 'type_station', 'type_research',
            ...array_map(static fn (string $pass): string => 'pass_' . $pass, self::PASSES),
            'order', 'legal', 'teacher_ok', 'spendable',
            'cost_metal', 'cost_crystal', 'cost_deuterium', 'cost_hours', 'cost_vs_stock', 'build_hours',
            'level', 'gain_ph', 'payback_hours', 'energy_delta', 'storage_gain', 'unlocks', 'eta_hours',
            ...YardChoiceEncoder::CANDIDATE,
            ...ErrandChoiceEncoder::candidateNames(),
        ];
    }

    /**
     * The account-wide part of the state, read once per login.
     *
     * @return array{state: list<float>, value: float}
     */
    public function account(AiProfile $profile, PlayerService $player): array
    {
        $resources = 0.0;
        $production = 0.0;
        $invested = 0.0;
        foreach ($player->planets->all() as $planet) {
            $held = $planet->getResources();
            $resources += $held->metal->get() + $held->crystal->get() + $held->deuterium->get();
            $production += max(0.0, $planet->getMetalProductionPerHour()) + max(0.0, $planet->getCrystalProductionPerHour()) + max(0.0, $planet->getDeuteriumProductionPerHour());
            $invested += $planet->getPlanetScore() * 1000.0;
        }
        $invested += $player->getResearchScore() * 1000.0;

        $researchLevels = 0;
        foreach (ObjectService::getResearchObjects() as $object) {
            $researchLevels += $player->getResearchLevel($object->machine_name);
        }

        $highscore = Highscore::query()->where('player_id', $player->getId())->first();
        $ranked = max(1, Highscore::query()->count());
        $created = $player->getUser()->created_at;
        $phase = $this->phases->of($player);
        $lab = $player->planets->all() === [] ? false : $this->researchQueue->retrieveQueue($player->planets->current())->getCurrentlyBuildingFromQueue() !== null;

        return [
            'state' => [
                log1p($player->planets->planetCount()),
                log1p($resources),
                log1p($production),
                log1p((float) ($highscore->general ?? 0)),
                $highscore?->general_rank !== null ? min(1.0, $highscore->general_rank / $ranked) : 1.0,
                log1p($created === null ? 0.0 : max(0.0, $created->diffInSeconds(now()) / 86400.0)),
                log1p($researchLevels),
                $lab ? 1.0 : 0.0,
                log(max(1, $this->settings->economySpeed())),
                ...array_map(static fn (GamePhase $case): float => $case === $phase ? 1.0 : 0.0, GamePhase::cases()),
                ...array_map(static fn (AiArchetype $case): float => $case === $profile->archetype ? 1.0 : 0.0, AiArchetype::cases()),
                ($profile->skill_band->value - 1) / 2,
                ...array_map(static fn (AiStockpileStrategy $case): float => $case === $profile->stockpile_strategy ? 1.0 : 0.0, AiStockpileStrategy::cases()),
            ],
            'value' => $invested + $resources,
        ];
    }

    /**
     * @param list<float> $account
     * @param array{planet: PlanetService, research: bool, candidates: list<array{candidate: BuildCandidate, pass: string, legal: bool, teacherOk: bool, spendable: bool}>, teacher: ?int} $set
     */
    public function point(int $playerId, array $account, array $set, string $key): ChoicePoint
    {
        $planet = $set['planet'];
        $held = $planet->getResources();
        $productionPerHour = max(1.0, $planet->getMetalProductionPerHour() + $planet->getCrystalProductionPerHour() + max(0.0, $planet->getDeuteriumProductionPerHour()));
        $stock = max(1.0, $held->metal->get() + $held->crystal->get() + $held->deuterium->get());

        $rows = [];
        $waitEta = 0.0;
        foreach ($set['candidates'] as $index => $row) {
            [$features, $eta] = $this->candidateFeatures($planet, $set['research'], $row, $index, $productionPerHour, $stock);
            $waitEta = $eta > 0.0 && ($waitEta === 0.0 || $eta < $waitEta) ? $eta : $waitEta;
            $rows[] = app()->makeWith(ChoiceCandidate::class, [
                'objectId' => $row['candidate']->buildingId,
                'pass' => $row['pass'],
                'reason' => $row['candidate']->reason,
                'legal' => $row['legal'],
                'features' => $features,
            ]);
        }

        // Waiting is always legal; what it buys is the soonest thing the planet cannot yet afford.
        $wait = array_fill(0, count(self::candidateNames()), 0.0);
        $wait[0] = 1.0;
        $wait[array_search('legal', self::candidateNames(), true)] = 1.0;
        $wait[array_search('eta_hours', self::candidateNames(), true)] = log1p($waitEta);

        return app()->makeWith(ChoicePoint::class, [
            'playerId' => $playerId,
            'planetId' => $planet->getPlanetId(),
            'kind' => $set['research'] ? ChoicePoint::RESEARCH : ChoicePoint::BUILDING,
            'state' => [...$account, ...$this->planetState($planet, $set['research'])],
            'candidates' => [
                app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'wait', 'legal' => true, 'features' => $wait]),
                ...array_slice($rows, 0, self::MAX_ROWS - 1),
            ],
            'teacherIndex' => $set['teacher'] === null ? 0 : $set['teacher'] + 1,
            'key' => $key,
        ]);
    }

    /** @return list<float> the planet's own state for a building or research choice; a yard choice appends its own */
    public function planetState(PlanetService $planet, bool $research): array
    {
        $held = $planet->getResources();
        $fill = static fn (float $amount, float $capacity): float => $capacity > 0 ? min(1.0, $amount / $capacity) : 0.0;
        $fieldsMax = max(1, $planet->getPlanetFieldMax());

        return [
            log1p($held->metal->get()),
            log1p($held->crystal->get()),
            log1p($held->deuterium->get()),
            $fill($held->metal->get(), $planet->metalStorage()->get()),
            $fill($held->crystal->get(), $planet->crystalStorage()->get()),
            $fill($held->deuterium->get(), $planet->deuteriumStorage()->get()),
            $this->signedLog($planet->getMetalProductionPerHour()),
            $this->signedLog($planet->getCrystalProductionPerHour()),
            $this->signedLog($planet->getDeuteriumProductionPerHour()),
            log1p(max(0.0, $planet->energyProduction()->get())),
            log1p(max(0.0, $planet->energyConsumption()->get())),
            $planet->getResourceProductionFactor() / 100,
            $planet->getPlanetTempAvg() / 100,
            min(1.0, $planet->getBuildingCount() / $fieldsMax),
            $fieldsMax / 200,
            $planet->isMoon() ? 1.0 : 0.0,
            $planet->getPlanetCoordinates()->position / 15,
            count($this->buildingQueue->retrieveQueueItems($planet)) / 5,
            $research ? 1.0 : 0.0,
            0.0,
            0.0,
            ...array_fill(0, count(YardChoiceEncoder::STATE), 0.0),
        ];
    }

    /**
     * @param array{candidate: BuildCandidate, pass: string, legal: bool, teacherOk: bool, spendable: bool} $row
     * @return array{0: list<float>, 1: float} the features and the hours until the planet can pay the price
     */
    private function candidateFeatures(PlanetService $planet, bool $research, array $row, int $index, float $productionPerHour, float $stock): array
    {
        $object = ObjectService::getObjectById($row['candidate']->buildingId);
        $machineName = $object->machine_name;
        $player = $planet->getPlayer();
        $level = $research ? (int) $player?->getResearchLevel($machineName) : $planet->getObjectLevel($machineName);
        $price = ObjectService::getObjectPrice($machineName, $planet);
        $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
        $seconds = $research ? $planet->getTechnologyResearchTime($machineName) : $planet->getBuildingConstructionTime($machineName);
        [$gain, $energy] = $research ? [0.0, 0.0] : $this->productionDelta($planet, $machineName, $level);
        $eta = $this->etaHours($planet, $price);

        return [[
            0.0,
            $object->type === GameObjectType::Building ? 1.0 : 0.0,
            $object->type === GameObjectType::Station ? 1.0 : 0.0,
            $object->type === GameObjectType::Research ? 1.0 : 0.0,
            ...array_map(static fn (string $pass): float => $pass === $row['pass'] ? 1.0 : 0.0, self::PASSES),
            ($index + 1) / self::MAX_ROWS,
            $row['legal'] ? 1.0 : 0.0,
            $row['teacherOk'] ? 1.0 : 0.0,
            $row['spendable'] ? 1.0 : 0.0,
            log1p($price->metal->get()),
            log1p($price->crystal->get()),
            log1p($price->deuterium->get()),
            log1p($cost / $productionPerHour),
            min(10.0, $cost / $stock) / 10,
            log1p($seconds / 3600),
            $level / 40,
            $this->signedLog($gain),
            log1p($gain > 0.0 ? min(10_000.0, $cost / $gain) : 10_000.0),
            $this->signedLog($energy),
            log1p($research ? 0.0 : $this->storageGain($planet, $machineName, $level)),
            ($this->unlocks()[$machineName] ?? 0) / 10,
            log1p($eta),
            ...array_fill(0, count(YardChoiceEncoder::CANDIDATE) + count(ErrandChoiceEncoder::candidateNames()), 0.0),
        ], $eta];
    }

    /** @return array{0: float, 1: float} resources per hour and energy the next level adds, as the host computes production */
    private function productionDelta(PlanetService $planet, string $machineName, int $level): array
    {
        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            if ($object->machine_name !== $machineName) {
                continue;
            }
            $now = $planet->getObjectProduction($machineName, $level, true);
            $next = $planet->getObjectProduction($machineName, $level + 1, true);

            return [
                ($next->metal->get() + $next->crystal->get() + $next->deuterium->get()) - ($now->metal->get() + $now->crystal->get() + $now->deuterium->get()),
                $next->energy->get() - $now->energy->get(),
            ];
        }

        return [0.0, 0.0];
    }

    private function storageGain(PlanetService $planet, string $machineName, int $level): float
    {
        foreach (ObjectService::getBuildingObjectsWithStorage() as $object) {
            if ($object->machine_name !== $machineName) {
                continue;
            }
            $now = $planet->getBuildingMaxStorage($machineName, $level);
            $next = $planet->getBuildingMaxStorage($machineName, $level + 1);

            return max(0.0, ($next->metal->get() + $next->crystal->get() + $next->deuterium->get()) - ($now->metal->get() + $now->crystal->get() + $now->deuterium->get()));
        }

        return 0.0;
    }

    /** Hours until the planet's own production covers the price; 0 when it already can. */
    public function etaHours(PlanetService $planet, Resources $price): float
    {
        $held = $planet->getResources();
        $hours = 0.0;
        foreach ([
            [$price->metal->get(), $held->metal->get(), $planet->getMetalProductionPerHour()],
            [$price->crystal->get(), $held->crystal->get(), $planet->getCrystalProductionPerHour()],
            [$price->deuterium->get(), $held->deuterium->get(), $planet->getDeuteriumProductionPerHour()],
        ] as [$cost, $stored, $perHour]) {
            if ($cost <= $stored) {
                continue;
            }
            $hours = max($hours, $perHour > 0.0 ? ($cost - $stored) / $perHour : 10_000.0);
        }

        return min(10_000.0, $hours);
    }

    /** @return array<string, int> */
    public function unlocks(): array
    {
        if (self::$unlocks !== null) {
            return self::$unlocks;
        }

        $counts = [];
        foreach (ObjectService::getObjects() as $object) {
            foreach ($object->requirements as $requirement) {
                $counts[$requirement->object_machine_name] = ($counts[$requirement->object_machine_name] ?? 0) + 1;
            }
        }

        return self::$unlocks = $counts;
    }

    public function signedLog(float $value): float
    {
        return $value < 0 ? -log1p(-$value) : log1p($value);
    }
}
