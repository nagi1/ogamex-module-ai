<?php

namespace Modules\AI\Domain\Decision;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Domain\Attack\DailyAttackBudget;
use Modules\AI\Domain\Raid\RaidEstimate;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiRaidExperienceFeature;
use Modules\AI\Enums\GamePhase;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiPhalanxScan;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\RecycleMission;
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
    /** The host's hard bashing limit: at most six attacks on one target per day. Single source of truth for the raid cap. */
    public const int BASHING_LIMIT = 6;

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

    /** RV-011: astrophysics 23 is the researched late-game marker where mine ROI falls below fleet returns. */
    private const LATE_PHASE_ASTROPHYSICS = 23;

    private const LOOT_TIER_DEFENDED = 2.0;

    /** A phalanx scan stays authoritative for the raid decision this long. */
    private const PHALANX_SCAN_TTL_HOURS = 2;

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

    /**
     * The reason this report was not turned into a raid. Counted per reason and logged, so a run that
     * produces no raids shows which test is killing them instead of reading as a quiet planner (LIFE-001).
     */
    private function reject(string $reason, int $playerId, int $reportId): ?QueueableRaid
    {
        $key = 'ai:raid-rejected:' . $reason;
        Cache::add($key, 0, 86_400);
        Cache::increment($key);
        Log::debug('ai.raid.rejected', ['reason' => $reason, 'player_id' => $playerId, 'report_id' => $reportId]);

        return null;
    }

    private function player(int $playerId): PlayerService
    {
        return $this->players[$playerId] ??= $this->playerServiceFactory->make($playerId, true);
    }

    public function plan(int $playerId, int $reportId): ?QueueableRaid
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return $this->reject('no_profile', $playerId, $reportId);
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return $this->reject('no_user', $playerId, $reportId);
        }

        $report = EspionageReport::query()->find($reportId);
        if ($report === null) {
            return $this->reject('no_report', $playerId, $reportId);
        }

        $player = $this->player($playerId);
        $origin = $this->origin($player);
        if ($origin === null) {
            return $this->reject('no_origin', $playerId, $reportId);
        }

        // The fuel and loot quotes need the origin's owner context, which the
        // planets collection does not carry by itself.
        $origin = $this->planetServiceFactory->makeForPlayer($player, $origin->getPlanetId(), false);

        $target = $this->target($report);
        if ($target === null) {
            return $this->reject('no_target', $playerId, $reportId);
        }

        // Target-class escalation (RV-011): the opening farms inactives only, a
        // colonised account also raids active players, and only an
        // astrophysics-23 account crashes fleets. The class is the host's own
        // answer — the target's last activity and the report's ships — never a
        // module list.
        if (!$this->targetEligible($this->phase($player), $report)) {
            return $this->reject('target_ineligible', $playerId, $reportId);
        }

        // A player does not raid someone the galaxy view marks as a newbie (under a fifth of their
        // points) unless that account has gone idle: the host's own isNewbie answer, WIK-045.
        if ($this->protectedNewbie($player, (int) $report->planet_user_id)) {
            return $this->reject('newbie', $playerId, $reportId);
        }

        if (!$this->withinBashingLimit($playerId, $target->getPlanetId())) {
            return $this->reject('bashing_limit', $playerId, $reportId);
        }

        if (!$this->withinCooldown($playerId, $target->getPlanetId())) {
            return $this->reject('cooldown', $playerId, $reportId);
        }

        if ($this->blacklisted($playerId, (int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position)) {
            return $this->reject('blacklisted', $playerId, $reportId);
        }

        // A phalanx scan that saw a fleet arriving at the target is a ninja warning: the
        // defender has ships on the way home or an ally in bound, so the raid is refused
        // rather than flown into it (RV-008).
        if ($this->phalanxRefuses($playerId, $target->getPlanetId())) {
            return $this->reject('phalanx_refuses', $playerId, $reportId);
        }

        $estimate = $this->defencelessTarget($target)
            ? $this->defencelessEstimate($player, $origin, $target)
            : $this->raidEstimator->estimate($playerId, $origin->getPlanetId(), $target->getPlanetId(), $profile->random_seed);
        if ($estimate->samples === 0) {
            return $this->reject('no_samples', $playerId, $reportId);
        }

        // A fleeter asks "will I survive?" before "does it profit?". Refuse when
        // the sampled fleet is wiped more than the skill band's survival floor of the time.
        if ($estimate->pWin < $profile->skill_band->raidSurvivalFloor()) {
            return $this->reject('survival_floor', $playerId, $reportId);
        }

        // A fight's wreckage is profit only to an account that can pick it up: one that owns the
        // host's harvest hull for this position (the recycler fleet a player builds for the field).
        $debris = $this->canHarvest($origin, $target) ? $estimate->p20Debris : 0.0;

        if ($estimate->p20NetProfit + $debris <= 0.0) {
            return $this->reject('unprofitable', $playerId, $reportId);
        }

        // The launch is not the stock (U6): the smallest counter-selected hulls whose single
        // simulation survives this target fly, not the whole garage.
        $launchUnits = $this->launchUnits($playerId, $player, $origin, $target, $profile);
        if ($launchUnits === null) {
            return $this->reject('no_launch_units', $playerId, $reportId);
        }

        // The sampled profit is loot minus losses only. A raid also burns deuterium to fly there and
        // back, so a distant farm that spends more fuel than the tier allows is refused even when it
        // would "profit" (RAID-006, RAID-011). The fuel is the launch fleet's: priced from the whole
        // stock it ran to hundreds of times the real trip and turned away 6 reports in 10.
        $fuel = $this->roundTripFuel($player, $origin, $target, $this->fleet($launchUnits));
        if ($fuel > floor($origin->deuterium()->get())) {
            return $this->reject('not_enough_fuel', $playerId, $reportId);
        }

        // The dispatch also refuses a flight the fleet's own tanks cannot hold the outbound fuel for
        // (STUCK-DispatchFleet "insufficient storage capacity"): a small subset on a far target.
        if ((int) ($fuel / 2) > $this->fleet($launchUnits)->getTotalFuelCapacity($player)) {
            return $this->reject('fuel_tank_short', $playerId, $reportId);
        }

        if (!$this->clearsLootTier($estimate->p20Loot + $debris, $fuel, $target->getDefenseUnits()->units !== [])) {
            return $this->reject('below_loot_tier', $playerId, $reportId);
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

    private function canHarvest(PlanetService $origin, PlanetService $target): bool
    {
        $harvester = RecycleMission::getHarvesterMachineNameForPosition($target->getPlanetCoordinates()->position);

        return $origin->getShipUnits()->getAmountByMachineName($harvester) > 0;
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
    private function launchUnits(int $playerId, PlayerService $player, PlanetService $origin, PlanetService $target, AiProfile $profile): ?array
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

        // Grow the counter hulls from the strongest down in a screen-then-confirm ladder (RV-007):
        // one draw per candidate through the shared seed stream, so candidates meet the same
        // randomness, and the wide pass only on the first survivor — a single lucky draw never flies
        // a coin-flip fleet.
        $launch = $cargo;
        foreach ($hulls as $hull) {
            $launch[$hull->unitObject->machine_name] = $hull->amount;
            $fleet = $this->fleet($launch);

            $screen = $this->raidEstimator->estimateFleet($playerId, $origin->getPlanetId(), $target->getPlanetId(), $fleet, $profile->random_seed, 1);
            if ($screen->samples === 0 || $screen->pWin < 1.0) {
                continue;
            }

            $confirm = $this->raidEstimator->estimateFleet($playerId, $origin->getPlanetId(), $target->getPlanetId(), $fleet, $profile->random_seed, $profile->skill_band->raidConfirmSamples());
            if ($confirm->pWin >= $profile->skill_band->raidSurvivalFloor()) {
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
     * origin's own fleet at the slowest speed (RAID-006). Stationary hulls
     * are left out: the host divides by the slowest speed, so a solar
     * satellite in the fleet is a division by zero, not a slower trip.
     */
    private function roundTripFuel(PlayerService $player, PlanetService $origin, PlanetService $target, UnitCollection $launch): int
    {
        $fleet = MovableFleet::of($player, $launch);
        if ($fleet->units === []) {
            return 0;
        }

        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $oneWay = $fleetMissions->calculateConsumption($origin, $fleet, $target->getPlanetCoordinates(), 0, 10.0);

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
     * The account's game phase, from host reads only (RV-011): one planet is the
     * opening, a colony moves it mid, and astrophysics 23 makes it late.
     */
    private function phase(PlayerService $player): GamePhase
    {
        if ($player->getResearchLevel('astrophysics') >= self::LATE_PHASE_ASTROPHYSICS) {
            return GamePhase::Late;
        }

        if ($player->planets->planetCount() >= 2) {
            return GamePhase::Mid;
        }

        return GamePhase::Early;
    }

    /**
     * Which targets an account may raid: inactives from the first day, every other player once the
     * account has a colony, since an experienced player fights whoever the simulation says is worth it.
     * Whether a fight is worth it is the Rust screen's answer below (win odds and net profit after
     * losses), never a milestone the account must reach first.
     */
    private function targetEligible(GamePhase $phase, EspionageReport $report): bool
    {
        return $this->targetInactive($report) || $phase !== GamePhase::Early;
    }

    /**
     * Whether the report's target is a farm: idle long enough to hit, read from
     * the host's own inactivity rule (last login at least seven days ago). A
     * report without a known owner is refused, never raided.
     */
    private function targetInactive(EspionageReport $report): bool
    {
        $targetUserId = (int) $report->planet_user_id;
        if ($targetUserId <= 0 || !User::query()->whereKey($targetUserId)->exists()) {
            return false;
        }

        // Fresh load: the inactivity stamp is the decision input and a cached
        // player would carry the stamp from whenever the factory first built it.
        return $this->playerServiceFactory->make($targetUserId, true)->isInactive();
    }

    /** The host's newbie mark (below 20% of the raider's points, and not inactive) on the target's owner. */
    private function protectedNewbie(PlayerService $player, int $targetUserId): bool
    {
        if ($targetUserId <= 0 || !User::query()->whereKey($targetUserId)->exists()) {
            return false;
        }

        return $this->playerServiceFactory->make($targetUserId, true)->isNewbie($player);
    }

    /**
     * The bashing limit, read from the account's own attack history on the target.
     */
    private function withinBashingLimit(int $playerId, int $targetPlanetId): bool
    {
        return ! app(DailyAttackBudget::class)->exhausted(
            playerId: $playerId,
            targetPlanetId: $targetPlanetId,
            missionType: AttackMission::getTypeId(),
            cap: self::BASHING_LIMIT,
            windowHours: self::BASHING_WINDOW_HOURS,
        );
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
     * A recent phalanx scan that saw ships arriving at the target refuses the raid: the
     * defender has a fleet on the way home or an ally in bound, and flying into it is a
     * ninja, not a raid. The scan is the only window into a fleet the target holds inside
     * its planet, which the espionage report cannot show.
     */
    private function phalanxRefuses(int $playerId, int $targetPlanetId): bool
    {
        return AiPhalanxScan::query()
            ->where('player_id', $playerId)
            ->where('target_planet_id', $targetPlanetId)
            ->where('incoming_ship_count', '>', 0)
            ->where('observed_at', '>=', now()->subHours(self::PHALANX_SCAN_TTL_HOURS))
            ->exists();
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
