<?php

namespace Modules\AI\Actions;

use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Training only (plan/rl/world-sim-spec.md, `combat`): random fights answered by the host, the parity cases of the
 * simulator's combat module. Each case puts drawn research, ships, defences and stock on two real accounts in memory,
 * fights it through `PhpBattleEngine::simulateBattle($seed, pure: true)` (host PHP has no FFI) and records the outcome
 * together with the input `RustBattleEngine` would send its library for the same fight, so the simulator can check its
 * own input building exactly and the engines in distribution. Nothing is saved; units are named by host id only.
 */
class DrawAiBattleCasesAction
{
    public const VERSION = 1;

    /** Largest stack drawn per unit type; enough for every rapid-fire chain without a slow PHP fight. */
    private const MAX_AMOUNT = 300;

    private const MAX_STOCK = 3_000_000;

    /**
     * @return array<string, mixed>
     */
    public function handle(PlayerService $attacker, PlanetService $target, int $count, int $maxLevel, int $seed): array
    {
        $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($attacker, $maxLevel);
        $defender = $target->getPlayer() ?? throw new RuntimeException('The target planet has no owner.');
        $found = [$this->research($attacker), $this->research($defender), $this->unitsOn($target), $target->getResources(), $defender->getUser()->tactical_retreat_ratio];

        $caps = $this->buildCaps($target);
        $random = new Randomizer(new Mt19937($seed));
        // The simulator has no tactical retreat (raid-sim-spec.md), so the defender never flees here.
        $defender->getUser()->tactical_retreat_ratio = 0;

        $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $cases[] = $this->case($attacker, $defender, $target, $random, $caps, $maxLevel, $seed * 1_000_003 + $i);
        }

        // Everything drawn was in memory; the accounts are handed back as they were found.
        $this->setResearch($attacker, $found[0]);
        $this->setResearch($defender, $found[1]);
        $this->placeOnPlanet($target, $found[2], $found[3]);
        $defender->getUser()->tactical_retreat_ratio = $found[4];

        return [
            'version' => self::VERSION,
            'engine' => 'php',
            'attacker_id' => $attacker->getId(),
            'defender_id' => $defender->getId(),
            'catalogue' => $catalogue,
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<int, int>  $caps
     * @return array<string, mixed>
     */
    private function case(PlayerService $attacker, PlayerService $defender, PlanetService $target, Randomizer $random, array $caps, int $maxLevel, int $seed): array
    {
        $attackerResearch = $this->drawResearch($attacker, $random, $maxLevel);
        $defenderResearch = $this->drawResearch($defender, $random, $maxLevel);

        $fleet = $this->drawUnits($random, array_values(array_filter(ObjectService::getShipObjects(), static fn (UnitObject $ship): bool => $ship->properties->speed->rawValue > 0)), 1, 4, []);
        $stationed = $this->drawUnits($random, array_values(ObjectService::getShipObjects()), 0, 3, []);
        $stationed->addCollection($this->drawUnits($random, array_values(ObjectService::getDefenseObjects()), 0, 4, $caps));
        $stock = array_map(fn (): int => $random->getInt(1, 10) === 1 ? 0 : $this->logUniform($random, self::MAX_STOCK), [0, 1, 2]);
        $this->placeOnPlanet($target, $stationed, new Resources(...$stock));

        $capacity = $fleet->getTotalCargoCapacity($attacker);
        $carried = $random->getInt(1, 10) <= 3 ? $random->getFloat(0, 0.6) : 0.0;
        $cargo = array_fill(0, 3, (int) ($capacity * $carried / 3));

        $attackerFleet = new AttackerFleet();
        $attackerFleet->units = $fleet;
        $attackerFleet->player = $attacker;
        $attackerFleet->fleetMissionId = 1;
        $attackerFleet->ownerId = $attacker->getId();
        $attackerFleet->cargoResources = new Resources(...$cargo);
        $attackerFleet->isInitiator = true;
        $attackerFleet->fleetMission = null;
        $defenders = [DefenderFleet::fromPlanet($target)];

        $engine = new PhpBattleEngine([$attackerFleet], $target, $defenders, app(SettingsService::class));
        $result = $engine->simulateBattle($seed, true);

        return [
            'seed' => $seed,
            'attacker' => ['research' => $attackerResearch, 'units' => $this->counts($fleet), 'cargo' => $cargo],
            'defender' => [
                'research' => $defenderResearch,
                'units' => $this->counts($stationed),
                'resources' => $stock,
                'engineer' => $defender->hasEngineer(),
                'moon_exists' => $result->moonExisted,
            ],
            'host_input' => $this->hostEngineInput([$attackerFleet], $target, $defenders, $result, $seed),
            'result' => [
                'rounds' => count($result->rounds),
                'winner' => $this->winner($result),
                'attacker_left' => $this->counts($result->attackerUnitsResult),
                'defender_left' => $this->counts($result->defenderUnitsResult),
                'attacker_lost' => $this->counts($result->attackerUnitsLost),
                'defender_lost' => $this->counts($result->defenderUnitsLost),
                'repaired' => $this->counts($result->repairedDefenses),
                'loot' => $this->triple($result->loot),
                'debris' => $this->triple($result->debris),
                'moon_chance' => $result->moonChance,
                'attacker_resource_loss' => $this->triple($result->attackerResourceLoss),
                'defender_resource_loss' => $this->triple($result->defenderResourceLoss),
            ],
        ];
    }

    /**
     * The input `RustBattleEngine::prepareBattleInput` builds for this fight. The engine cannot be constructed without
     * FFI, so its builder is reached on an instance without a constructor; only the fields the builder reads are set.
     *
     * @param  list<AttackerFleet>  $attackers
     * @param  list<DefenderFleet>  $defenders
     * @return array<string, mixed>
     */
    private function hostEngineInput(array $attackers, PlanetService $target, array $defenders, BattleResult $result, int $seed): array
    {
        $engine = (new ReflectionClass(RustBattleEngine::class))->newInstanceWithoutConstructor();
        foreach (['attackers' => $attackers, 'defenderPlanet' => $target, 'defenders' => $defenders, 'seed' => $seed] as $name => $value) {
            (new ReflectionProperty(BattleEngine::class, $name))->setValue($engine, $value);
        }

        // Kept as built (maps are objects), so the file holds the exact JSON the library would receive.
        return (new ReflectionMethod(RustBattleEngine::class, 'prepareBattleInput'))->invoke($engine, $result);
    }

    /** The report's rule (BattleReport): no round is an attacker win, both sides standing a draw, else who stands. */
    private function winner(BattleResult $result): string
    {
        if ($result->rounds === []) {
            return 'attacker';
        }
        $last = end($result->rounds);
        if ($last->attackerShips->getAmount() > 0 && $last->defenderShips->getAmount() > 0) {
            return 'draw';
        }

        return $last->attackerShips->getAmount() > 0 ? 'attacker' : 'defender';
    }

    /**
     * The most of each defence the host lets a planet hold (`getObjectMaxBuildAmount` on an empty, rich planet), so a
     * drawn planet is one the host could have built: one-of-a-kind defences stay at one, an empty silo holds nothing.
     *
     * @return array<int, int>
     */
    private function buildCaps(PlanetService $planet): array
    {
        $this->placeOnPlanet($planet, new UnitCollection(), new Resources(1e13, 1e13, 1e13, 0));
        $caps = [];
        foreach (ObjectService::getDefenseObjects() as $defence) {
            $caps[$defence->id] = ObjectService::getObjectMaxBuildAmount($defence->machine_name, $planet, true);
        }

        return $caps;
    }

    /**
     * @param  list<UnitObject>  $pool
     * @param  array<int, int>  $caps  per unit id; an id not listed is uncapped
     */
    private function drawUnits(Randomizer $random, array $pool, int $minTypes, int $maxTypes, array $caps): UnitCollection
    {
        $units = new UnitCollection();
        $picked = array_slice($random->shuffleArray($pool), 0, $random->getInt($minTypes, min($maxTypes, count($pool))));
        foreach ($picked as $unit) {
            $amount = min($this->logUniform($random, self::MAX_AMOUNT), $caps[$unit->id] ?? self::MAX_AMOUNT);
            if ($amount > 0) {
                $units->addUnit($unit, $amount);
            }
        }

        return $units;
    }

    /** @return array<int, int> research id => level, every research drawn 0..max */
    private function drawResearch(PlayerService $player, Randomizer $random, int $maxLevel): array
    {
        $levels = array_map(static fn (): int => $random->getInt(0, $maxLevel), $this->research($player));
        $this->setResearch($player, $levels);

        return $levels;
    }

    /** @return array<int, int> research id => level */
    private function research(PlayerService $player): array
    {
        $levels = [];
        foreach (ObjectService::getResearchObjects() as $research) {
            $levels[$research->id] = $player->getResearchLevel($research->machine_name);
        }

        return $levels;
    }

    /** @param array<int, int> $levels */
    private function setResearch(PlayerService $player, array $levels): void
    {
        foreach (ObjectService::getResearchObjects() as $research) {
            $player->setResearchLevel($research->machine_name, $levels[$research->id], false);
        }
    }

    private function unitsOn(PlanetService $planet): UnitCollection
    {
        $present = new UnitCollection();
        foreach (ObjectService::getUnitObjects() as $unit) {
            $amount = $planet->getObjectAmount($unit->machine_name);
            if ($amount > 0) {
                $present->addUnit($unit, $amount);
            }
        }

        return $present;
    }

    /** Replaces the planet's units and stock in memory only, through the host's own unsaved setters. */
    private function placeOnPlanet(PlanetService $planet, UnitCollection $units, Resources $stock): void
    {
        $planet->removeUnits($this->unitsOn($planet), false);
        $planet->addUnits($units, false);

        $held = $planet->getResources();
        $planet->deductResources(new Resources($held->metal->get(), $held->crystal->get(), $held->deuterium->get(), 0), false);
        $planet->addResources($stock, false);
    }

    private function logUniform(Randomizer $random, int $max): int
    {
        return max(1, min($max, (int) floor(exp($random->getFloat(0, log($max + 1))))));
    }

    /** @return array<int, int> unit id => amount, units at zero left out */
    private function counts(UnitCollection $units): array
    {
        $counts = [];
        foreach ($units->units as $entry) {
            if ($entry->amount > 0) {
                $counts[$entry->unitObject->id] = ($counts[$entry->unitObject->id] ?? 0) + $entry->amount;
            }
        }

        return $counts;
    }

    /** @return list<float> */
    private function triple(Resources $resources): array
    {
        return [$resources->metal->get(), $resources->crystal->get(), $resources->deuterium->get()];
    }
}
