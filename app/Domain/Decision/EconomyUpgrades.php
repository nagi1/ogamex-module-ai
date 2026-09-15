<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\RankedExperience;
use Modules\AI\Domain\Routine\RoutineProfile;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resource;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * The planet's own next upgrade: the mine that repays itself fastest, or the storage that is about
 * to overflow.
 *
 * A player does not work down a build order. They compare what a level adds to what it costs and
 * take the best ratio -- "you should prioritize mines with lowest amortization first, i.e. build the
 * mines which repay their cost the fastest" -- and they stop when the next level would not repay
 * itself in the time they intend to still be playing. That is the whole rule, and it is arithmetic
 * over host numbers rather than a list of names: the candidates are the objects the host itself
 * reports as producing resources, their price is the host's price for the next level, and what they
 * add is the difference the host's own production calculation reports between this level and the
 * next. An extension that adds a mine is part of this ranking the moment the host knows about it,
 * and an object that adds no resource production -- a plant, a reactor, a robotics factory -- is
 * simply not a candidate here, because energy has its own rule.
 *
 * Costs and production are compared in one currency using the trade band players use, and the
 * result is a payback in hours. Two things move it, both bounded and both the account's own: a
 * seeded variation whose size is the persona's skill band, so two accounts with the same numbers
 * still order them slightly differently, and a nudge from the account's own remembered outcomes,
 * which resolves a near-tie without ever outvoting the arithmetic or deciding legality.
 *
 * Storage is here rather than in a rule of its own because it is the same decision seen from the
 * other side: a warehouse exists so that production does not stop while nobody is watching, and it
 * is worth building exactly when it would fill inside the time the account is likely to be away.
 * Its capacity comes from the host's own storage formula, so a storage object a mod adds is a
 * candidate too, and a resource this planet does not produce can never fill and is skipped.
 */
class EconomyUpgrades
{
    /** metal-equivalent weights of the accepted trade band, which is how players compare costs. */
    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    /**
     * How long a level may take to repay itself.
     *
     * The published range is "about 2-3 real days at any speed" for a mature account, and a young
     * account is far stricter than that: a fresh mine repays itself in hours, so nothing is gained
     * by accepting a week-long payback before the account has any income at all. The bound
     * therefore starts at two days and relaxes with how deep the planet's production already is,
     * which is the same shape another implementation settled on after measuring it.
     */
    private const PAYBACK_BASE_HOURS = 48.0;

    private const PAYBACK_CAP_HOURS = 168.0;

    private const LEVELS_PER_SLACK = 20.0;

    /**
     * The warehouse has to cover the gap between this account's own visits, so the gap is what the
     * trigger measures against -- not a constant.
     *
     * A warehouse exists so production does not stop while nobody is watching, which makes the
     * absence the only thing that matters: a player who opens the game five times a day is away
     * about five hours and has no use for forty-eight hours of storage, while a casual player away
     * half a day has every use for it. Measured against a fixed 48 hours instead, the trigger was
     * true for every planet at every level -- the grand test ran the whole cohort to level-nine
     * warehouses, hours from overflow yet always "urgent", while the mines underneath sat at level
     * four and never got a turn. The figure is the account's, so it changes with the persona the way
     * the rest of the routine does, and nothing here names a storage object.
     */
    private const HOURS_PER_DAY = 24.0;

    /** Bounded so one remembered outcome cannot dominate the arithmetic. */
    private const MAXIMUM_CASES = 20;

    private const EXPERIENCE_WEIGHT_PERCENT = 20;

    private const EXPERIENCE_MAXIMUM_SHARE = 0.2;

    public function __construct(private readonly ExperienceEngine $experience)
    {
    }

    /**
     * @return list<BuildCandidate> the storage that is about to overflow, then the best-paying upgrades
     */
    public function pending(PlanetService $planet, AiProfile $profile): array
    {
        return [...$this->storage($planet, $profile), ...$this->production($planet, $profile)];
    }

    /** @return list<BuildCandidate> the best-paying production upgrades this planet can pay to widen */
    public function production(PlanetService $planet, AiProfile $profile): array
    {
        $entries = [];
        $levels = [];

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            if (!BuildingQueueObject::accepts($object->machine_name)) {
                continue;
            }

            $gain = $this->productionGainOfNextLevel($planet, $object->machine_name);
            if ($gain <= 0.0) {
                continue;
            }

            $levels[] = $planet->getObjectLevel($object->machine_name);

            $entries[] = [
                'hours' => $this->paybackHours($profile, (int) $object->id, ObjectService::getObjectPrice($object->machine_name, $planet), $gain),
                'buildTime' => $planet->getBuildingConstructionTime($object->machine_name),
                'candidate' => app()->makeWith(BuildCandidate::class, [
                    'buildingId' => $object->id,
                    'reason' => 'economy:' . $object->machine_name,
                ]),
            ];
        }

        $horizon = $this->paybackHorizonHours($this->averageLevel($levels));

        $affordable = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['hours'] <= $horizon,
        ));

        // Shorter first on a tie: a build occupies the planet's only queue slot, so when two levels
        // repay equally the one that gives the slot back sooner is the better use of it.
        usort($affordable, static function (array $left, array $right): int {
            return [$left['hours'], $left['buildTime']] <=> [$right['hours'], $right['buildTime']];
        });

        return array_map(static fn (array $entry): BuildCandidate => $entry['candidate'], $affordable);
    }

    /**
     * @return list<BuildCandidate> storages whose remaining capacity would fill inside an absence
     *
     * A full warehouse stops the planet producing, so this is the more urgent of the economy's two
     * answers -- but only while it really is about to fill inside this account's own absence, which
     * is what keeps it from preempting the mine that pays for everything.
     */
    public function storage(PlanetService $planet, AiProfile $profile): array
    {
        $absence = $this->absenceHours($profile);
        $entries = [];

        foreach (ObjectService::getBuildingObjectsWithStorage() as $object) {
            $hours = $this->timeToFill($planet, $object->machine_name);

            if ($hours === null || $hours >= $absence) {
                continue;
            }

            $entries[] = ['hours' => $hours, 'candidate' => app()->makeWith(BuildCandidate::class, [
                'buildingId' => $object->id,
                'reason' => 'storage:' . $object->machine_name,
            ])];
        }

        usort($entries, static fn (array $left, array $right): int => $left['hours'] <=> $right['hours']);

        return array_map(static fn (array $entry): BuildCandidate => $entry['candidate'], $entries);
    }

    /**
     * Hours until the storage that raises a resource is full, or null when this object cannot help.
     *
     * The capacity a level adds is the host's own storage formula asked twice, so the module never
     * names a storage object, and a resource with no production on this planet can never fill --
     * which is why a metal depot is not built on a planet with no mine.
     */
    private function timeToFill(PlanetService $planet, string $machineName): ?float
    {
        $level = $planet->getObjectLevel($machineName);
        $added = $planet->getBuildingMaxStorage($machineName, $level + 1);

        $current = $planet->getBuildingMaxStorage($machineName);
        $stored = $planet->getResources();

        $hours = array_filter([
            $this->fillHours($planet->metalStorage(), $stored->metal, $planet->getMetalProductionPerHour(), $added->metal->get() - $current->metal->get()),
            $this->fillHours($planet->crystalStorage(), $stored->crystal, $planet->getCrystalProductionPerHour(), $added->crystal->get() - $current->crystal->get()),
            $this->fillHours($planet->deuteriumStorage(), $stored->deuterium, $planet->getDeuteriumProductionPerHour(), $added->deuterium->get() - $current->deuterium->get()),
        ], static fn (?float $value): bool => $value !== null);

        return $hours === [] ? null : min($hours);
    }

    /**
     * How long this account is typically away between two visits, from its own routine.
     *
     * The persona already states how often it looks at the account, so the gap is that figure's
     * reciprocal and no new setting is introduced. It is floored at one session a day so a profile
     * with an unset or implausible routine still has a horizon rather than an infinite one.
     */
    private function absenceHours(AiProfile $profile): float
    {
        return self::HOURS_PER_DAY / max(1, RoutineProfile::fromAiProfile($profile)->sessionsPerDay);
    }

    private function fillHours(Resource $capacity, Resource $stored, float $productionPerHour, float $addedCapacity): ?float
    {
        // A storage that does not raise this resource, or a planet that does not produce it, has
        // nothing that can fill, so it is not a storage decision.
        if ($addedCapacity <= 0.0 || $productionPerHour <= 0.0) {
            return null;
        }

        return max(0.0, $capacity->get() - $stored->get()) / $productionPerHour;
    }

    private function paybackHours(AiProfile $profile, int $objectId, Resources $price, float $gain): float
    {
        return ($this->metalEquivalent($price) / $gain)
            * (1 + $this->personaVariation($profile, $objectId))
            * (1 - $this->rememberedBias($profile, $objectId));
    }

    /** What the host's own production calculation says the next level adds, in one currency. */
    private function productionGainOfNextLevel(PlanetService $planet, string $machineName): float
    {
        $level = $planet->getObjectLevel($machineName);

        // The host scales a building's reported output by the planet's current energy factor, so a
        // planet running a deficit reports nothing for the mine it would fix. Asking both levels at
        // 100% is what makes the comparison a property of the building rather than of today's energy.
        return $this->metalEquivalent($planet->getObjectProduction($machineName, $level + 1, true))
            - $this->metalEquivalent($planet->getObjectProduction($machineName, $level, true));
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }

    /** @param list<int> $levels */
    private function averageLevel(array $levels): float
    {
        return $levels === [] ? 0.0 : array_sum($levels) / count($levels);
    }

    private function paybackHorizonHours(float $averageLevel): float
    {
        return min(
            self::PAYBACK_CAP_HOURS,
            self::PAYBACK_BASE_HOURS * (1 + $averageLevel / self::LEVELS_PER_SLACK),
        );
    }

    /**
     * The persona's taste, up to a few per cent either way.
     *
     * A veteran's order is the arithmetic's order; a novice's is noisier. The value is derived from
     * the account's own seed, so one account has one order it can reproduce, and two accounts with
     * the same numbers still differ from each other.
     */
    private function personaVariation(AiProfile $profile, int $objectId): float
    {
        $unit = (crc32($profile->random_seed . ':economy:' . $objectId) % 1000) / 1000;

        return ($unit - 0.5) * $profile->skill_band->variationWeight() / 100;
    }

    /**
     * A bounded nudge from this account's own finalized outcomes.
     *
     * Only the built object is known before the account has built anything, so every other feature
     * stays unknown and similarity is decided by the object alone. Only a case that matches on every
     * feature the module knows about is evidence about this choice, because the engine's similarity
     * is a mean over shared features -- a partial score describes a different situation rather than
     * a weaker precedent, and object identifiers compare numerically, which would otherwise make an
     * outcome for one building look like faint evidence for its neighbour. An uncertain outcome is
     * worth correspondingly less than a settled one, and the whole term is capped so a remembered
     * outcome can never outvote the arithmetic.
     */
    private function rememberedBias(AiProfile $profile, int $objectId): float
    {
        $weight = (int) config('ai.cognition.experience.decision_weight', self::EXPERIENCE_WEIGHT_PERCENT);

        if ($weight === 0) {
            return 0.0;
        }

        $ranked = $this->experience->rankSimilarExperiences(
            app()->makeWith(ExperienceQuery::class, [
                'playerId' => $profile->player_id,
                'family' => AiExperienceCaseFamily::BuildingUpgrade,
                'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
                'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
                'features' => [AiBuildingExperienceFeature::ObjectId->value => $objectId],
                'limit' => self::MAXIMUM_CASES,
            ]),
        );

        $evidence = array_filter(array_map(
            static fn (RankedExperience $case): float => $case->similarity >= 1.0
                ? $case->utility * (1 - $case->uncertainty)
                : 0.0,
            $ranked,
        ), static fn (float $value): bool => $value !== 0.0);

        if ($evidence === []) {
            return 0.0;
        }

        $bias = $weight / 100 * array_sum($evidence) / count($evidence);

        return max(-self::EXPERIENCE_MAXIMUM_SHARE, min(self::EXPERIENCE_MAXIMUM_SHARE, $bias));
    }
}
