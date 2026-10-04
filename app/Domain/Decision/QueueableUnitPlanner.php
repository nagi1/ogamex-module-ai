<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Doctrine\ArchetypeDoctrine;
use Modules\AI\Enums\AiThreatResponse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitEntry;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Models\Enums\PlanetType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account can legally queue a unit right now, and which one.
 *
 * Roles are taken in the order a miner's opening takes them: cargo first, because every later
 * fleet action needs somewhere to put loot and resources, then the colony ship that unlocks a
 * second planet, which is what fleetsave, transport and scouting all wait on. The two intel-driven
 * roles come after the opening, because each waits on an observation: defence when the host says a
 * hostile is inbound or when the planet has something the need evaluator says is worth a wall, and
 * a combat escort when a fresh report shows a defended target the account's own fleet is not
 * expected to crack.
 *
 * Power is the one role the building queue shares: a planet that is short and whose capacity the
 * building queue cannot take buys it from the yard instead, which is what a player does when the
 * plant is out of reach or the queue is busy. It is offered only when the building planner's own
 * gate says no, so the two never compete over the same shortfall.
 *
 * Which object fills each role is read from the host: cargo is the ship with the largest cargo
 * capacity per metal-equivalent cost the planet can actually queue, combat is the hull with the best
 * attack per metal-equivalent cost, the colony ship is the ship the host's own colonisation mission
 * consumes, and defence is the component `DefenseCompositionPlanner` says this account's doctrine is
 * most behind on. A mod-added hull with a better ratio becomes its role's unit with no edit here.
 *
 * The standing wall pass below is the one place the account's whole planet list is weighed at once,
 * and it is the only role that is collected before it is chosen: the account's wall spreads across
 * its planets one login at a time, each login serving the first planet still standing at zero.
 *
 * A bare planet whose yard cannot yet take any defence unit is reached by the building planner's
 * defence-prerequisite step, not here: a wall waits on the shipyard that builds it. A planet that
 * holds an order in the yard is served, whether or not the host has finished building it.
 *
 * The pass's plan is marked, because where the order is placed decides whether it happens: the
 * session's building steps are executed first and spend the balance the wall was priced against, so
 * the host refused the wall order and the planet stayed bare on every login (QUAL-003). The marker
 * lets the schedule run the login's first wall ahead of those steps.
 *
 * A bare sibling whose yard cannot yet take a defence unit is the building planner's business: the
 * wall pass that planner runs first stands the yard the unit waits on, so a login that finds a
 * sibling naked spends itself on that yard rather than on the account's habits, and the wall reaches
 * every sibling rather than only the ones whose yard already exists. The pass below speaks for that
 * sibling even when it is not the planet whose balance pays: a wall is placed on the planet that
 * stands at zero, whatever the login chose. A sibling whose yard is ready
 * keeps its own stock for the wall order because a bare planet that spends its balance every login
 * never reaches the price of the unit the wall order waits on.
 */
class QueueableUnitPlanner
{
    /** How many of the character class's own ship a planet keeps, and how many one order buys. */
    private const CLASS_SHIP_STANDING = 5;

    private const CLASS_SHIP_BATCH = 2;

    /** The host's one interplanetary missile object, the name its own mission reads, and the few a silo keeps ready. */
    private const MISSILE = 'interplanetary_missile';

    private const MISSILE_STANDING = 5;

    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    /** The first cargo batch is one hull: sizing for a raid payload arrives with the raid itself. */
    private const FIRST_CARGO_AMOUNT = 1;

    /** The same 24 h staleness window the observation publishes target reports with. */
    private const INTEL_TTL_HOURS = 24;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private EnergyCapacity $energyCapacity,
        private QueueableBuildingPlanner $buildingPlanner,
        private DefenseCompositionPlanner $defenseComposition,
        private DefenseNeedEvaluator $defenseNeed,
        private StalledGrowthDetector $stalledGrowth,
        private ThreatResponsePlanner $threatResponses,
    ) {}

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueableUnit
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (! User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        if ($planets === []) {
            return null;
        }

        // What this inbound asks the account for, per threatened body -- the wall among those
        // responses, so a body no fleet is aimed at buys no reactive wall for one.
        $threat = $this->threatResponses->plan($playerId, $player);

        // Whether the pass below has a planet to serve. A sibling that stands no wall while one
        // already does owns the account's wall effort: the pass serves it, and when it cannot be
        // served the building planner's own wall pass stands the yard it waits on. Adding wall
        // where one already stands instead is the shape the invariant reads -- a planet at zero
        // defence beside a sibling holding a wall -- so nothing below collects more wall while a
        // sibling is still bare (measured live 3 Oct 2026: a planet at zero beside one holding
        // 1,225 units).
        $nakedBesideWalled = $this->nakedBesideWalled($planets);

        // Standing defence is collected across the account and chosen once at the end. Returning on
        // the first planet that wanted anything let the planet with the most to lose take every order:
        // need grows with production, so the homeworld's need was never satisfied and its colonies were
        // never reached -- 125 of 158 grand-test planets sat at zero defence while one held 40,643.
        $standing = [];

        // A planet that stands no defence beside a sibling that already holds one takes the file's
        // floor before every other role, and across the whole account. It is also the only planet the
        // account may buy wall for: the survivor below is the bare sibling, and the walled planets
        // are left alone until it is served, so a login's one wall order cannot be stacked where a
        // wall already stands. The roles below are per planet
        // and each can keep re-firing on a single planet -- power for a planet that stays short, a
        // cargo fleet that never catches the next report, a colony ship for the next planet -- so the
        // naked planet was answered only when every one of those happened to fall quiet, and the
        // account stayed walled in one place (measured live 2 Oct 2026: 1 naked planet beside a
        // sibling holding 1,475). While no wall stands anywhere the account is still in its opening,
        // where cargo comes first; the standing pass below still reaches the floor for that case.
        $bareWallOrders = $this->standingDefenceOrders($playerId, $player);
        if ($bareWallOrders !== []) {
            return $bareWallOrders[0];
        }

        // Power outranks the habits below but not an incoming attack. A planet that is throttling
        // loses production every hour it stays short, wherever it sits on the account, so it is
        // answered before a habit on another planet gets its turn -- the same reason the building
        // planner runs its storage pass before its routine. The building planner has already
        // refreshed every planet's balance earlier in this perception, so the shortfall read here
        // is the session's own.
        if (! $threat->underAttack) {
            foreach ($planets as $planet) {
                $power = $this->powerFromYard($planet);
                if ($power !== null) {
                    return $power;
                }
            }
        }

        foreach ($planets as $planet) {
            $planet->updateResources(false);

            // Whether this planet wants a wall, and how big. Asked before the wall is composed so
            // the same answer sizes both the reactive and the standing defence below.
            $need = $this->defenseNeed->evaluate($player, $planet);

            // Cargo first, and only while the account owns no ship at all: a fleet begins with
            // one hull that can carry. A planet that owns nothing but cannot queue one yet still
            // falls through -- defence is reactive and standing, and waiting for a hull is what
            // left a fleetless account with no wall of its own.
            if ($this->ownsNoShip($planet)) {
                $cargo = $this->bestCargo($player, $planet);
                if ($cargo !== null) {
                    return $this->unit($planet, $cargo, 'role:cargo:'.$cargo->machine_name);
                }
            }

            // Defence is reactive and time-sensitive: an inbound buys the wall of the body it is
            // aimed at before anything else on that body. What says this body is answering with a
            // wall is the threat planner -- either because of what the body stands to lose or
            // because of a fleet the account keeps home behind it -- and the wall is sized to the
            // need that planner hands back, so the reactive and the bait wall are one decision.
            if ($threat->holds($planet->getPlanetId(), AiThreatResponse::ReinforceDefense)) {
                $defense = $this->defenseComposition->plan($player, $planet, $this->threatResponses->reinforcementNeed($player, $planet, $need));
                if ($defense !== null) {
                    // Marked ahead of the economy for the same reason a bare sibling's first wall
                    // is: the login's building steps spend the balance this wall was priced against,
                    // and an inbound leaves no second login to place it in.
                    return $this->unit($planet, $defense->unit, 'role:defense:'.$defense->unit->machine_name, $defense->amount, true);
                }
            }

            // Expansion: a colony ship once a fleet exists, the account has room, and none is
            // already owned. One colony ship is the second planet every later fleet move needs.
            if (! $this->ownsColonyShip($planet) && $player->planets->planetCount() < $player->getMaxPlanetAmount()) {
                $colonyShip = ObjectService::getUnitObjectByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]);
                if ($this->queueable($planet, $colonyShip)) {
                    return $this->unit($planet, $colonyShip, 'role:colony');
                }
            }

            // Scouting: a probe once a fleet exists, so the account can start seeing neighbours.
            if (! $this->ownsProbe($planet)) {
                $probe = ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]);
                if ($this->queueable($planet, $probe)) {
                    return $this->unit($planet, $probe, 'role:probe');
                }
            }

            // The class's own ship: the hull the host reserves for the account's character class (the
            // collector's crawler, the general's reaper, the discoverer's pathfinder) is what a player
            // of that class builds beside the first fleet. The host names it and gates it, a few at a time.
            $classShip = $this->classShip($player, $planet);
            if ($classShip !== null) {
                return $classShip;
            }

            // Missiles: a silo, impulse drive for the range and a defended target in the reports are what a
            // player who thins walls before a raid needs, a silo's worth at a time.
            $missiles = $this->missileStock($player, $planet, $playerId);
            if ($missiles !== null) {
                return $missiles;
            }

            // Escort: a fresh report on a defended target and no warship of our own means the
            // account wants the cheapest combat hull, so a later raid has something to attack
            // with. The hull is the best attack-per-cost the planet can queue, never a named ship.
            if ($this->observedDefendedTarget($playerId)) {
                $escort = $this->bestEscort($player, $planet);
                if ($escort !== null && $this->needsEscort($player, $planet, $escort)) {
                    return $this->unit($planet, $escort, 'role:escort:'.$escort->machine_name);
                }
            }

            // Cargo sizing: once the opening fleet exists, grow the cargo to the
            // raid payload the freshest report promises, not a fixed one (FLE-010).
            $cargoPlan = $this->cargoForPayload($player, $planet, $playerId);
            if ($cargoPlan !== null) {
                return $cargoPlan;
            }

            // Standing defence: what the planet stands to lose decides whether it wants a wall and
            // how big, so it is never naked between attacks -- not only when the host already says
            // a hostile is inbound. A planet whose wall already covers its exposure wants nothing.
            // Candidates are collected rather than returned: the choice between them is made below,
            // where the whole account is visible.
            if ($need === null) {
                continue;
            }

            // The login places one wall order and it belongs to a bare sibling, so a planet that
            // already holds a wall -- built or paid for in the yard -- collects no further wall
            // while one of its siblings stands at zero. Only the wall is withheld: the roles above
            // are this planet's too, so a walled planet still gets its cargo, its probes and the
            // shipyard a later order needs.
            if ($nakedBesideWalled && $this->defenseNeed->standingUnits($planet) > 0) {
                continue;
            }

            // A planet that stands no defence at all takes the floor even while the economy is
            // saving. Being short of the next step is what such a planet is, so the veto below
            // dropped the floor every session and left the account naked beside its own wall
            // (measured live 2 Oct 2026: a planet at zero defence beside a sibling holding 1,099).
            $bare = $this->holdsNoDefence($planet);
            $defense = $this->defenseComposition->plan($player, $planet, $need);

            if ($defense === null) {
                // The doctrine names nothing this planet's yard can build (a mid-game doctrine's ratio
                // names nothing below a level-two shipyard, while the anchor it could build is not in
                // the ratio at all), so the host's own cheapest defence unit is the wall here too --
                // the same fallback the opening pass takes -- rather than the planet staying bare
                // however often the session offers it.
                $fallback = $this->cheapestQueueableDefence($planet, $need, $bare);
                if ($fallback !== null) {
                    $standing[] = [$planet, $fallback];
                }

                continue;
            }

            // The host takes the whole price when the order is placed, so the batch is capped by what
            // this planet can pay for and skipped when that is nothing: an order it cannot pay for is a
            // refusal, and spending the login's one wall order on it is what left a poor planet bare
            // while the account believed it had walled it.
            $amount = min($defense->amount, $this->affordable($planet, $defense->unit));
            if ($amount < self::FIRST_CARGO_AMOUNT || (! $bare && $this->starvesSaving($planet, $profile, $defense->unit, $amount))) {
                continue;
            }

            $standing[] = [$planet, $this->unit($planet, $defense->unit, 'role:defense:standing:'.$defense->unit->machine_name, $amount, $bare)];
        }

        // The floor a planet that stands nothing takes is the one wall order that outranks the fleet:
        // keeping a planet alive is what the wall is for, and a bare planet beside a walled sibling
        // is the first thing the account fixes. Wall beyond that floor yields to the war fleet -- an
        // account past its opening spends its surplus on the strongest hull the host lets it build
        // before it buys another turret, which is how a cohort grows a fleet at all (measured live
        // 3 Oct 2026: one wall order per login and no hull above the median in a hundred accounts).
        $floor = array_values(array_filter($standing, static fn (array $entry): bool => $entry[1]->aheadOfEconomy));
        if ($floor !== []) {
            usort($floor, static fn (array $a, array $b): int => $a[0]->getDefenseUnits()->getAmount() <=> $b[0]->getDefenseUnits()->getAmount());

            return $floor[0][1];
        }

        $fleet = $this->capitalFleet($player, $planets, $profile);
        if ($fleet !== null) {
            return $fleet;
        }

        if ($standing !== []) {
            // The least-defended planet that still wants a wall takes the next order, so a wall rises
            // everywhere it is wanted instead of forever in one place. Counting units rather than
            // pricing them keeps this one comparison and no second valuation.
            usort($standing, static fn (array $a, array $b): int => $a[0]->getDefenseUnits()->getAmount() <=> $b[0]->getDefenseUnits()->getAmount());

            return $standing[0][1];
        }

        return null;
    }

    /**
     * What a player does with a full yard and nothing urgent: spends half of what a planet can
     * afford on the strongest military hull the host lets it build, so the war fleet grows as
     * research unlocks bigger hulls and a mod-added hull counts with no edit. Strongest is the
     * catalogue's own price order, and it is read across the whole account rather than on the
     * richest planet alone: a player orders the heavy hull from the yard that unlocks it, so an
     * account whose small yard sits on its richest planet still grows a war fleet (measured live
     * 3 Oct 2026: one wall order per login and no hull above the median in a hundred accounts).
     * The economy's saving can still veto the order.
     *
     * @param array<int, PlanetService> $planets
     */
    private function capitalFleet(PlayerService $player, array $planets, AiProfile $profile): ?QueueableUnit
    {
        $best = null;
        $bestPrice = -INF;

        foreach ($planets as $planet) {
            $hulls = array_filter(
                ObjectService::getMilitaryShipObjects(),
                fn (UnitObject $hull): bool => $this->canAttack($player, $hull) && $this->queueable($planet, $hull),
            );
            if ($hulls === []) {
                continue;
            }

            usort($hulls, fn (UnitObject $a, UnitObject $b): int => $this->hullPrice($planet, $b) <=> $this->hullPrice($planet, $a));
            $hull = $hulls[0];
            $price = $this->hullPrice($planet, $hull);
            if ($price <= $bestPrice) {
                continue;
            }

            $amount = max(self::FIRST_CARGO_AMOUNT, intdiv($this->affordable($planet, $hull), 2));
            if ($this->starvesSaving($planet, $profile, $hull, $amount)) {
                continue;
            }

            $best = $this->unit($planet, $hull, 'role:capital:'.$hull->machine_name, $amount, false, true);
            $bestPrice = $price;
        }

        return $best;
    }

    /**
     * The war-fleet order a login places whichever page it opened, or null when the yard has nothing
     * to spend on.
     *
     * `plan()` returns the fleet only when no other role on any planet wanted anything, and a login
     * is almost always consumed by one of them -- a probe, a colony ship, a wall -- so the order the
     * schedule below waits for was never handed to it and the account held no hull dearer than its
     * wall orders ever bought. The schedule asks for this the way it asks for the bare siblings' wall
     * orders, so one hull is bought per login however the engine scored the page.
     */
    public function capitalFleetOrder(int $playerId, ?PlayerService $player = null): ?QueueableUnit
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null || ! User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        if ($planets === []) {
            return null;
        }

        return $this->capitalFleet($player, $planets, $profile);
    }

    /** A hull that shoots: probes and satellites are military in the catalogue's grouping but fight nothing. */
    private function templateDefence(PlanetService $planet): ?UnitObject
    {
        $profile = AiProfile::query()->where('player_id', $planet->getPlayer()?->getId() ?? 0)->first();
        if ($profile === null) {
            return null;
        }

        $owned = [];
        foreach ($planet->getDefenseUnits()->units as $entry) {
            $owned[$entry->unitObject->machine_name] = $entry->amount;
        }

        $name = app(ArchetypeDoctrine::class)->nextTemplateUnit(
            $profile->archetype,
            'defence_template',
            $owned,
            fn (string $machineName): bool => $this->unitNamed($machineName) !== null && $this->requirementsMet($planet, $this->unitNamed($machineName)) && $this->affordable($planet, $this->unitNamed($machineName)) >= self::FIRST_CARGO_AMOUNT,
        );

        return $name === null ? null : $this->unitNamed($name);
    }

    /** The host's unit of that name, or null when a doctrine names something that is not a unit here. */
    private function unitNamed(string $machineName): ?UnitObject
    {
        try {
            return ObjectService::getUnitObjectByMachineName($machineName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, PlanetService> $planets
     */
    private function templateHull(PlayerService $player, array $planets, AiProfile $profile): ?QueueableUnit
    {
        $owned = [];
        foreach ($planets as $planet) {
            foreach ($planet->getShipUnits()->units as $entry) {
                $owned[$entry->unitObject->machine_name] = ($owned[$entry->unitObject->machine_name] ?? 0) + $entry->amount;
            }
        }

        foreach ($planets as $planet) {
            $name = app(ArchetypeDoctrine::class)->nextTemplateUnit(
                $profile->archetype,
                'fleet_template',
                $owned,
                fn (string $machineName): bool => $this->unitNamed($machineName) !== null && $this->queueable($planet, $this->unitNamed($machineName)),
            );
            if ($name === null) {
                continue;
            }

            $hull = ObjectService::getUnitObjectByMachineName($name);
            $amount = max(self::FIRST_CARGO_AMOUNT, intdiv($this->affordable($planet, $hull), 2));
            if ($this->starvesSaving($planet, $profile, $hull, $amount)) {
                continue;
            }

            return $this->unit($planet, $hull, 'doctrine:fleet:'.$name, $amount, false, true);
        }

        return null;
    }

    private function canAttack(PlayerService $player, UnitObject $hull): bool
    {
        return $hull->properties->attack->calculate($player)->totalValue > 1;
    }

    private function hullPrice(PlanetService $planet, UnitObject $hull): float
    {
        $price = ObjectService::getObjectPrice($hull->machine_name, $planet);

        return $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
    }

    /**
     * The colony ship a colonisation still lacks: a player with a free slot and no ship at the origin
     * orders the ship first. Null when the ship is there already or the yard cannot take it yet.
     */
    public function colonyShipFor(int $playerId, int $planetId): QueueableUnit|null
    {
        $planet = collect($this->playerServiceFactory->make($playerId, true)->planets->all())
            ->first(fn (PlanetService $candidate): bool => $candidate->getPlanetId() === $planetId);
        $ship = ObjectService::getUnitObjectByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]);

        if ($planet === null || $this->ownsColonyShip($planet) || ! $this->queueable($planet, $ship)) {
            return null;
        }

        return $this->unit($planet, $ship, 'role:colony');
    }

    private function unit(PlanetService $planet, UnitObject $ship, string $reason, int $amount = self::FIRST_CARGO_AMOUNT, bool $aheadOfEconomy = false, bool $surplusSpend = false): QueueableUnit
    {
        return app()->makeWith(QueueableUnit::class, [
            'planetId' => $planet->getPlanetId(),
            'unitId' => $ship->id,
            'amount' => $amount,
            'reason' => $reason,
            'aheadOfEconomy' => $aheadOfEconomy,
            'surplusSpend' => $surplusSpend,
        ]);
    }

    /**
     * The cargo batch a raidable fresh report asks for, once the opening fleet
     * exists: expected loot (the host's class loot fraction of the largest
     * visible pile) plus a 20% buffer, minus what the fleet already carries.
     */
    private function cargoForPayload(PlayerService $player, PlanetService $planet, int $playerId): ?QueueableUnit
    {
        $cargo = $this->bestCargo($player, $planet);
        if ($cargo === null) {
            return null;
        }

        $loot = $this->expectedRaidLoot($player, $playerId);
        if ($loot <= 0) {
            return null;
        }

        // The cargo role is chosen for a positive capacity, so the capacity is
        // non-zero by the time it is sized.
        $capacity = $cargo->properties->capacity->calculate($player)->totalValue;
        $needed = (int) ceil($loot * 1.2 / $capacity);
        $owned = (int) floor($planet->getShipUnits()->getTotalCargoCapacity($player) / $capacity);
        $missing = min($needed - $owned, ObjectService::getObjectMaxBuildAmount($cargo->machine_name, $planet, true));

        if ($missing <= 0) {
            return null;
        }

        return $this->unit($planet, $cargo, 'role:cargo:payload', $missing);
    }

    /**
     * The metal-equivalent loot the account's largest fresh report promises,
     * at the host's own class loot fraction (FLE-002).
     */
    private function expectedRaidLoot(PlayerService $player, int $playerId): float
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::INTEL_TTL_HOURS))
            ->latest('id')
            ->limit(10)
            ->pluck('espionage_report_id');

        $loot = 0.0;
        foreach (EspionageReport::query()->whereIn('id', $reportIds)->get(['resources']) as $report) {
            $resources = $report->resources ?? [];
            $pile = (int) ($resources['metal'] ?? 0)
                + self::CRYSTAL_WEIGHT * (int) ($resources['crystal'] ?? 0)
                + self::DEUTERIUM_WEIGHT * (int) ($resources['deuterium'] ?? 0);
            $loot = max($loot, $pile);
        }

        return $loot * app(CharacterClassService::class)->getInactiveLootPercentage($player->getUser());
    }

    private function ownsNoShip(PlanetService $planet): bool
    {
        return $planet->getShipUnits()->units === [];
    }

    private function ownsColonyShip(PlanetService $planet): bool
    {
        return $planet->getShipUnits()->getAmountByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]) > 0;
    }

    private function ownsProbe(PlanetService $planet): bool
    {
        return $planet->getShipUnits()->getAmountByMachineName(EspionageMission::getRequiredShipMachineNames()[0]) > 0;
    }

    /**
     * The unit whose named property per metal-equivalent cost is highest among those this planet
     * can queue. Cargo ranks capacity, combat and defence rank attack: both are "most X per unit
     * of resources", and both numbers come from the host.
     *
     * @param  array<int, UnitObject>  $units
     * @param  'capacity'|'attack'  $property
     */
    private function bestByProperty(PlayerService $player, PlanetService $planet, array $units, string $property): ?UnitObject
    {
        $best = null;
        $bestRatio = 0.0;

        foreach ($units as $unit) {
            $value = $unit->properties->{$property}->calculate($player)->totalValue;
            if ($value <= 0) {
                continue;
            }

            if (! $this->queueable($planet, $unit)) {
                continue;
            }

            $ratio = $value / $this->metalEquivalent(ObjectService::getObjectRawPrice($unit->machine_name));
            if ($ratio <= $bestRatio) {
                continue;
            }

            $best = $unit;
            $bestRatio = $ratio;
        }

        return $best;
    }

    private function bestCargo(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        return $this->bestByProperty($player, $planet, ObjectService::getShipObjects(), 'capacity');
    }

    /**
     * The combat hull with the best attack per metal-equivalent cost: the cheapest way to start
     * cracking a defended target, derived from host attack and price rather than a counter table.
     */
    private function bestEscort(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        return $this->bestByProperty($player, $planet, ObjectService::getShipObjects(), 'attack');
    }

    /**
     * The yard's answer to a power shortfall, or null when the yard is not the answer.
     *
     * Only a planet the building queue cannot cover has a shortfall left to buy here, and how many
     * units that takes comes from the capacity question itself, capped by what the planet can pay
     * for -- so the two routes cannot disagree about either the deficit or the price.
     */
    private function powerFromYard(PlanetService $planet): ?QueueableUnit
    {
        $shortfall = $this->energyCapacity->shortfall($planet);
        if ($shortfall <= 0.0 || $this->capacityBuildable($planet)) {
            return null;
        }

        $producer = $this->bestEnergyProducer($planet);
        if ($producer === null) {
            return null;
        }

        $perUnit = (float) $planet->getObjectProduction($producer->machine_name, 1, true)->energy->get();
        $affordable = ObjectService::getObjectMaxBuildAmount($producer->machine_name, $planet, true);

        return $this->unit(
            $planet,
            $producer,
            'role:energy:'.$producer->machine_name,
            min((int) ceil($shortfall / $perUnit), $affordable)
        );
    }

    /**
     * Whether the building queue can still answer this planet's power with a capacity it will take.
     *
     * The building planner owns that gate, so it is asked rather than restated here: the yard may
     * only take the shortfall once the queue has said it cannot.
     */
    private function capacityBuildable(PlanetService $planet): bool
    {
        foreach ($this->energyCapacity->pending($planet) as $candidate) {
            if ($this->buildingPlanner->canQueue($planet, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unit the host reports as producing power, ranked by power per metal-equivalent cost.
     *
     * Only the host's producing objects are asked, so a unit that looks like a power source but
     * produces nothing is not a candidate; ships and defence are the yard's business and the rest
     * is the building queue's. A mod-added power unit is picked up here with no edit.
     */
    private function bestEnergyProducer(PlanetService $planet): ?UnitObject
    {
        $best = null;
        $bestRatio = 0.0;

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            if (! $object instanceof UnitObject) {
                continue;
            }

            if (! $this->queueable($planet, $object)) {
                continue;
            }

            $energy = (float) $planet->getObjectProduction($object->machine_name, 1, true)->energy->get();
            if ($energy <= 0.0) {
                continue;
            }

            $ratio = $energy / $this->metalEquivalent(ObjectService::getObjectRawPrice($object->machine_name));
            if ($ratio <= $bestRatio) {
                continue;
            }

            $best = $object;
            $bestRatio = $ratio;
        }

        return $best;
    }

    /**
     * True when the account owns no ship at least as fighty per cost as the candidate escort.
     * Cargo hulls carry a token attack, so "owns a ship with attack" is not enough; the account
     * already has a warship only when its best ratio matches the one it would queue.
     */
    private function needsEscort(PlayerService $player, PlanetService $planet, UnitObject $escort): bool
    {
        $escortRatio = $this->attackPerCost($player, $escort);

        foreach ($planet->getShipUnits()->units as $entry) {
            /** @var UnitEntry $entry */
            if ($this->attackPerCost($player, $entry->unitObject) >= $escortRatio) {
                return false;
            }
        }

        return true;
    }

    private function attackPerCost(PlayerService $player, UnitObject $unit): float
    {
        $attack = $unit->properties->attack->calculate($player)->totalValue;
        if ($attack <= 0) {
            return 0.0;
        }

        return $attack / $this->metalEquivalent(ObjectService::getObjectRawPrice($unit->machine_name));
    }

    /**
     * Whether the account has a fresh espionage report on a target carrying defence.
     *
     * Reports reach the account through its own message rows, so this reads only what the account
     * has been told; the report's defence counts are the host's redacted picture at probe time.
     */
    private function observedDefendedTarget(int $playerId): bool
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::INTEL_TTL_HOURS))
            ->latest('id')
            ->limit(10)
            ->pluck('espionage_report_id');

        // defence is a nullable host column: a probe that revealed no defence
        // (an empty or unowned target) stores null, not [], so it must be
        // guarded exactly like resources above.
        foreach (EspionageReport::query()->whereIn('id', $reportIds)->get(['defense']) as $report) {
            foreach ($report->defense ?? [] as $count) {
                if ((int) $count > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The class ship order, a handful at a time, while the planet owns fewer than the standing few and the
     * yard can build it. Null when the account has no class, the host offers none, or it is not buildable here.
     */
    private function classShip(PlayerService $player, PlanetService $planet): ?QueueableUnit
    {
        $class = $player->getUser()->getCharacterClassEnum();
        if ($class === null) {
            return null;
        }

        $ship = ObjectService::getUnitObjectByMachineName(ObjectService::getObjectById($class->getClassShipId())->machine_name);
        if ($planet->getObjectAmount($ship->machine_name) >= self::CLASS_SHIP_STANDING || ! $this->queueable($planet, $ship)) {
            return null;
        }

        $amount = min(self::CLASS_SHIP_BATCH, $this->affordable($planet, $ship));

        return $this->unit($planet, $ship, 'role:class:'.$ship->machine_name, $amount);
    }

    /**
     * Interplanetary missiles up to the silo's standing few, when the account can fly them (the host's range
     * is above zero) and the freshest reports show a wall worth thinning. The silo requirement and the
     * price are the host's, asked through the same gates every unit order passes.
     */
    private function missileStock(PlayerService $player, PlanetService $planet, int $playerId): ?QueueableUnit
    {
        if ($player->getMissileRange() < 1 || ! $this->observedDefendedTarget($playerId)) {
            return null;
        }

        $missile = ObjectService::getUnitObjectByMachineName(self::MISSILE);
        if ($planet->getObjectAmount(self::MISSILE) >= self::MISSILE_STANDING || ! $this->queueable($planet, $missile)) {
            return null;
        }

        return $this->unit($planet, $missile, 'role:missile', min(self::MISSILE_STANDING - $planet->getObjectAmount(self::MISSILE), $this->affordable($planet, $missile)));
    }

    private function queueable(PlanetService $planet, UnitObject $unit): bool
    {
        if (! $this->requirementsMet($planet, $unit)) {
            return false;
        }

        // The host service silently returns when the amount is unaffordable, so a published
        // capability that cannot pay would schedule work that creates no queue row.
        return ObjectService::getObjectMaxBuildAmount($unit->machine_name, $planet, true) >= self::FIRST_CARGO_AMOUNT;
    }

    /**
     * Whether the host lets this planet build the unit at all: its requirement graph and its character
     * class, with affordability left out. A wall is legal to order whenever the yard can take the unit;
     * how much of it the planet can pay for is a separate question, asked below.
     */
    private function requirementsMet(PlanetService $planet, UnitObject $unit): bool
    {
        return ObjectService::objectRequirementsMet($unit->machine_name, $planet)
            && ObjectService::objectCharacterClassMet($unit->machine_name, $planet);
    }

    /**
     * How many of the unit the planet can pay for out of the balance it holds now.
     *
     * The host's own affordability gate, with the requirement graph left to `requirementsMet` above:
     * the host returns zero for any amount once its requirements_met argument is false, so passing
     * false here would read every planet as unable to pay and leave the bare sibling beside the
     * account's wall with no order at all (QUAL-003).
     */
    private function affordable(PlanetService $planet, UnitObject $unit): int
    {
        return ObjectService::getObjectMaxBuildAmount($unit->machine_name, $planet, true);
    }

    /**
     * Whether this planet stands no defence at all: the state the doctrine's floor is written for,
     * and the one the economy's saving must not veto.
     */
    private function holdsNoDefence(PlanetService $planet): bool
    {
        return $planet->getDefenseUnits()->getAmount() === 0;
    }

    /**
     * Whether one planet of the account stands no wall while a sibling already holds one: the state
     * the wall order above is spent on, and the one that outranks adding wall anywhere else.
     *
     * @param  array<int, PlanetService>  $planets
     */
    private function nakedBesideWalled(array $planets): bool
    {
        $walled = false;
        $bare = false;
        // A sibling counts as walled the moment it is paid for, for the same reason the wall order
        // above reads its own yard: the invariant is read on built units, so the account leaves its
        // opening when the first order is placed.

        foreach ($planets as $planet) {
            if ($this->defenseNeed->standingUnits($planet) > 0) {
                $walled = true;

                continue;
            }

            // A moon without defence is ordinary (the invariant leaves it out too): it is not a bare sibling.
            $bare = $bare || $planet->getPlanetType() !== PlanetType::Moon;
        }

        return $walled && $bare;
    }

    /**
     * Whether any planet of the account stands a wall at all: the state that says the opening is over,
     * so the floor of a sibling holding nothing outranks the account's habits rather than waiting on
     * them.
     *
     * @param  array<int, PlanetService>  $planets
     */
    private function anyPlanetHoldsDefence(array $planets): bool
    {
        foreach ($planets as $planet) {
            // A wall already paid for in the yard counts here for the same reason it counts in the
            // pass above: the account has left its opening the moment its first wall is bought, so
            // the bare siblings are served from that login on rather than one session behind the
            // homeworld's queue (measured live 2 Oct 2026: a sibling left at zero defence while the
            // account's only wall sat in the yard).
            if ($this->defenseNeed->standingUnits($planet) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first wall a planet that holds nothing takes.
     *
     * The doctrine names the shape, but only among units this planet's yard can actually build: a
     * mid-game doctrine's ratio names nothing below a level-two shipyard, while the anchor it could
     * build is not in the ratio at all. A young colony's yard sits below every one of them, so the
     * composition answered null and the planet stayed naked beside a sibling's wall however often the
     * session offered it (measured live 2 Oct 2026: one planet at zero defence beside a sibling
     * holding 1,496). The fallback is the host's own cheapest defence unit, so no unit is named here.
     */
    private function standingDefence(PlayerService $player, PlanetService $planet): ?QueueableUnit
    {
        $need = $this->defenseNeed->evaluate($player, $planet);
        if ($need === null) {
            return $this->cheapestQueueableDefence($planet, null);
        }

        $defense = $this->defenseComposition->plan($player, $planet, $need);
        if ($defense !== null && $this->requirementsMet($planet, $defense->unit)) {
            // The host refuses an order it cannot pay for whole (QueueUnits: queue_not_created), and a
            // planet whose plan repeats that refusal every login stays naked forever, so the order is
            // capped by what this planet can actually pay for right now.
            $amount = min($defense->amount, $this->affordable($planet, $defense->unit));
            if ($amount >= self::FIRST_CARGO_AMOUNT) {
                return $this->unit($planet, $defense->unit, 'role:defense:standing:'.$defense->unit->machine_name, $amount, true);
            }
        }

        return $this->cheapestQueueableDefence($planet, $need);
    }

    /**
     * The wall order every bare sibling of the account owes, in planet order.
     *
     * A player with several naked colonies clicks through all of them before logging off, and the
     * invariant reads the account, so one login has to reach them all: a pass that returned a single
     * sibling per login needed as many logins as the account has planets, which is longer than its
     * own day of sessions, and the cohort read still found planets at zero beside a sibling holding
     * a wall (QUAL-003: 3 planets at zero defence while one held 1,312 units). Each order names its
     * own planet, so the schedule places them on the planets that stand bare, whatever the login chose.
     *
     * The order a bare sibling takes is the wall itself when its own yard can build it, and the
     * building planner's wall prerequisites stand the yard when it cannot: a login that finds a
     * sibling naked beside a wall spends itself on that sibling before the account's habits. Each
     * order names the planet it belongs to, so the executor places it on that planet and not on
     * whichever planet the login happened to open.
     *
     * Only an account that already holds a wall somewhere is served: while no wall stands anywhere
     * the account is in its opening, where cargo, the colony ship and the probe come first, and the
     * standing pass below still reaches the floor once a wall exists. A planet already paid for in
     * the yard is no longer bare and is left for the next login.
     *
     * A sibling whose own yard cannot take the wall yet is left to the building planner: the order
     * this pass can place is a defence unit, and a login that can neither place the unit nor stand
     * the yard it waits on keeps the sibling naked, so the yard is the planner's own pass and not
     * a second facility decision here. A sibling that can take the unit but holds nothing is served
     * here whatever its exposure, so the wall reaches every planet the account owns.
     *
     * @return list<QueueableUnit>
     */
    // QUAL-003: every bare sibling is asked, so the wallet of one is never the reason another stays zero.
    public function standingDefenceOrders(int $playerId, ?PlayerService $player = null): array
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null || ! User::query()->whereKey($playerId)->exists()) {
            return [];
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        if ($planets === [] || ! $this->anyPlanetHoldsDefence($planets)) {
            return [];
        }

        $orders = [];
        foreach ($planets as $planet) {
            // The balance is refreshed before the wall is priced: the host refuses an unaffordable batch.
            $planet->updateResources(false);
            if ($planet->getPlanetType() === PlanetType::Moon || $this->defenseNeed->standingUnits($planet) > 0) {
                continue;
            }

            $order = $this->standingDefence($player, $planet);
            if ($order !== null) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * The defence unit the host prices lowest that this planet's yard can build, in the count the need
     * is worth: the same value-to-units arithmetic the composition does, with the host's price of the
     * unit the doctrine cannot name here.
     */
    private function cheapestQueueableDefence(PlanetService $planet, ?DefenseNeed $need, bool $aheadOfEconomy = true): ?QueueableUnit
    {
        $best = null;
        $bestPrice = INF;

        foreach (ObjectService::getDefenseObjects() as $unit) {
            if (! $unit instanceof UnitObject || ! $this->requirementsMet($planet, $unit)) {
                continue;
            }

            $price = $this->metalEquivalent(ObjectService::getObjectRawPrice($unit->machine_name));
            if ($price <= 0.0 || $price >= $bestPrice) {
                continue;
            }

            $best = $unit;
            $bestPrice = $price;
        }

        // A planet that already stands a wall grows it along the archetype's defence template (architecture
        // step 4): the defence furthest below its share. A bare planet keeps the cheapest unit, which is the
        // fastest first wall.
        if ($best !== null && ! $this->holdsNoDefence($planet)) {
            $templated = $this->templateDefence($planet);
            if ($templated !== null) {
                $best = $templated;
                $bestPrice = max(1.0, $this->metalEquivalent(ObjectService::getObjectRawPrice($best->machine_name)));
            }
        }

        if ($best === null) {
            return null;
        }

        $amount = min(
            $this->affordable($planet, $best),
            max(self::FIRST_CARGO_AMOUNT, (int) ceil(($need?->defenceValue ?? 0.0) / $bestPrice))
        );

        // The host refuses an order it cannot pay for whole, so a planet holding less than one unit's
        // price is left to a later login rather than sent a batch that creates no queue row.
        if ($amount < self::FIRST_CARGO_AMOUNT) {
            return null;
        }

        return $this->unit($planet, $best, 'role:defense:standing:'.$best->machine_name, $amount, $aheadOfEconomy);
    }

    /**
     * Whether this order would spend a resource the planet is saving for an economy step: a player
     * mines while short, and defence comes from what the economy leaves.
     */
    private function starvesSaving(PlanetService $planet, AiProfile $profile, UnitObject $unit, int $amount): bool
    {
        // A flat score means the saving is not arriving: spend instead of waiting on it (IMPL-69).
        if ($this->stalledGrowth->stalled($profile->player_id)) {
            return false;
        }

        $saving = $this->buildingPlanner->savingFor($planet, $profile);
        if ($saving === null) {
            return false;
        }

        $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
        $held = $planet->getResources();

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            if ($price->{$resource}->get() > 0 && $saving->{$resource}->get() > 0
                && $held->{$resource}->get() - $price->{$resource}->get() * $amount < $saving->{$resource}->get()) {
                return true;
            }
        }

        return false;
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }
}
