<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The composition half of the defence decision: which unit the account's doctrine is most behind on.
 *
 * `QueueableUnitPlanner` answers *whether* a planet should hold defence; this answers *what shape*
 * that wall takes. The shape is a sourced player doctrine — an anchor unit plus a ratio of the other
 * units per anchor — read from `resources/behavior/defence-doctrines.yaml` at planning time. Ratios
 * live in that file only; nothing here restates one.
 *
 * The units are host objects resolved from the defence registry, so a mod-added defence piece is
 * usable with no edit here, and a doctrine naming a unit the host does not have is an error rather
 * than a silent default. Nothing here decides that more defence is wanted — that is the need
 * evaluator's business. When a need arrives it is the *size* the wall is built to, in the value the
 * evaluator states; how many units that is stays the doctrine's business, because the ratio and the
 * host's prices are here.
 */
class DefenseCompositionPlanner
{
    /** The doctrine an account without a persisted belief builds. */
    private const DEFAULT_DOCTRINE = 'balanced';

    private const DOCTRINE_FILE = 'resources/behavior/defence-doctrines.yaml';

    /** Metal-equivalent weights, the same ones the rest of the module prices objects with. */
    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    public function __construct(private ?string $doctrineFile = null)
    {
    }

    /**
     * The doctrine's next unit for this planet, or null when the wall already matches its ratio or
     * nothing in the gap can legally be queued.
     *
     * A need raises the wall the ratio is applied to: the doctrine's stated anchor is the wall an
     * account builds with nothing at risk, and one that has something to lose builds further out.
     */
    public function plan(PlayerService $player, PlanetService $planet, ?DefenseNeed $need = null): ?DefenseComposition
    {
        $doctrines = $this->doctrines();
        $defenceObjects = $this->defenceObjectsByMachineName();

        $key = $this->afterStopRule($doctrines, $defenceObjects, $this->doctrineKey($player), $planet);

        $doctrine = $doctrines[$key] ?? throw new RuntimeException(
            sprintf('defence-doctrines: the doctrine "%s" is not defined.', $key)
        );

        $anchorCount = (int) $doctrine['anchor']['count'];
        if ($anchorCount <= 0) {
            throw new RuntimeException(sprintf('defence-doctrines: the doctrine "%s" has no anchor count.', $key));
        }

        $anchor = $this->resolveUnit($doctrine['anchor']['unit'], $defenceObjects);

        // The wall is what the planet holds plus what it has already paid for and is waiting on.
        // The yard builds far slower than a fast universe pays, so a target measured on built units
        // alone is re-bought every session and the queue grows without bound: measured live
        // 29 Sep 2026, 21.8M paid rocket launchers pending on grand while 2.9M were built in a day.
        $current = $planet->getDefenseUnits();
        $pending = $this->pendingDefence($planet);
        $held = fn (string $machineName): int => $current->getAmountByMachineName($machineName)
            + ($pending[$machineName] ?? 0);

        // The wall's size in doctrine layers. A wall whose anchor is still below the doctrine's
        // stated count is built to that stated wall; a larger anchor scales every ratio with it, so
        // a bigger wall is the same doctrine rather than a different one. A need extends that
        // further: what the account stands to lose, priced against what one layer of this doctrine
        // costs, is how far out the wall is built.
        $scale = max($held($anchor->machine_name), $anchorCount);

        if ($need !== null) {
            $scale = max($scale, (int) ceil($need->defenceValue / $this->layerValue($anchor, $doctrine, $defenceObjects)));
        }

        $best = null;
        $bestBehind = 0.0;

        foreach ($doctrine['ratio'] as $name => $ratio) {
            $unit = $this->resolveUnit($name, $defenceObjects);
            $target = (int) ceil($ratio * $scale / $anchorCount);
            $have = $held($unit->machine_name);

            if ($have >= $target) {
                continue;
            }

            // Largest shortfall as a fraction of the target; the doctrine's first entry wins a tie.
            $behind = ($target - $have) / $target;
            if ($behind <= $bestBehind) {
                continue;
            }

            $affordable = $this->queueableAmount($planet, $unit);
            if ($affordable <= 0) {
                continue;
            }

            $best = app()->makeWith(DefenseComposition::class, [
                'unit' => $unit,
                'amount' => min($target - $have, $affordable),
                'doctrine' => $key,
                'reason' => 'defense:'.$key.':'.$unit->machine_name,
            ]);
            $bestBehind = $behind;
        }

        return $best;
    }

    /**
     * The doctrine key the account's persisted belief builds to, or the default when it has none.
     */
    private function doctrineKey(PlayerService $player): string
    {
        $profile = AiProfile::query()->where('player_id', $player->getId())->first();

        return $profile?->defense_doctrine?->doctrineKey() ?? self::DEFAULT_DOCTRINE;
    }

    /**
     * Defence this planet has already paid for and is waiting on, by machine name.
     *
     * The host takes the whole price when the order is placed, so an order in the yard is a wall the
     * account already owns; without it the doctrine buys the same shortfall again every session.
     * One read per planet per plan, resolved through the host's object registry so a mod-added
     * defence piece needs no name here.
     *
     * @return array<string, int>
     */
    private function pendingDefence(PlanetService $planet): array
    {
        $pending = [];

        $rows = UnitQueue::query()
            ->where('planet_id', $planet->getPlanetId())
            ->where('processed', 0)
            ->selectRaw('object_id, SUM(object_amount) AS amount')
            ->groupBy('object_id')
            ->pluck('amount', 'object_id');

        foreach ($rows as $objectId => $amount) {
            $machineName = ObjectService::getObjectById((int) $objectId)->machine_name;
            $pending[$machineName] = (int) $amount;
        }

        return $pending;
    }

    /**
     * The doctrine the wall moves to once it outgrows the one it started in.
     *
     * The handover is stated in the doctrine file ("swap to X once the wall passes N ..."), so the
     * target and the threshold are read from there rather than repeated as constants here.
     *
     * ponytail: the sentence is matched by shape, so rewording it silently disables the handover.
     * Upgrade path: give the file a structured `stop_rule: {doctrine, anchor_at}` mapping when a
     * second doctrine needs a handover.
     *
     * @param  array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}>  $doctrines
     * @param  array<string, UnitObject>  $defenceObjects
     */
    private function afterStopRule(array $doctrines, array $defenceObjects, string $key, PlanetService $planet): string
    {
        $rule = $doctrines[$key]['stop_rule'] ?? null;
        if (!is_string($rule) || preg_match('/swap to ([a-z_]+) once the wall passes (\d+)/', $rule, $matches) !== 1) {
            return $key;
        }

        $anchor = $this->resolveUnit($doctrines[$key]['anchor']['unit'], $defenceObjects);
        $wall = $planet->getDefenseUnits()->getAmountByMachineName($anchor->machine_name);

        return $wall > (int) $matches[2] ? $matches[1] : $key;
    }

    /**
     * What one anchor unit of this doctrine is worth, from the host's own prices: the anchor itself
     * plus its share of every ratio unit.
     *
     * The ratio is stated per anchor count, so a wall of scale S holds S anchors and ratio/100 of
     * each other unit per anchor -- the same arithmetic the targets below are derived with. Pricing
     * it here is what lets a need stated in value become a scale without the evaluator knowing a
     * single price or ratio.
     *
     * @param  array{anchor: array{unit: string, count: int}, ratio: array<string, int>}  $doctrine
     * @param  array<string, UnitObject>  $defenceObjects
     */
    private function layerValue(UnitObject $anchor, array $doctrine, array $defenceObjects): float
    {
        $ratioValue = 0.0;
        foreach ($doctrine['ratio'] as $name => $ratio) {
            $unit = $this->resolveUnit($name, $defenceObjects);
            $ratioValue += $ratio * $this->metalEquivalent(ObjectService::getObjectRawPrice($unit->machine_name));
        }

        return $this->metalEquivalent(ObjectService::getObjectRawPrice($anchor->machine_name))
            + $ratioValue / (int) $doctrine['anchor']['count'];
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }

    /**
     * How many of a unit this planet can put in the yard now, or 0 when the host would not take it.
     *
     * Requirements and character class are the host's; the amount the planet can pay for is the
     * host's own price arithmetic, so the planner never prices a unit itself.
     */
    private function queueableAmount(PlanetService $planet, UnitObject $unit): int
    {
        if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)
            || !ObjectService::objectCharacterClassMet($unit->machine_name, $planet)) {
            return 0;
        }

        return ObjectService::getObjectMaxBuildAmount($unit->machine_name, $planet, true);
    }

    /**
     * The doctrine's name, resolved to a host defence object.
     *
     * The names in the file are the host's own, so they are matched against the host registry by
     * normalising to the registry's machine-name form: the display title is translated and may
     * pluralise ("Anti-Ballistic Missiles"), so it is not a stable key. A name the host does not
     * have is an error.
     *
     * @param  array<string, UnitObject>  $defenceObjects
     */
    private function resolveUnit(string $name, array $defenceObjects): UnitObject
    {
        $machineName = strtolower(preg_replace('/[\s-]+/', '_', $name) ?? '');

        return $defenceObjects[$machineName] ?? throw new RuntimeException(
            sprintf('defence-doctrines: no host defence object named "%s".', $name)
        );
    }

    /**
     * The host's defence objects, keyed by machine name.
     *
     * @return array<string, UnitObject>
     */
    private function defenceObjectsByMachineName(): array
    {
        $objects = [];

        foreach (ObjectService::getDefenseObjects() as $object) {
            $objects[$object->machine_name] = $object;
        }

        return $objects;
    }

    /**
     * The doctrine definitions from the behaviour file.
     *
     * @return array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}>
     */
    private function doctrines(): array
    {
        $parsed = Yaml::parseFile($this->doctrineFile ?? module_path('AI', self::DOCTRINE_FILE));

        if (!is_array($parsed) || !isset($parsed['doctrines']) || !is_array($parsed['doctrines'])) {
            throw new RuntimeException('defence-doctrines: the file must define a doctrines mapping.');
        }

        /** @var array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}> $doctrines */
        $doctrines = $parsed['doctrines'];

        return $doctrines;
    }
}
