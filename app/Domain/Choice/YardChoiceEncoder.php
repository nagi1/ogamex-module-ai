<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Turns one planet's yard decision (which ship or defence unit to order now, or waiting) into numbers a policy can
 * rank, in the same schema as the economy choices: a unit has its own columns, a building row leaves them at zero.
 * Every value is the host's (price, attack, shield, hull, speed, capacity, standing amount), so a unit a mod adds is
 * described like any other (Gate 1).
 */
class YardChoiceEncoder
{
    /** @var list<string> the yard's own state columns (QueueableUnitPlanner::yardState) */
    public const STATE = [
        'yard_planet_ships', 'yard_planet_defence', 'yard_acct_ships', 'yard_acct_defence', 'yard_owns_colony_ship',
        'yard_owns_probe', 'yard_planet_room', 'yard_under_attack', 'yard_defended_target_seen', 'yard_planet_bare',
    ];

    /** @var list<string> */
    public const CANDIDATE = [
        'type_ship', 'type_defence', 'u_attack', 'u_shield', 'u_hull', 'u_speed', 'u_capacity', 'u_fuel',
        'u_amount', 'u_standing', 'u_strength_per_cost', 'u_total_cost_vs_stock',
    ];

    private const MAX_ROWS = 32;

    public function __construct(private EconomyChoiceEncoder $economy, private QueueableUnitPlanner $units)
    {
    }

    /**
     * @param list<float> $account
     */
    public function point(int $playerId, array $account, PlayerService $player, PlanetService $planet, ?QueueableUnit $teacher, string $key): ChoicePoint
    {
        $rows = $this->units->yardCandidates($planet, $teacher);
        $held = $planet->getResources();
        $stock = max(1.0, $held->metal->get() + $held->crystal->get() + $held->deuterium->get());
        $productionPerHour = max(1.0, $planet->getMetalProductionPerHour() + $planet->getCrystalProductionPerHour() + max(0.0, $planet->getDeuteriumProductionPerHour()));

        $candidates = [];
        $teacherIndex = 0;
        $waitEta = 0.0;
        foreach ($rows as $index => $row) {
            if (count($candidates) >= self::MAX_ROWS - 1 && !($teacher !== null && $teacher->unitId === $row['unit']->id && $teacher->planetId === $planet->getPlanetId())) {
                continue;
            }
            [$features, $eta] = $this->features($player, $planet, $row, $index, $stock, $productionPerHour);
            $waitEta = $eta > 0.0 && ($waitEta === 0.0 || $eta < $waitEta) ? $eta : $waitEta;
            $candidates[] = app()->makeWith(ChoiceCandidate::class, ['objectId' => $row['unit']->id, 'pass' => null, 'reason' => 'yard', 'legal' => $row['legal'], 'features' => $features]);
            $teacherIndex = $teacher !== null && $teacher->unitId === $row['unit']->id && $teacher->planetId === $planet->getPlanetId() ? count($candidates) : $teacherIndex;
        }

        $names = EconomyChoiceEncoder::candidateNames();
        $wait = array_fill(0, count($names), 0.0);
        $wait[0] = 1.0;
        $wait[array_search('legal', $names, true)] = 1.0;
        $wait[array_search('eta_hours', $names, true)] = log1p($waitEta);

        return app()->makeWith(ChoicePoint::class, [
            'playerId' => $playerId,
            'planetId' => $planet->getPlanetId(),
            'kind' => ChoicePoint::YARD,
            'state' => [...$account, ...$this->planetState($player, $planet)],
            'candidates' => [
                app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'wait', 'legal' => true, 'features' => $wait]),
                ...$candidates,
            ],
            'teacherIndex' => $teacherIndex,
            'key' => $key,
        ]);
    }

    /** @return list<float> */
    private function planetState(PlayerService $player, PlanetService $planet): array
    {
        $economy = $this->economy->planetState($planet, false);
        // The economy state ends with the research flag (zero here), the yard flag, the errand flag and the yard's own columns.
        $base = array_slice($economy, 0, count($economy) - 2 - count(self::STATE));
        $yard = $this->units->yardState($player, $planet);

        return [
            ...$base,
            1.0,
            0.0,
            ...array_map(fn (float $value): float => log1p($value), array_values($yard)),
        ];
    }

    /**
     * @param array{unit: \OGame\GameObjects\Models\UnitObject, amount: int, legal: bool, teacherOk: bool} $row
     * @return array{0: list<float>, 1: float}
     */
    private function features(PlayerService $player, PlanetService $planet, array $row, int $index, float $stock, float $productionPerHour): array
    {
        $unit = $row['unit'];
        $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
        $unitCost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
        $total = $unitCost * $row['amount'];
        $attack = $unit->properties->attack->calculate($player)->totalValue;
        $shield = $unit->properties->shield->calculate($player)->totalValue;
        $hull = $unit->properties->structural_integrity->calculate($player)->totalValue;
        $eta = $this->economy->etaHours($planet, $price);
        $names = EconomyChoiceEncoder::candidateNames();
        $features = array_fill(0, count($names), 0.0);
        $set = static function (string $name, float $value) use (&$features, $names): void {
            $features[array_search($name, $names, true)] = $value;
        };

        $set('order', ($index + 1) / self::MAX_ROWS);
        $set('legal', $row['legal'] ? 1.0 : 0.0);
        $set('teacher_ok', $row['teacherOk'] ? 1.0 : 0.0);
        $set('spendable', $row['legal'] ? 1.0 : 0.0);
        $set('cost_metal', log1p($price->metal->get()));
        $set('cost_crystal', log1p($price->crystal->get()));
        $set('cost_deuterium', log1p($price->deuterium->get()));
        $set('cost_hours', log1p($total / $productionPerHour));
        $set('cost_vs_stock', min(10.0, $total / $stock) / 10);
        $set('build_hours', log1p($planet->getUnitConstructionTime($unit->machine_name) * $row['amount'] / 3600));
        $set('unlocks', ($this->economy->unlocks()[$unit->machine_name] ?? 0) / 10);
        $set('eta_hours', log1p($eta));
        $set('type_ship', $unit->type === GameObjectType::Ship ? 1.0 : 0.0);
        $set('type_defence', $unit->type === GameObjectType::Defense ? 1.0 : 0.0);
        $set('u_attack', log1p($attack));
        $set('u_shield', log1p($shield));
        $set('u_hull', log1p($hull));
        $set('u_speed', log1p($unit->properties->speed->calculate($player)->totalValue));
        $set('u_capacity', log1p($unit->properties->capacity->calculate($player)->totalValue));
        $set('u_fuel', log1p($unit->properties->fuel->calculate($player)->totalValue));
        $set('u_amount', log1p($row['amount']));
        $set('u_standing', log1p($planet->getObjectAmount($unit->machine_name)));
        $set('u_strength_per_cost', log1p($unitCost > 0.0 ? ($attack + $shield + $hull) / $unitCost : 0.0));
        $set('u_total_cost_vs_stock', min(10.0, $total / $stock) / 10);

        return [$features, $eta];
    }
}
