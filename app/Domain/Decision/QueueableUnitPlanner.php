<?php

namespace Modules\AI\Domain\Decision;

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
use OGame\Services\FleetMissionService;
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
 */
class QueueableUnitPlanner
{
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

        $underAttack = $this->underAttack($player);

        // Standing defence is collected across the account and chosen once at the end. Returning on
        // the first planet that wanted anything let the planet with the most to lose take every order:
        // need grows with production, so the homeworld's need was never satisfied and its colonies were
        // never reached -- 125 of 158 grand-test planets sat at zero defence while one held 40,643.
        $standing = [];

        // Power outranks the habits below but not an incoming attack. A planet that is throttling
        // loses production every hour it stays short, wherever it sits on the account, so it is
        // answered before a habit on another planet gets its turn -- the same reason the building
        // planner runs its storage pass before its routine. The building planner has already
        // refreshed every planet's balance earlier in this perception, so the shortfall read here
        // is the session's own.
        if (! $underAttack) {
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
            // one hull that can carry, and nothing else is worth building before that exists.
            if ($this->ownsNoShip($planet)) {
                $cargo = $this->bestCargo($player, $planet);
                if ($cargo !== null) {
                    return $this->unit($planet, $cargo, 'role:cargo:'.$cargo->machine_name);
                }

                continue;
            }

            // Defence is reactive and time-sensitive: an inbound hostile makes it worth buying
            // before anything else on this planet, and the host's own "under attack" is the
            // trigger, so the module keeps no mission-type list.
            if ($underAttack) {
                $defense = $this->defenseComposition->plan($player, $planet, $need);
                if ($defense !== null) {
                    return $this->unit($planet, $defense->unit, 'role:defense:'.$defense->unit->machine_name, $defense->amount);
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

            $defense = $this->defenseComposition->plan($player, $planet, $need);
            if ($defense !== null) {
                $standing[] = [$planet, $defense];
            }
        }

        if ($standing !== []) {
            // The least-defended planet that still wants a wall takes the next order, so a wall rises
            // everywhere it is wanted instead of forever in one place. Counting units rather than
            // pricing them keeps this one comparison and no second valuation.
            usort($standing, static fn (array $a, array $b): int => $a[0]->getDefenseUnits()->getAmount() <=> $b[0]->getDefenseUnits()->getAmount());
            [$planet, $defense] = $standing[0];

            return $this->unit($planet, $defense->unit, 'role:defense:standing:'.$defense->unit->machine_name, $defense->amount);
        }

        return null;
    }

    private function unit(PlanetService $planet, UnitObject $ship, string $reason, int $amount = self::FIRST_CARGO_AMOUNT): QueueableUnit
    {
        return app()->makeWith(QueueableUnit::class, [
            'planetId' => $planet->getPlanetId(),
            'unitId' => $ship->id,
            'amount' => $amount,
            'reason' => $reason,
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

    private function underAttack(PlayerService $player): bool
    {
        return app()->makeWith(FleetMissionService::class, ['player' => $player])->currentPlayerUnderAttack();
    }

    private function queueable(PlanetService $planet, UnitObject $unit): bool
    {
        if (! ObjectService::objectRequirementsMet($unit->machine_name, $planet)
            || ! ObjectService::objectCharacterClassMet($unit->machine_name, $planet)) {
            return false;
        }

        // The host service silently returns when the amount is unaffordable, so a published
        // capability that cannot pay would schedule work that creates no queue row.
        return ObjectService::getObjectMaxBuildAmount($unit->machine_name, $planet, true) >= self::FIRST_CARGO_AMOUNT;
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }
}
