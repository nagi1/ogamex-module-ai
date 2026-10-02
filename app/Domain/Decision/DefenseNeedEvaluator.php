<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Models\AiProfile;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether this planet wants a wall at all, and how big: the size half of the defence decision.
 *
 * `DefenseCompositionPlanner` decides what shape the wall takes; this decides whether there is
 * anything worth defending and how much. What a raider weighs is the pile on the planet and the
 * production that accrues there while the account is away, both in the one currency the game prices
 * everything in, and that value is what the wall is sized to. The doctrine never enters here: it
 * picks the shape, not the size, so a Minimalist and a Bunker are handed the same size and build it
 * differently.
 *
 * Two things decide the number, both host state the module already reads. Presence: the account's
 * own `activity_band` is how often it looks in, so a band that visits once a day leaves a planet's
 * output standing far longer than one that looks every few hours and is exposed to more of it.
 * Contact: a pile only counts once the account has been seen -- an unmolested account spends its
 * surplus rather than standing a wall against it (decision doctrine D10, spend-first) -- but once the
 * host reports a hostile inbound there is no time left to spend it, so the whole pile joins what the
 * wall must cover. The archetype is deliberately not an input: two miners with the same band and the
 * same planet state want the same wall.
 *
 * The need is a value, not a unit count: how many units a wall of this worth is stays the
 * composition planner's business, because the doctrine's ratio and the host's prices live there.
 */
class DefenseNeedEvaluator
{
    /** Metal-equivalent weights, the same ones the rest of the module prices production with. */
    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    private const HOURS_PER_DAY = 24.0;

    public function evaluate(PlayerService $player, PlanetService $planet): ?DefenseNeed
    {
        $profile = AiProfile::query()->where('player_id', $player->getId())->first();
        if ($profile === null) {
            return null;
        }

        $inbound = $this->inbound($player);

        // The ceiling outranks the exposure clock: a planet that already holds the wall the
        // behaviour file caps wants nothing further, whatever the account's production would pile
        // up while it is away. Exposure is unbounded, and left to it the account builds the whole
        // account's wall on one planet.
        if ($this->atCeiling($planet)) {
            return null;
        }

        // What piles up before the account next looks at the planet, plus the pile itself once a
        // hostile is on its way and spending it is no longer an option.
        $exposure = $this->hourlyProduction($planet) * $this->absenceHours($profile->activity_band);
        if ($inbound) {
            $exposure += $this->metalEquivalent($planet->getResources());
        }

        // A planet with no defence at all takes the file's floor before exposure is weighed: a young
        // colony's output is near zero, so exposure alone leaves it naked beside the walled planets
        // the same account already holds (measured live 30 Sep 2026: 7-8 planets at zero defence while
        // one held the wall). One wall unit's worth is all this says; the doctrine still sizes it.
        if ($this->standingUnits($planet) === 0) {
            $floor = $this->minimumDeterrent();
            if ($floor !== null && $exposure < $floor->defenceValue) {
                return $floor;
            }
        }

        // A wall already worth at least this much wants nothing further; the two planners agree on
        // the value through this comparison and nowhere else.
        if ($exposure <= $this->unitValue($planet->getDefenseUnits())) {
            return null;
        }

        return app()->makeWith(DefenseNeed::class, [
            'defenceValue' => $exposure,
            'reason' => 'defense:need:' . $this->contact($inbound),
        ]);
    }

    /**
     * Whether this planet already holds the wall the behaviour file caps, built or paid for in the
     * yard. The yard counts because the host takes the whole price when the order is placed, so a
     * planet with the ceiling queued has bought it (measured live: 50.8M paid but unbuilt units on
     * grand while the planets read under the ceiling on built units alone).
     */
    private function atCeiling(PlanetService $planet): bool
    {
        $ceiling = $this->ceilingUnits();

        if ($ceiling === null) {
            return false;
        }

        return $this->standingUnits($planet) >= $ceiling;
    }

    /** The defence units this planet holds, built plus already ordered. */
    private function standingUnits(PlanetService $planet): int
    {
        return $planet->getDefenseUnits()->getAmount() + $this->pendingDefenseUnits($planet);
    }

    /** Defence this planet has paid for and is waiting on, from the host's own unit queue. */
    private function pendingDefenseUnits(PlanetService $planet): int
    {
        $defenceObjectIds = array_map(
            static fn ($object): int => $object->id,
            ObjectService::getDefenseObjects()
        );

        if ($defenceObjectIds === []) {
            return 0;
        }

        return (int) UnitQueue::query()
            ->where('planet_id', $planet->getPlanetId())
            ->whereIn('object_id', $defenceObjectIds)
            ->where('processed', 0)
            ->sum('object_amount');
    }

    /**
     * The ceiling on one planet's wall from the behaviour file, or null when the file states none.
     * The number is policy, so it lives there and not here; a file without it caps nothing.
     */
    private function ceilingUnits(): ?int
    {
        $parsed = Yaml::parseFile(module_path('AI', 'resources/behavior/defence-doctrines.yaml'));
        $units = is_array($parsed) ? ($parsed['standing_wall_ceiling']['units'] ?? null) : null;

        return is_numeric($units) && (int) $units > 0 ? (int) $units : null;
    }

    /**
     * The floor under the exposure-derived need for a planet that holds nothing, from the behaviour
     * file, or null when the file states none. The number is policy and the reason is its trace, so
     * both live there and not here.
     */
    private function minimumDeterrent(): ?DefenseNeed
    {
        $parsed = Yaml::parseFile(module_path('AI', 'resources/behavior/defence-doctrines.yaml'));
        $block = is_array($parsed) ? ($parsed['minimum_deterrent'] ?? null) : null;
        $value = is_array($block) ? ($block['value'] ?? null) : null;
        $reason = is_array($block) ? ($block['reason'] ?? null) : null;

        if (! is_numeric($value) || (float) $value <= 0.0 || ! is_string($reason)) {
            return null;
        }

        return app()->makeWith(DefenseNeed::class, [
            'defenceValue' => (float) $value,
            'reason' => $reason,
        ]);
    }

    /**
     * How long the account leaves this planet alone between two visits.
     *
     * The activity band is how many times a day the account looks in, so the gap is a day divided by
     * that. A band with no value yet falls back to the middle of the range rather than disabling the
     * wall.
     */
    private function absenceHours(?AiActivityBand $band): float
    {
        return self::HOURS_PER_DAY / max(1, $band?->value ?? AiActivityBand::Regular->value);
    }

    /**
     * Whether a visitor is already on the way: the host's own under-attack answer, so the module
     * keeps no mission-type list.
     */
    private function inbound(PlayerService $player): bool
    {
        return app()->makeWith(FleetMissionService::class, ['player' => $player])->currentPlayerUnderAttack();
    }

    /** Names what the account has recently seen, so a trace can show why the wall grew. */
    private function contact(bool $inbound): string
    {
        return $inbound ? 'inbound' : 'unwatched';
    }

    /** The planet's own output in the one currency, from the host's production figures. */
    private function hourlyProduction(PlanetService $planet): float
    {
        return $planet->getMetalProductionPerHour()
            + self::CRYSTAL_WEIGHT * $planet->getCrystalProductionPerHour()
            + self::DEUTERIUM_WEIGHT * $planet->getDeuteriumProductionPerHour();
    }

    /** The metal-equivalent value of a unit collection, from the host's own raw prices. */
    private function unitValue(UnitCollection $units): float
    {
        $value = 0.0;
        foreach ($units->toArray() as $machineName => $amount) {
            $value += $this->metalEquivalent(ObjectService::getObjectRawPrice($machineName)) * $amount;
        }

        return $value;
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }
}
