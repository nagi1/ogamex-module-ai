<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Raid\RaidEstimate;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiRaidExperienceFeature;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Services\LootService;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\GameObjects\Models\Units\UnitEntry;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Decides whether an espionage report is worth acting on, and how.
 *
 * The three checks are the plan's raid policy, all named as play: the bashing
 * limit (no more than six attacks on one planet in a day), the profit test (the
 * sampled lower-tail net profit must stay positive), and fresh intel (a stale
 * report is not acted on). The fleet and the defender are read from the host —
 * the estimator samples the host's own battle engine — so no target, unit or
 * price is named here.
 */
class RaidPlanner
{
    /** The host's hard bashing limit: at most six attacks on one target per day. */
    private const BASHING_LIMIT = 6;

    private const BASHING_WINDOW_HOURS = 24;

    /** A farm is not re-hit inside this window, however many sessions run (a machine signature otherwise). */
    private const RAID_COOLDOWN_HOURS = 6;

    /** A target with this many recent raid outcomes is judged on what actually landed. */
    private const BLACKLIST_RAIDS = 3;

    private const BLACKLIST_WINDOW_DAYS = 7;

    /** Metal-equivalent loot a farm must average, across the judged raids, or it is left alone. */
    private const BLACKLIST_LOOT_FLOOR = 10_000.0;

    /** RAID-011: loot-to-fuel ratio a raid must clear before it flies (metal-equivalent loot : deuterium). */
    private const LOOT_TIER_FARM = 3.0;

    private const LOOT_TIER_DEFENDED = 2.0;

    /** RAID-009: the fleet raids on the storage-fill schedule, so the warehouse must be near full. */
    private const RAID_STORAGE_FILL_RATIO = 0.8;

    /**
     * The fraction of sampled runs the attacking fleet must survive before a
     * raid flies. The fleet-loss rate is its complement (1 - SURVIVAL_FLOOR): a
     * coin-flip that profits on paper still loses the fleet too often. ponytail:
     * one unmeasured floor for every persona; per-archetype tightening is the
     * upgrade path once play data shows it varies.
     */
    private const SURVIVAL_FLOOR = 0.8;

    /**
     * The account's own player, loaded once per planner instance. A skill-pass
     * screen is read-only and the host flushes the factory cache before every
     * job, so the first load is fresh and the per-report repeats are not.
     *
     * @var array<int, PlayerService>
     */
    private array $players = [];

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private NativeRaidEstimator $raidEstimator,
    ) {
    }

    private function player(int $playerId): PlayerService
    {
        return $this->players[$playerId] ??= $this->playerServiceFactory->make($playerId, true);
    }

    /**
     * Whether the account's fleet planet has a warehouse worth flying for.
     *
     * A fleeter raids on the storage-fill schedule (8-12h), not ad hoc every
     * session: the fleet flies when the mines have filled the warehouse
     * (RAID-009). The ratio is the corpus' own near-full threshold (E3); the
     * exact number is persona flavour.
     */
    public function storageReady(int $playerId): bool
    {
        if (!User::query()->whereKey($playerId)->exists()) {
            return true;
        }

        $player = $this->player($playerId);
        $origin = $this->origin($player);
        if ($origin === null) {
            return true;
        }

        $origin = $this->planetServiceFactory->makeForPlayer($player, $origin->getPlanetId(), false);
        $stored = $origin->metal()->get() + $origin->crystal()->get() + $origin->deuterium()->get();
        $capacity = $origin->metalStorage()->get() + $origin->crystalStorage()->get() + $origin->deuteriumStorage()->get();

        if ($capacity <= 0) {
            return false;
        }

        return $stored / $capacity >= self::RAID_STORAGE_FILL_RATIO;
    }

    public function plan(int $playerId, int $reportId): ?QueueableRaid
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $report = EspionageReport::query()->find($reportId);
        if ($report === null) {
            return null;
        }

        $player = $this->player($playerId);
        $origin = $this->origin($player);
        if ($origin === null) {
            return null;
        }

        // The fuel and loot quotes need the origin's owner context, which the
        // planets collection does not carry by itself.
        $origin = $this->planetServiceFactory->makeForPlayer($player, $origin->getPlanetId(), false);

        $target = $this->target($report);
        if ($target === null) {
            return null;
        }

        if (!$this->withinBashingLimit($playerId, $target->getPlanetId())) {
            return null;
        }

        if (!$this->withinCooldown($playerId, $target->getPlanetId())) {
            return null;
        }

        if ($this->blacklisted($playerId, (int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position)) {
            return null;
        }

        // The trip is priced from the origin fleet alone and the hold caps what
        // any run can bring home, so the best possible haul is known before a
        // single draw is taken. When even that ceiling cannot pay for the flight
        // the 50-sample screen is pure cost: p20Loot never exceeds it, so no run
        // the screen would have kept is turned away here (RAID-006, RAID-011).
        $fuel = $this->roundTripFuel($player, $origin, $target);
        $defended = $target->getDefenseUnits()->units !== [];
        $ceilingLoot = $this->raidEstimator->metalEquivalent($this->maximumLoot($player, $origin, $target));
        if (!$this->clearsLootTier($ceilingLoot, $fuel, $defended)) {
            return null;
        }

        $estimate = $this->defencelessTarget($target)
            ? $this->defencelessEstimate($player, $origin, $target)
            : $this->raidEstimator->estimate($playerId, $origin->getPlanetId(), $target->getPlanetId(), $profile->random_seed);
        if ($estimate->samples === 0) {
            return null;
        }

        // A fleeter asks "will I survive?" before "does it profit?". Refuse when
        // the sampled fleet is wiped more than 1 - SURVIVAL_FLOOR of the time.
        if ($estimate->pWin < self::SURVIVAL_FLOOR) {
            return null;
        }

        if ($estimate->p20NetProfit <= 0.0) {
            return null;
        }

        // The sampled profit is loot minus losses only. A raid also burns
        // deuterium to fly there and back, so a distant farm that spends more
        // fuel than the tier allows is refused even when it would "profit"
        // (RAID-006, RAID-011).
        if (!$this->clearsLootTier($estimate->p20Loot, $fuel, $defended)) {
            return null;
        }

        // The launch is not the stock (U6): the smallest counter-selected hulls whose single
        // simulation survives this target fly, not the whole garage.
        $launchUnits = $this->launchUnits($playerId, $player, $origin, $target, $profile->random_seed);
        if ($launchUnits === null) {
            return null;
        }

        return app()->makeWith(QueueableRaid::class, [
            'originPlanetId' => $origin->getPlanetId(),
            'targetGalaxy' => (int) $report->planet_galaxy,
            'targetSystem' => (int) $report->planet_system,
            'targetPosition' => (int) $report->planet_position,
            'targetType' => (int) $report->planet_type,
            'missionType' => AttackMission::getTypeId(),
            'launchUnits' => $launchUnits,
        ]);
    }

    /**
     * The launch subset: enough cargo for the haul plus the smallest counter-selected hulls whose
     * single simulation survives this target (U6).
     *
     * The counter order is the host's own rapid-fire graph — a hull that shreds the target's mix
     * ranks first and one the target shreds back is denied — never a module counter map. Cargo is
     * sized to the loot the origin could carry, largest hull first, so a farm draws kill ships plus
     * cargo rather than the whole stock. Null when even the full stock does not survive the draw.
     *
     * @return array<string, int>|null
     */
    private function launchUnits(int $playerId, PlayerService $player, PlanetService $origin, PlanetService $target, int $seed): ?array
    {
        $targetMix = [...$target->getShipUnits()->units, ...$target->getDefenseUnits()->units];
        $lootVolume = $this->lootVolume($this->maximumLoot($player, $origin, $target));
        $cargo = $this->cargoForLoot($player, $origin, $lootVolume);

        // Nothing fights back: cargo plus one cheapest kill hull, nothing to simulate.
        if ($targetMix === []) {
            return $this->withKillHull($origin, $cargo);
        }

        $hulls = $this->militaryHulls($origin);
        usort($hulls, fn (UnitEntry $left, UnitEntry $right) =>
            $this->counterScore($right->unitObject, $targetMix) <=> $this->counterScore($left->unitObject, $targetMix)
            ?: $this->attackPerCost($player, $right->unitObject) <=> $this->attackPerCost($player, $left->unitObject));

        // Grow the counter hulls from the strongest down, simulating once per step: the smallest
        // fleet whose single draw survives is the launch (FLE-012). ponytail: one draw per candidate
        // is a probability average — a close fight may need the next hull on a later re-plan; the
        // 50-sample full-stock screen already bounds the worst case.
        $launch = $cargo;
        foreach ($hulls as $hull) {
            $launch[$hull->unitObject->machine_name] = $hull->amount;
            $estimate = $this->raidEstimator->estimateFleet(
                $playerId,
                $origin->getPlanetId(),
                $target->getPlanetId(),
                $this->fleet($launch),
                $seed,
            );

            if ($estimate->samples > 0 && $estimate->pWin >= 1.0) {
                return $launch;
            }
        }

        return null;
    }

    /**
     * How well a hull counters the target's mix: rapid fire against it, minus the target's rapid
     * fire back, weighted by how many the target fields. Read from the host's own graph (gate 1).
     *
     * @param list<UnitEntry> $targetMix
     */
    private function counterScore(UnitObject $candidate, array $targetMix): int
    {
        $score = 0;

        foreach ($targetMix as $entry) {
            $score += $this->rapidfire($candidate, $entry->unitObject) * $entry->amount;
            $score -= $this->rapidfire($entry->unitObject, $candidate) * $entry->amount;
        }

        return $score;
    }

    /** The rapid-fire factor of one hull against another, or zero when it has none. */
    private function rapidfire(UnitObject $shooter, UnitObject $target): int
    {
        foreach ($shooter->rapidfire as $rapidfire) {
            if ($rapidfire->object_machine_name === $target->machine_name) {
                return $rapidfire->amount;
            }
        }

        return 0;
    }

    /** Attack per metal-equivalent price, so an unmatched hull still ranks by what it kills. */
    private function attackPerCost(PlayerService $player, UnitObject $unit): float
    {
        $attack = (float) $unit->properties->attack->calculate($player)->totalValue;
        $price = $this->raidEstimator->metalEquivalent(ObjectService::getObjectRawPrice($unit->machine_name));

        return $price > 0.0 ? $attack / $price : 0.0;
    }

    /**
     * The fewest civil cargo hulls that carry the loot, largest capacity first, so the haul stays
     * intact while the military hulls shrink to the counters. Civil vs military is the host's own
     * split, so a mod-added transport is cargo with no edit (gate 1).
     *
     * @return array<string, int>
     */
    private function cargoForLoot(PlayerService $player, PlanetService $origin, int $volume): array
    {
        $stock = $origin->getShipUnits();
        $ships = [];

        foreach (ObjectService::getCivilShipObjects() as $ship) {
            $amount = $stock->getAmountByMachineName($ship->machine_name);
            $capacity = $this->capacity($player, $ship);
            if ($amount > 0 && $capacity > 0) {
                $ships[] = ['unit' => $ship, 'amount' => $amount, 'capacity' => $capacity];
            }
        }

        usort($ships, static fn (array $left, array $right): int => $right['capacity'] <=> $left['capacity']);

        $cargo = [];
        $carried = 0;

        foreach ($ships as $entry) {
            if ($carried >= $volume) {
                break;
            }

            $amount = min($entry['amount'], (int) ceil(($volume - $carried) / $entry['capacity']));
            $cargo[$entry['unit']->machine_name] = $amount;
            $carried += $amount * $entry['capacity'];
        }

        return $cargo;
    }

    /**
     * The defenceless farm still gets a kill hull: the cheapest military ship the account owns, one
     * hull, so the cargo never flies alone (U6). The target cannot fight back, so one is enough.
     *
     * @param array<string, int> $cargo
     * @return array<string, int>
     */
    private function withKillHull(PlanetService $origin, array $cargo): array
    {
        $cheapest = null;
        $cheapestPrice = null;

        foreach (ObjectService::getMilitaryShipObjects() as $ship) {
            if ($origin->getShipUnits()->getAmountByMachineName($ship->machine_name) <= 0) {
                continue;
            }

            $price = $this->raidEstimator->metalEquivalent(ObjectService::getObjectRawPrice($ship->machine_name));
            if ($cheapestPrice === null || $price < $cheapestPrice) {
                $cheapest = $ship;
                $cheapestPrice = $price;
            }
        }

        if ($cheapest === null) {
            return $cargo;
        }

        $cargo[$cheapest->machine_name] = max($cargo[$cheapest->machine_name] ?? 0, 1);

        return $cargo;
    }

    /** @return list<UnitEntry> the military hulls the origin actually owns, in stock order. */
    private function militaryHulls(PlanetService $origin): array
    {
        $hulls = [];

        foreach (ObjectService::getMilitaryShipObjects() as $ship) {
            $amount = $origin->getShipUnits()->getAmountByMachineName($ship->machine_name);
            if ($amount > 0) {
                $hulls[] = new UnitEntry($ship, $amount);
            }
        }

        return $hulls;
    }

    private function capacity(PlayerService $player, UnitObject $unit): int
    {
        return (int) $unit->properties->capacity->calculate($player)->totalValue;
    }

    private function lootVolume(Resources $loot): int
    {
        return (int) ($loot->metal->get() + $loot->crystal->get() + $loot->deuterium->get());
    }

    /** @param array<string, int> $units */
    private function fleet(array $units): UnitCollection
    {
        $fleet = new UnitCollection();

        foreach ($units as $machineName => $amount) {
            if ($amount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $amount);
            }
        }

        return $fleet;
    }

    /**
     * The first planet carrying a fleet.
     */
    private function origin(PlayerService $player): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * Whether the live target has neither ships nor defence, so it cannot fight
     * back (FS-013).
     */
    private function defencelessTarget(PlanetService $target): bool
    {
        return $target->getDefenseUnits()->units === [] && $target->getShipUnits()->units === [];
    }

    /**
     * A live-defenceless target cannot fight back, so the 50-sample screen is a
     * deterministic win. The loot is the host's own cargo-constrained plunder via
     * LootService, converted with the estimator's own weights — never a second
     * loot authority.
     */
    private function defencelessEstimate(PlayerService $player, PlanetService $origin, PlanetService $target): RaidEstimate
    {
        $metalEquivalent = $this->raidEstimator->metalEquivalent($this->maximumLoot($player, $origin, $target));

        return app()->makeWith(RaidEstimate::class, [
            'samples' => 1,
            'p20NetProfit' => $metalEquivalent,
            'p20Loot' => $metalEquivalent,
            'pWin' => 1.0,
        ]);
    }

    /**
     * The most any run can bring home: the host's own cargo-constrained plunder
     * of what the planet holds right now. A defended planet only yields it once
     * its fleet dies, so it is a ceiling and never a promise.
     */
    private function maximumLoot(PlayerService $player, PlanetService $origin, PlanetService $target): Resources
    {
        $fraction = app(CharacterClassService::class)->getInactiveLootPercentage($player->getUser());
        $resources = $target->getResources();

        return LootService::distributeLoot(
            new Resources(
                max(0, $resources->metal->get()) * $fraction,
                max(0, $resources->crystal->get()) * $fraction,
                max(0, $resources->deuterium->get()) * $fraction,
                0,
            ),
            $origin->getShipUnits()->getTotalCargoCapacity($player),
        );
    }

    /**
     * The deuterium a raid burns flying there and back, host-quoted for the
     * origin's own fleet at the slowest speed (RAID-006).
     */
    private function roundTripFuel(PlayerService $player, PlanetService $origin, PlanetService $target): int
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $oneWay = $fleetMissions->calculateConsumption($origin, $origin->getShipUnits(), $target->getPlanetCoordinates(), 0, 10.0);

        return 2 * (int) $oneWay;
    }

    /**
     * A routine farm must carry three metal-equivalent for each deuterium spent;
     * a defended run is allowed two because the debris subsidises it. Nothing
     * under the floor flies (RAID-011).
     */
    private function clearsLootTier(float $loot, int $fuel, bool $defended): bool
    {
        $tier = $defended ? self::LOOT_TIER_DEFENDED : self::LOOT_TIER_FARM;

        return $loot / max(1, $fuel) >= $tier;
    }

    /**
     * The target planet a report points at, if it still exists.
     */
    private function target(EspionageReport $report): ?PlanetService
    {
        return $this->planetServiceFactory->makeForCoordinate(
            new Coordinate((int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position),
            false,
            PlanetType::from((int) $report->planet_type),
        );
    }

    /**
     * The bashing limit, read from the account's own attack history on the target.
     */
    private function withinBashingLimit(int $playerId, int $targetPlanetId): bool
    {
        $attacks = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('planet_id_to', $targetPlanetId)
            ->where('mission_type', AttackMission::getTypeId())
            ->where('time_arrival', '>=', now()->subHours(self::BASHING_WINDOW_HOURS)->timestamp)
            ->count();

        return $attacks < self::BASHING_LIMIT;
    }

    /**
     * A farm is hit on the storage-fill schedule, not back-to-back: the same
     * target is left alone inside the cooldown however many sessions run, or
     * the account reads as a script (FS-015). The last attack is the host's own
     * fleet-mission history.
     */
    private function withinCooldown(int $playerId, int $targetPlanetId): bool
    {
        $lastAttack = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('planet_id_to', $targetPlanetId)
            ->where('mission_type', AttackMission::getTypeId())
            ->orderByDesc('time_departure')
            ->value('time_departure');

        return $lastAttack === null || (int) $lastAttack <= now()->subHours(self::RAID_COOLDOWN_HOURS)->timestamp;
    }

    /**
     * A target the account keeps coming home empty from is blacklisted for the
     * window: the real loot the host recorded in each raid outcome — never the
     * estimator's screen — is the taste that closes the loop (FS-015).
     */
    private function blacklisted(int $playerId, int $galaxy, int $system, int $position): bool
    {
        $cases = AiExperienceCase::query()
            ->where('player_id', $playerId)
            ->where('family', AiExperienceCaseFamily::Raid)
            ->where('created_at', '>=', now()->subDays(self::BLACKLIST_WINDOW_DAYS))
            ->where('features->' . AiRaidExperienceFeature::Galaxy->value, $galaxy)
            ->where('features->' . AiRaidExperienceFeature::System->value, $system)
            ->where('features->' . AiRaidExperienceFeature::Position->value, $position)
            ->get(['features']);

        if ($cases->count() < self::BLACKLIST_RAIDS) {
            return false;
        }

        $totalLoot = $cases->sum(fn (AiExperienceCase $case): float => (float) ($case->features[AiRaidExperienceFeature::Loot->value] ?? 0));

        return $totalLoot / $cases->count() < self::BLACKLIST_LOOT_FLOOR;
    }
}
