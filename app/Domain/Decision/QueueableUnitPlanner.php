<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
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
 * hostile is inbound, and a combat escort when a fresh report shows a defended target the account's
 * own fleet is not expected to crack.
 *
 * Which object fills each role is read from the host: cargo is the ship with the largest cargo
 * capacity per metal-equivalent cost the planet can actually queue, combat and defence are the
 * hulls with the best attack per metal-equivalent cost, and the colony ship is the ship the host's
 * own colonisation mission consumes. A mod-added hull with a better ratio becomes its role's unit
 * with no edit here.
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
    ) {
    }

    public function plan(int $playerId): ?QueueableUnit
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        if ($planets === []) {
            return null;
        }

        $underAttack = $this->underAttack($player);

        foreach ($planets as $planet) {
            $planet->updateResources(false);

            // Cargo first, and only while the account owns no ship at all: a fleet begins with
            // one hull that can carry, and nothing else is worth building before that exists.
            if ($this->ownsNoShip($planet)) {
                $cargo = $this->bestCargo($player, $planet);
                if ($cargo !== null) {
                    return $this->unit($planet, $cargo, 'role:cargo:' . $cargo->machine_name);
                }

                continue;
            }

            // Defence is reactive and time-sensitive: an inbound hostile makes it worth buying
            // before anything else on this planet, and the host's own "under attack" is the
            // trigger, so the module keeps no mission-type list.
            if ($underAttack) {
                $defense = $this->bestDefense($player, $planet);
                if ($defense !== null) {
                    return $this->unit($planet, $defense, 'role:defense:' . $defense->machine_name);
                }
            }

            // Expansion: a colony ship once a fleet exists, the account has room, and none is
            // already owned. One colony ship is the second planet every later fleet move needs.
            if (!$this->ownsColonyShip($planet) && $player->planets->planetCount() < $player->getMaxPlanetAmount()) {
                $colonyShip = ObjectService::getUnitObjectByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]);
                if ($this->queueable($planet, $colonyShip)) {
                    return $this->unit($planet, $colonyShip, 'role:colony');
                }
            }

            // Scouting: a probe once a fleet exists, so the account can start seeing neighbours.
            if (!$this->ownsProbe($planet)) {
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
                    return $this->unit($planet, $escort, 'role:escort:' . $escort->machine_name);
                }
            }

            // Cargo sizing: once the opening fleet exists, grow the cargo to the
            // raid payload the freshest report promises, not a fixed one (FLE-010).
            $cargoPlan = $this->cargoForPayload($player, $planet, $playerId);
            if ($cargoPlan !== null) {
                return $cargoPlan;
            }

            // Standing defence: a turtle or miner keeps a baseline wall scaled to
            // its fleet, so it is never naked between attacks — not only when the
            // host already says a hostile is inbound.
            if ($this->needsStandingDefense($planet, $profile->archetype)) {
                $defense = $this->bestDefense($player, $planet);
                if ($defense !== null) {
                    return $this->unit($planet, $defense, 'role:defense:standing:' . $defense->machine_name);
                }
            }
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
     * @param array<int, UnitObject> $units
     * @param 'capacity'|'attack' $property
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

            if (!$this->queueable($planet, $unit)) {
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
     * The defence piece with the best attack per metal-equivalent cost — the doctrine verbatim:
     * defence exists to make an attack unprofitable by inflicting maximum possible damage.
     */
    private function bestDefense(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        return $this->bestByProperty($player, $planet, ObjectService::getDefenseObjects(), 'attack');
    }

    /**
     * Whether a turtle or miner should grow its standing wall now: the planet's
     * defence value has fallen below the persona's fraction of its fleet value.
     * The floor is taste over host data, never a hardcoded defence count.
     */
    private function needsStandingDefense(PlanetService $planet, AiArchetype $archetype): bool
    {
        $ratio = $this->standingDefenseFloor($archetype);
        if ($ratio <= 0.0) {
            return false;
        }

        $fleetValue = $this->unitValue($planet->getShipUnits());
        if ($fleetValue <= 0.0) {
            return false;
        }

        return $this->unitValue($planet->getDefenseUnits()) < $ratio * $fleetValue;
    }

    /** The standing-defence floor as a fraction of fleet value, per persona. */
    private function standingDefenseFloor(AiArchetype $archetype): float
    {
        return match ($archetype) {
            AiArchetype::Turtle => 0.5,
            AiArchetype::Miner => 0.2,
            default => 0.0,
        };
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

    /**
     * True when the account owns no ship at least as fighty per cost as the candidate escort.
     * Cargo hulls carry a token attack, so "owns a ship with attack" is not enough; the account
     * already has a warship only when its best ratio matches the one it would queue.
     */
    private function needsEscort(PlayerService $player, PlanetService $planet, UnitObject $escort): bool
    {
        $escortRatio = $this->attackPerCost($player, $escort);

        foreach ($planet->getShipUnits()->units as $entry) {
            /** @var \OGame\GameObjects\Models\Units\UnitEntry $entry */
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
        if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)
            || !ObjectService::objectCharacterClassMet($unit->machine_name, $planet)) {
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
