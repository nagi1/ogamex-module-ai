# Enhancement plan — jstar88/Ogame-algorithms

## Verdict summary (one line)

A 13-year-old, license-less PHP-5 formula library whose every mechanism (cost, production, storage,
construction time, espionage, plunder) our module already reads from the host — nothing to adopt or
enhance; close it.

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| — none — | The repo is a pure formula library; adopting any formula means reimplementing a host authority, which Gate 1 forbids and the no-duplication rule bans. Its one good design idea — parameterize cost as `(baseCost, costIncreaseFactor)` instead of hardcoding per-object prices — is already our pattern: `ObjectService::getObjectPrice($machineName, $planet)` is host-read everywhere. Nothing to build. | — | — |

## ENHANCE — table

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| — none — | The repo models nothing better than the host does. Its plunder 5-step fill order is exactly what the host battle engine already returns as `loot`, and its espionage/production constants are older than the host's own formulas. | No change. | — |

## ALREADY-DONE (confirmed by grep) — list with file path

- **M01–M07 production & energy** (metal/crystal/deuterium/plant/satellite/fusion, consumption):
  host-read via `PlanetService::getObjectProduction()`, `getMetalProductionPerHour()`,
  `getCrystalProductionPerHour()`, `getDeuteriumProductionPerHour()` —
  `app/Domain/Decision/EconomyUpgrades.php`, `app/Domain/Decision/EnergyCapacity.php`,
  `app/Domain/Decision/FacilityChain.php`, `app/Domain/Decision/ReserveFloor.php`,
  `app/Domain/Perception/PlayerObservationService.php`.
- **M11 storage capacity**: host `metalStorage()/crystalStorage()/deuteriumStorage()` —
  `app/Domain/Decision/EconomyUpgrades.php`, `app/Domain/Decision/RaidPlanner.php`,
  `app/Domain/Decision/ReserveFloor.php`.
- **M12 construction time**: host `PlanetService::getBuildingConstructionTime()` —
  `app/Domain/Decision/EconomyUpgrades.php` (line 141).
- **M13 cost at level**: host `ObjectService::getObjectPrice()` —
  `app/Domain/Decision/FacilityChain.php`, `app/Domain/Decision/QueueableBuildingPlanner.php`,
  `app/Domain/Decision/QueueableTransferPlanner.php`, `app/Domain/Decision/EconomyUpgrades.php`.
- **M16 espionage visibility level**: host `OGame\GameMissions\EspionageMission` drives probe
  count and report tiers; the module only picks a legal target and dispatches —
  `app/Domain/Decision/QueueableSpyPlanner.php`, `app/Actions/QueueAiSpyAction.php`.
- **M17 counter-espionage probability**: host mission mechanics; the module computes no discovery
  chance of its own — `app/Domain/Decision/QueueableSpyPlanner.php`.
- **M18/M19 plunder split (solo + ACS)**: host `PhpBattleEngine::simulateBattle()` returns `loot`;
  the module only samples it read-only — `app/Infrastructure/Battle/NativeRaidEstimator.php`, and
  `app/Domain/Decision/RaidPlanner.php` caps the pre-screen with the host's
  `CharacterClassService::getInactiveLootPercentage()` and `getTotalCargoCapacity()`.

## REFUSE — list with one-line reason

- **Port the library as-is** — no LICENSE file, PHP 5 (`mt_rand` deprecated, `array()` syntax,
  `require` without `_once`), global class names (`Cost`, `Mine`, `Plunder`) that collide with
  Composer autoload; copying is a policy violation regardless of age.
- **Per-object coefficient subclasses** (`MetalMine`, `CrystalMine`, `SolarPlant`, …) — hardcoded
  `30/20/10/1.1/1.44/…` coefficients are exactly the Gate-1 sin; the host owns these formulas.
- **M15 deconstruction cost** — the module never deconstructs, and the formula's `(1 − 0.04·ion)`
  multiplier goes negative above Ion 25 (a latent bug we would inherit for zero use).
- **M14 cumulative cost** — no caller needs cost summed over levels 1..N; payback uses next-level
  price only.
- **M20 base-class zero-default getters** — an inheritance scaffold with zero-return stubs, not a
  mechanism.
- **A module-side plunder forecaster** — the host battle engine already returns loot; a second
  split reimplementation would be a forbidden duplicate, and `RaidPlanner::expectedLoot` is a
  deliberate cheap pre-screen, not an authority.

## Priority recommendation — single highest-value change

Adopt nothing. The single highest-value action is to record this repo as **closed with no module
change**: every formula it offers is already host-read, and anything it offers that we lack
(deconstruction, cumulative cost) is YAGNI. If a future slice ever needs a numeric forecast the host
does not expose, the move is to request a read-only host quote, never a module formula — do not spend
the diff now on a number nobody is asking for.

## Open questions / risks

- The repo's constants are 13 years old and diverge from the live game (universe/economy speed,
  lifeforms, rebalanced classes). We must never "re-verify" them ourselves — the host's own
  `getObjectProduction`/`getObjectPrice` is the only authority we consult.
- `RaidPlanner::expectedLoot` applies one uniform loot fraction to all resources and ignores the
  fill order (deuterium last, capacity-capped), so a deuterium-heavy target can be over-scored by
  the fuel-tier pre-screen. This is currently harmless because the profit gate re-samples the real
  host plunder in `NativeRaidEstimator`; watch it only if loot-tier mis-orders a raid in live play.
- `Espionage::getCounterEspionage` divides by 100 where the doc claims 0..1, and `getSpyLevel` can
  return fractions/negatives — both are latent bugs we avoid entirely by delegating to the host
  mission rather than reimplementing any part of it.
