## Overview

`jstar88/opbe` ("**O**game **P**robabilistic **B**attle **E**ngine") is a standalone PHP battle resolver for OGame-style browser games. It is **not** a bot, scheduler, or AI — it is a deterministic-plus-stochastic combat simulator invoked synchronously when a fleet arrives at a target. It is AGPL-3.0, requires PHP ≥ 5.3, and has ~95% PHP. The core claim is O(1) CPU/memory: it never iterates per-ship; it computes **expected values** over ship *types* (a `ShipType` object holds a count), with a small number of random draws (rapid fire, explosions, moon chance).

Key facts:
- 6 rounds per battle (`ROUNDS = 6`), attacker fires first each round, shields fully regenerate between rounds.
- Battle outcome is the OGame standard: attacker wins only if all defenders are dead (draw otherwise).
- Object stats (`shield`, `attack`, rapid-fire matrix `sd`) are supplied by the *host* into model constructors; the **engine core never hardcodes ids** — but the bundled host integration does (see Notable concerns).
- After-battle effects (debris, moon attempt, plunder, defense rebuild) live partly in `core/BattleReport.php` and partly in the host integration (`missionCaseAttack.php`).

## Architecture & entry points

Manual `require` chain, no autoloader, no Composer: `utils/includer.php` defines `OPBEPATH` and requires every class in dependency order, ending with `constants/battle_constants.php`.

Public entry point — `core/Battle.php`:
```php
$engine = new Battle($attackingPlayerGroup, $defendingPlayerGroup);
$engine->startBattle(false);
$info = $engine->getReport();   // BattleReport
```

Container classes (all extend `IterableUtil`, all clone-heavy, all `__toString()` render HTML via `views/*.html`):
- `models/Type.php` — base `{id, count}`, `increment/decrement/setCount/isEmpty`.
- `models/ShipType.php` — combat unit: shield, power, rapid fire `$rf`, cost, hull (derived), techs.
- `models/Ship.php` / `models/Defense.php` — subclasses differing only in `getRepairProb()`.
- `models/Fleet.php` — group of `ShipType`; `models/HomeFleet.php` (`extends Fleet {}`, empty) = planet ships+defense.
- `models/Player.php` — group of `Fleet`; `models/PlayerGroup.php` — group of `Player` (one side of a battle).

Combat classes (`combatObject/`): `Fire.php` (one attacker type → merged defender fleet), `FireManager.php` (collection of `Fire`), `PhysicShot.php` (damage → shield/hull split), `ShipsCleaner.php` (explosion count).

Core loop: `core/Round.php` orchestrates per-round fire → damage → clean → repair. `core/BattleReport.php` aggregates rounds + after-battle effects.

Integration adapters (host-specific, `implementations/`):
- `implementations/Xgp/missionCaseAttack.php` — XG Proyect attack mission (raw `mysql_query`/`doquery`).
- `implementations/2Moons/{1_3,1_6_1,1_7_2}_injectionMode/calculateAttack.php` — 2Moons injection-mode variants.
- `implementations/*/LangImplementation.php` — i18n.

Test harness: `tests/RunnableTest.php` (loads `vars/XG.php`, times battle, prints HTML), `tests/runnable/cases/*.php` (one class per scenario, e.g. `BsVsBc.php`, `RcVsHf.php`), `index.php` / `github.php` (browser case listing).

## Scheduling & loop model

**No scheduler, no daemon, no tick loop.** OPBE is a synchronous library call. In `missionCaseAttack.php` the trigger is a time comparison in the game's fleet-mission routine:
```php
if ($FleetRow['fleet_mess'] == 0 && $FleetRow['fleet_start_time'] <= time()) { ... }
```

The only "loop" is the fixed round loop in `core/Battle.php::startBattle()`:
```php
$round = new Round($this->attackers, $this->defenders, 0);   // round 0 = initial state
$this->report->addRound($round);
for ($i = 1; $i <= ROUNDS; $i++) {
    $att_lose = $this->attackers->isEmpty();
    $deff_lose = $this->defenders->isEmpty();
    if ($att_lose || $deff_lose) { $this->checkWhoWon(...); ...; return; }
    $round = new Round($this->attackers, $this->defenders, $i);
    $round->startRound();
    $this->report->addRound($round);
    $this->attackers = $round->getAfterBattleAttackers();
    $this->defenders = $round->getAfterBattleDefenders();
}
```
Per round (`core/Round.php::startRound()`): build `Fire` for each attacker ShipType vs merged defender fleet → build `Fire` for each defender ShipType vs merged attacker fleet → `inflictDamage()` (attackers first) → `cleanShips()` → `repairShields()`. Early exit when a side is empty.

## Decision engine & algorithms

There is **no decision engine** (no target selection, no strategy, no AI). The only "decisions" are stochastic battle-resolution formulas:

1. **Expected value, not per-ship simulation** — README: "instead [of calculating] each ship case, OPBE creates an estimation of their behavior… analyzing behavior for infinite simulations." Counts are floats through the pipeline.

2. **Fire generation** (`combatObject/Fire.php`):
   - Normal shots = `count`; normal power = `count * singlePower` (`getNormalPower()`).
   - RF shots = `round(getShotsFromOneAttackerShipOfType(shipType) * count)`; RF power = `RFshots * singlePower`.
   - `getShotsFromOneAttackerShipOfType`: `meanShots = GeometricDistribution::getMeanFromProbability(1 - $p) - 1`; if `USE_RANDOMIC_RF`, `Gauss::getNextMsBetween(meanShots, σ, meanShots*(1-MAX_RF_NERF), meanShots*(1+MAX_RF_BUFF))`.
   - `getProbabilityToShotAgainForAttackerShipOfType`: `$p += (1 - GeometricDistribution::getProbabilityFromMean($RF)) * (count_D / totalCount)` over all defender types (RF-weighted, count-weighted average).

3. **Shot distribution** (`models/Fleet.php::inflictDamage()`) — for each `Fire`, iterate defenders in id order (`getOrderedIterator()`, `ksort`); each defender type receives `floor(totalShots * count_D / totalCount)` shots, and the type with the **largest fractional remainder** gets 1 extra shot.

4. **Shield/hull split** (`combatObject/PhysicShot.php`) — `bounce()` → `assorb()` → `inflict()` (details in Discrete mechanisms).

5. **Explosions** (`combatObject/ShipsCleaner.php::start()`).

6. **Randomness sources**: `mt_rand` (Moon, `Math::tryEvent`), Box–Muller Gauss (`utils/Gauss.php`), geometric-distribution helpers (`utils/GeometricDistribution.php`).

## Data model & persistence

In-memory model is a clone-immune tree `PlayerGroup → Player → Fleet → ShipType` (all `Iterator`s). Each `add*` clones its argument ("no side effects", per README). `cloneMe()` re-instantiates with same fields (including current shield/life in `ShipType::cloneMe()`).

`ShipType` state fields: `singleShield/singleLife/singlePower` (per unit), `fullShield/fullLife/fullPower` (aggregate), `currentShield/currentLife`, `lastShots/lastShipHit` (accumulated damage counters used by `ShipsCleaner`), `rf`, `cost`, and `weapons/shields/armour_tech`.

Persistence:
- `BattleReport` is `serialize()`-able — README shows storing `serialize($info)` in a DB `Reports` table and re-rendering via `__toString()` → `views/report.html`.
- Actual DB writes are **host-side** in `missionCaseAttack.php`: `updateAttackers`, `updateDefenders`, `updateDebris` (galaxy debris), `updateMoon` (insert moon planet row), `sendMessage` (combat-report message row `rw`), all via raw `doquery()` SQL. No ORM.
- `BattleReport::getSteal()/setSteal()` carries the plunder result (set by `updateAttackers`).

## Config surface

Everything is in `constants/battle_constants.php` (plain `define()`s, no config object/env):

| Constant | Value | Meaning |
|---|---|---|
| `BATTLE_WIN/LOSE/DRAW` | 1 / −1 / 0 | result codes |
| `ROUNDS` | 6 | max rounds |
| `SHIELD_CELLS` | 100 | shield granularity cells |
| `USE_BIEXPLOSION_SYSTEM` | true | two-mode explosion model |
| `PROB_TO_REAL_MAGIC` | 2 | threshold divisor for bi-explosion |
| `EPSILON` | 1.2e-6 | float clamp tolerance |
| `SHIELDS/ARMOUR/WEAPONS_TECH_INCREMENT_FACTOR` | 0.1 | +10% per tech level |
| `COST_TO_ARMOUR` | 0.1 | hull = 10% of (metal+crystal) |
| `MIN_PROB_TO_EXPLODE` | 0.3 | explosion floor |
| `DEFENSE_REPAIR_PROB` | 0.7 | 70% of defense rebuilt |
| `SHIP_REPAIR_PROB` | 0 | ships never rebuilt |
| `USE_HITSHIP_LIMITATION` | true | cap absorbed damage to hit ships |
| `USE_EXPLODED_LIMITATION` | true | exploded ≤ shots received |
| `USE_RF` | true | enable rapid fire |
| `USE_RANDOMIC_RF` | true | Gauss-perturbed RF |
| `MAX_RF_BUFF` / `MAX_RF_NERF` | 0.2 / 0.2 | ±20% RF bound |
| `ONLY_FIRST_AND_LAST_ROUND` | false | report memory save |
| `REPAIRED_DO_DEBRIS` | true | repaired defense counts to debris |
| `SHIP_DEBRIS_FACTOR` / `DEFENSE_DEBRIS_FACTOR` | 0.3 / 0.3 | 30% debris |
| `POINT_UNIT` | 1000 | points = resources/1000 (**no core consumer found**) |
| `MOON_UNIT_PROB` / `MAX_MOON_PROB` | 100000 / 20 | moon chance |
| `MOON_MIN/MAX_START_SIZE` | 2000 / 6000 | moon diameter base |
| `MOON_MIN/MAX_FACTOR` | 100 / 200 | size per prob % |
| `MOON_MAX_HIGHT/LOW_TEMP_DIFFERENCE_FROM_PLANET` | 30 / 10 | moon temps |
| `DEFAULT_MOON_NAME` | 'moon' | |
| `TIMEZONE` | 'Europe/Vatican' | error-file timestamps |

Host-side (outside the engine core): `SHIP_MIN_ID=202`, `SHIP_MAX_ID=217`, `DEFENSE_MIN_ID=401`, `DEFENSE_MAX_ID=503` (`missionCaseAttack.php`), and the full `CombatCaps`/`pricelist`/`requeriments`/`resource`/`reslist` arrays in `tests/runnable/vars/XG.php` (used as the de-facto stat source).

## Edge cases & failure handling

- **Validation**: `PhysicShot::__construct` throws on negative damage/count; `ShipsCleaner::__construct` throws on negative `lastShipHit`/`lastShots`; `ShipType::inflictDamage` throws on negative shots and asserts `currentLife/currentShield/lastShipHit ≥ 0` after damage; `ShipsCleaner::start` throws on negative explosion probability.
- **Float cleanup**: values within `EPSILON` below zero are snapped to 0 (`ShipType::inflictDamage`, `ShipsCleaner::start`).
- **Zero/NaN guards**: `Math::divide` throws only in `$real=true` branch when denominator is 0; the `$real=false` path (`getShotsFiredByAllToOne`) does float division with no guard. `GeometricDistribution` returns `INF` for `p==0` (division-by-zero risk downstream).
- **Gauss bounding**: `Gauss::getNextMsBetween` throws if mean ∉ [min,max]; after 10 rejected samples it falls back to `mt_rand($min,$max)`.
- **Explosion floor**: `MIN_PROB_TO_EXPLODE=0.3` means lightly-damaged fleets explode nothing (see M18).
- **Overflow**: README documents that exceeding `9223372036854775807` truncates and "some overflow errors maybe" — no BCMath (listed as "future feature").
- **Error handling**: `DebugManager::runDebugged` wraps battle with custom error/exception handlers that write to `errors/*.html` and `die('An error occurred…')`. In `missionCaseAttack.php` the handlers are `intercept`ed with a closure that first sets `fleet_mess=1` (returns fleets) before dying — a deliberate anti-data-loss hook.
- **`Math::rest` bug** (`utils/Math.php`): `while ($divisore < 1) { $divisore *= 10; $dividendo *= 10; }` then `$dividendo % $divisore` — `%` truncates floats to int, so the fractional-remainder distribution is fragile (remainders for small per-ship shots can be wrong).

## Anti-detection & authenticity

none. OPBE contains **no** humanization, latency simulation, fleet-save, session shaping, or anti-bot logic — it is not a bot. Its only behavioral outputs are battle resolutions, which by construction mirror OGame's public combat math (and is *meant* to match speedsim/dragosim at ~3000 sims). Note: it is also the thing a defender would use to *simulate* outcomes, not a source of authentic play.

## Discrete mechanisms

- M01 — battle result codes: `BATTLE_WIN=1`, `BATTLE_LOSE=-1`, `BATTLE_DRAW=0` (`constants/battle_constants.php`).
- M02 — draw rule: `checkWhoWon` gives DRAW unless exactly one side is empty; attacker wins only when defenders empty (`core/Battle.php`).
- M03 — fixed 6 rounds: `for ($i = 1; $i <= ROUNDS; $i++)`, early exit if either side empty (`core/Battle.php`).
- M04 — attacker fires first: `inflictDamage(fire_a)` then `inflictDamage(fire_d)` (`core/Round.php::startRound`).
- M05 — one `Fire` per attacker ShipType, targeting the *merged* defender fleet (`getEquivalentFleetContent`) (`core/Round.php`).
- M06 — normal power = `count * singlePower`; normal shots = `count` (`combatObject/Fire.php::calculateTotal`).
- M07 — RF shots = `round(meanExtraShots * count)` added to shots and `power += RFshots * singlePower` (`Fire::calculateRf`).
- M08 — RF re-shot probability: `p = Σ_d (1 − 1/RF_d) · count_d/totalCount`; RF=0/1 ⇒ factor 0 (`Fire::getProbabilityToShotAgainForAttackerShipOfType`).
- M09 — expected extra shots: `1/(1−p) − 1` (geometric mean minus the guaranteed shot) (`Fire::getShotsFromOneAttackerShipOfType`).
- M10 — random RF: `Gauss::getNextMsBetween(mean, σ, mean·0.8, mean·1.2)` with `MAX_RF_NERF=MAX_RF_BUFF=0.2` (`Fire.php` + `battle_constants.php`).
- M11 — Gaussian RNG: Box–Muller `u = sqrt(−2·ln x)·cos(2π·y)` using `mt_rand` (`utils/Gauss.php::getNext`).
- M12 — tech scaling: each level multiplies the stat by `1 + 0.1·Δlevel` (weapon/shield/armour), only increasing (throws on decrease) (`models/ShipType.php::setWeapons/Shields/ArmourTech`).
- M13 — hull from cost: `singleLife = 0.1 · (metal + crystal)` (`ShipType::__construct`, `COST_TO_ARMOUR=0.1`).
- M14 — shield cells: `shieldCellValue = singleShield / 100`; shield "disabled" when `currentShield/count < 0.01` (`ShipType::getShieldCellValue`/`isShieldDisabled`, `SHIELD_CELLS=100`).
- M15 — bounce rule: a shot whose damage ≤ one shield-cell value bounces entirely → `bouncedDamage = (damage − clamp(damage, cellValue)) · count` (`combatObject/PhysicShot.php::bounce`).
- M16 — absorbed damage: `min(unbounced·count, currentShield·hitShips/count)` when `USE_HITSHIP_LIMITATION` (`PhysicShot::assorb`).
- M17 — hull damage: `pure − absorbed − bounced`, capped at `currentLife·hitShips/count`, floored at 0 (`PhysicShot::inflict`); `hitShips = min(shots, count)` (`PhysicShot::getHitShips`).
- M18 — explosion probability: `prob = 1 − currentLife/(hull·count)` (`combatObject/ShipsCleaner.php::start`).
- M19 — bi-explosion: if `lastShipHit ≥ count/2` then `probToExplode = (prob < 0.3) ? 0 : prob`, else `prob·(1−0.3)` (`ShipsCleaner::start`, `PROB_TO_REAL_MAGIC=2`, `MIN_PROB_TO_EXPLODE=0.3`).
- M20 — exploded count: `min(round(count·probToExplode), lastShots)` under `USE_EXPLODED_LIMITATION` (`ShipsCleaner::start`).
- M21 — remaining life carried out: `remainLife = currentLife / count`; `decrement(exploded, remainLife, 0)` then reset `lastShipHit=lastShots=0` (`ShipType::cleanShips`).
- M22 — full shield regen every round: `currentShield = fullShield` (`ShipType::repairShields`, called from `Round::startRound`).
- M23 — shot distribution by count: `shotsToType = floor(totalShots · count_D / totalCount)`, then +1 shot to the type with the largest remainder (`models/Fleet.php::inflictDamage`).
- M24 — ordered fires: defender targets iterated in ascending id (`ksort` in `Fleet::getOrderedIterator`); attacker fires in insertion order (`Fleet::inflictDamage`).
- M25 — defense rebuild: after battle, `round(lost · (1 − 0.7))` of each destroyed `Defense` is restored; `Ship` repair prob = 0 (`core/BattleReport.php::getPlayerRepaired`, `Defense::getRepairProb`/`Ship::getRepairProb`).
- M26 — lost units (cost): `(metal, crystal) × count` per destroyed type, minus repaired amount (`BattleReport::getPlayersLostUnits`).
- M27 — debris: `Σ destroyed(metal+crystal) × 0.3`, split SHIP/DEFENSE factor; repaired defense included iff `REPAIRED_DO_DEBRIS=true` (`BattleReport::getAttacker/DefenderDebris`).
- M28 — moon chance: `min(floor(totalDebris / 100000), 20)` (`BattleReport::getMoonProb`, `MOON_UNIT_PROB=100000`, `MAX_MOON_PROB=20`).
- M29 — moon roll: `mt_rand(0,99) < prob` (`utils/Math.php::tryEvent` → `Events::event_moon`).
- M30 — moon size: `rand(2000 + prob·100, 6000 + prob·200)`; fields `floor((size/1000)²)` (`utils/Events.php::event_moon`).
- M31 — plunder algorithm: halve defender resources, then fill `1/3` capacity metal → `1/2` remaining crystal → rest deuterium → `1/2` remaining metal → rest crystal (`missionCaseAttack.php::plunder`).
- M32 — capacity: `Σ count · pricelist[id]['capacity']` over surviving fleet (`missionCaseAttack.php::getCapacity`).
- M33 — memory save: with `ONLY_FIRST_AND_LAST_ROUND=true`, report keeps only rounds 0 and latest (`BattleReport::addRound`).
- M34 — clone-on-add: every container `add*` clones its argument to prevent side effects (`PlayerGroup/Fleet/Player::add*`).

## Notable concerns

**Gate 1 (hardcoded object universe) — VIOLATED at the integration layer.** The engine core is clean (ids/stats injected via constructors), but the bundled integrations are exactly what gate-1 forbids:
- `missionCaseAttack.php`: `define('SHIP_MIN_ID', 202); define('SHIP_MAX_ID', 217); define('DEFENSE_MIN_ID', 401); define('DEFENSE_MAX_ID', 503);` and loops over those id ranges to read planet defense.
- `tests/runnable/vars/XG.php`: full hardcoded `$CombatCaps` (shield/attack/`sd` RF matrix for ids 202–217, 401–503), `$pricelist`, `$requeriments`, `$reslist`. This is a static source of truth for the object universe, prices and requirements. For OGameX these are exactly the things that must come from the host. OPBE's *core* pattern (constructor-injected `$id/$rf/$shield/$cost/$power`) is the right shape to reuse; the XG vars are the anti-pattern.

**Gate 2 (over-engineering) — several signals:**
- `Number` wrapper + `Math::divide/multiple/rest` adds a parallel arithmetic layer over plain floats, and `Math::rest` is buggy (`%` on floats; a `while` loop multiplying small divisors by 10).
- Dual iteration: `IterableUtil` implements `Iterator` *and* exposes `getIterator()`; README itself says to prefer `getIterator()` — a forwarding abstraction with no single clear path.
- Ubiquitous `cloneMe()` on every layer is defensive but allocates full trees each round/`add*`.
- `HomeFleet extends Fleet {}` is an empty subclass existing only for report templating.
- `POINT_UNIT=1000` is defined but appears unused by the engine.
- No autoloader/Composer; manual `require` ordering in `includer.php` (brittle).

**Gate 3 (non-human behavior) — none directly**, since it has no scheduler/decision engine. Caveats for an OGameX port:
- Deterministic "expected value" resolution is *faster* but is a modeling approximation, not what a real battle is; it is calibrated against high-iteration speedsim, not per-player authenticity. It's a server-side resolver, not an actor.
- `DEBUG`/`echo` statements exist throughout `Round`, `Player`, `PlayerGroup` (`echo '--- Round …'`, `echo "***** firing …"`) — captured by `ob_start` unless `$debug`, but a leak under exception paths (`DebugManager::save` calls `ob_get_clean()` and may flush partial HTML).

## Confidence

High for structure, formulas, constants, and file names — all read directly from `raw.githubusercontent.com/jstar88/opbe/master` source (not only README). Medium for two specifics:
- `POINT_UNIT` unused: inferred from the files reviewed (not exhaustively grep-confirmed across every view/implementation).
- 2Moons `calculateAttack.php` variants were identified by listing but not line-read; I assume they mirror `Xgp/missionCaseAttack.php` with version-specific SQL/lang.
- `Math::rest` reachability: confirmed called from `Math::divide($real=true)`, which is used by `Fleet::inflictDamage` via `getShotsFiredByAllToDefenderType(..., true)`.

No invented facts: anti-detection and decision-engine sections are "none" because the repo contains neither.