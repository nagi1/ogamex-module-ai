# Enhancement plan — klaasvp/trashsim-public

## Verdict summary (one line)
TrashSim is a combat simulator whose battle math the host `BattleEngine` already owns (and a second engine is forbidden), so everything M01–M14/M19–M24 is ALREADY-DONE via the host or REFUSED; the only surviving ideas are its Monte Carlo aggregation — classify each sampled run win/loss/draw instead of counting net-sign losers — plus one shared clean-up of `RaidPlanner::expectedLoot()`.

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Win/loss/draw outcome buckets (M16) | Classify every sampled run by the host result: win = defender fleet wiped (`defenderUnitsResult` empty, loot granted), draw = both sides survive the 6 rounds, loss = attacker wiped | `app/Infrastructure/Battle/NativeRaidEstimator.php` (read `attackerUnitsResult`/`defenderUnitsResult` per run), `app/Domain/Raid/RaidEstimate.php` | `losingRuns` today is `net < 0`, which folds "fleet died" and "couldn't break the target" into one number; a target that draws 50% still passes P20 (wins carry the tail, draws are zero) so the account flies half its raids for nothing but fuel. A player reads the combat report as win/loss/draw. |
| Typical-case mean (M17) | Report the mean net beside the P20 tail from the per-run list the estimator already collects | `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Raid/RaidEstimate.php` | One extra scalar from data already in hand; names the "typical return" a player sees, versus the P20 worst case that gates the decision. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `RaidPlanner::expectedLoot()` re-derives loot as `min(metalEquivalent × classLootFraction, cargoCapacity)` from the espionage report's stale snapshot | The host returns authoritative cargo-constrained, fill-ordered loot (`BattleResult::$loot`) on every sampled run; we already discard it | Carry P20 loot out of the same sampled runs and feed `clearsLootTier()` from it; delete `expectedLoot()` (shared with the `jstar88-opbe` plan) | `app/Domain/Decision/RaidPlanner.php`, `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php` |

## ALREADY-DONE (confirmed by read)
- **Seedable, read-only battle question + N=50 Monte Carlo** — `app/Infrastructure/Battle/NativeRaidEstimator.php` (`SCREEN_SAMPLES = 50`, `simulateBattle($seed + $i, true)`).
- **Lower-tail aggregation + loser count** — same file: `lowerQuantile(..., 0.2)` and `losingRuns` over `list<float>` net profits.
- **6-round combat, attacker-first fire, shield regen, rapid fire, hull/explosion, bounce rule, draw rule (M01–M12)** — host `app/GameMissions/BattleEngine/PhpBattleEngine.php` + `BattleEngine.php`.
- **Loot fill order + cargo constraint + class loot fraction (M19/M20)** — host `app/GameMissions/BattleEngine/Services/LootService.php`; `BattleResult::$loot`; `BattleEngine::$lootPercentage` from `CharacterClassService`.
- **Debris (M21)** — host `BattleEngine::calculateDebris()` → `BattleResult::$debris`.
- **Moon chance + creation (M23)** — host `BattleEngine::calculateMoonChance()` / `rollMoonCreation()` → `BattleResult::$moonChance/$moonCreated`.
- **Defense repair ~70% (M21)** — host `app/GameMissions/BattleEngine/Services/DefenseRepairService.php` → `BattleResult::$repairedDefenses`.
- **Tactical retreat ratio (M31)** — host `app/GameMissions/BattleEngine/Services/TacticalRetreatService.php` → `BattleResult::$tacticalRetreatRatio`.
- **Flight fuel quote (M26–M28)** — `app/Domain/Decision/RaidPlanner.php::roundTripFuel()` → host `FleetMissionService::calculateConsumption`.
- **Cargo capacity (M30)** — host `UnitCollection::getTotalCargoCapacity`, used in `RaidPlanner::expectedLoot()`.
- **Character classes (M35)** — host `CharacterClassService::getInactiveLootPercentage`.
- **Spy-report ingestion (M34)** — module `EspionageReport` model + `app/Domain/Decision/QueueableSpyPlanner.php`.

## REFUSE — list with one-line reason
- **Combat engine as module code (M01–M14)** — forbidden second battle engine; the host `BattleEngine`/`PhpBattleEngine` is the single authority we already sample.
- **Plunder fill order re-implemented (M19)** — host `LootService::distributeLoot` owns it.
- **Debris / moon chance / defense repair / reaper cap re-implemented (M21–M23)** — host owns each (`calculateDebris`, `calculateMoonChance`, `DefenseRepairService`, `WreckFieldService`).
- **Distance / duration / fuel / speed / cargo re-implemented (M26–M30)** — host `FleetMissionService` + `UnitCollection`; a module copy is a second flight authority.
- **Tactical retreat re-implemented (M31)** — host `TacticalRetreatService`.
- **Worker pool splitting across `navigator.hardwareConcurrency` (M32)** — browser-only parallelism; a PHP queue worker has no navigator and 50 samples is already the bounded screen.
- **IPM simulator (M33)** — dead half-finished code in the repo (empty missile loop, empty result) and no host missile mission path exists to read, so any module version would be gate-1-hardcoded.
- **Merged profit formula `-loss + debris + plunder - fuel` (M25)** — our split is deliberate policy: debris stays out of the raid gate (TP-004) and fuel is a separate loot-tier ratio, not a scalar inside net profit.
- **Hardcoded entity tables `entityInfoV6/V7` + research/class id literals (M34/M35 sources)** — gate-1 violation; the host `ObjectService`/`GameObjects` is the only source of truth.
- **Entity bit-packing + Chrome UA sniff (M13/M14)** — browser perf hacks with no server analogue.
- **Best/worst "desired case" result picker UI (M18)** — web-tool display feature; the headless raid gate only needs the tail.
- **Share-link persistence (M36)** — human-facing web sharing feature; N/A.

## Priority recommendation — single highest-value change
Add win/loss/draw outcome buckets to `NativeRaidEstimator` by reading `attackerUnitsResult`/`defenderUnitsResult` from each run (one branch per run) and have `RaidPlanner` refuse targets the fleet frequently draws against. It closes a real hole: a 50%-draw target passes the P20 net gate today (wins carry the tail, draws score zero) and the account flies half its raids for nothing but fuel. Smallest diff — one new field, one read, one gate — and it is nameable play: "I read the combat report as win, loss or draw."

## Open questions / risks
- Should a frequent-draw target fail the estimate outright (a `drawRate` threshold) or only steer `RaidPlanner`? Prefer a distinct `drawRate` on `RaidEstimate`, not folding draws into `p20NetProfit`.
- The estimator samples live target state; `expectedLoot()` used the stale report snapshot. Dropping it (ENHANCE row) makes live state the sole loot authority — confirm no other caller needs the report-resource path.
- Outcome classification must reuse the same `seed+i` stream as P20 (one bounded screen, no second pass).
- Host battle files live outside `Modules/AI` (`/home/nagi/code/ogamex-next/app/GameMissions/BattleEngine/`); the ALREADY-DONE rows above were verified by reading host source, not a module grep.
- `wins + losses + draws` must equal `samples`; the tactical-retreat "both withdrew" run is neither win nor loss — decide its bucket (treat as draw/no-loot).
