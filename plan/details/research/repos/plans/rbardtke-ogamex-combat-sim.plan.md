# Enhancement plan — rbardtke/OGameX-Combat-Simulator

## Verdict summary (one line)

Mostly REFUSE — it is a second implementation of OGameX's own combat formulas with a hardcoded unit
universe (gate-1 violation); the module already samples the host's own battle engine, so the only
worthwhile takeaways are two host-read ergonomic ideas around raid acceptance.

## ADOPT-IDEA — table: | Mechanism | What it does | Where in our module | Why worth it |

| Mechanism | What it does | Where in our module | Why worth it |
| --- | --- | --- | --- |
| Pre-flight round-trip duration (repo M27, `app.js` flight-time panel) | Predicts how long the fleet is away before committing, so a raid/save does not tie the fleet up past the next save window | `app/Domain/Decision/RaidPlanner.php` — one call to host `FleetMissionService::calculateFleetMissionDuration()` (host `app/Services/FleetMissionService.php:95`) | Gate 3: a raider knows the fleet will be home in time; the host already owns the formula, so this is a one-call, no-new-dependency check, not a reimplementation |

## ENHANCE — table: | Our current | What the repo does better | Proposed change | Files touched |

| Our current | What the repo does better | Proposed change | Files touched |
| --- | --- | --- | --- |
| `RaidPlanner::clearsLootTier()` gives defended runs a lower loot tier "because the debris subsidises it" (`RaidPlanner.php:194`), but `NativeRaidEstimator` discards `$result->debris` — the subsidy is a comment, not code | Repo computes per-run debris (M17) and credits it in profit/loss | Add a `p20NetProfitWithDebris` (or `p20Debris`) to `RaidEstimate`, capture `$result->debris` in `NativeRaidEstimator::sample()`, and have the defended tier compare against the debris-inclusive profit so the claim is real | `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php` |

## ALREADY-DONE (confirmed by grep) — list with file path

- Battle outcome prediction — `app/Infrastructure/Battle/NativeRaidEstimator.php:95-102` samples the
  host `PhpBattleEngine::simulateBattle($seed, true)` (no second engine; M01–M12 stay host-owned).
- Multi-run sampling + lower-tail screening — `NativeRaidEstimator.php:30,93-108` (50 samples, P20
  quantile; strictly stronger than the repo's win%/average M15/M16).
- Plunder / loot fraction — `app/Domain/Decision/RaidPlanner.php:186`
  (`CharacterClassService::getInactiveLootPercentage`) and `NativeRaidEstimator.php:101` (`$result->loot`).
- Debris + moon chance/creation — host `BattleResult` (`app/GameMissions/BattleEngine/Models/BattleResult.php:13-43`)
  already computes them; surfaced to us via `PhpBattleEngine` (currently unread — see ENHANCE).
- Cargo capacity — host `UnitCollection::getTotalCargoCapacity($player)` used at
  `app/Domain/Decision/RaidPlanner.php:187`, `app/Domain/Decision/QueueableUnitPlanner.php:165`,
  `app/Actions/QueueAiFleetSaveAction.php:163`.
- Coordinate distance (M26) — host `FleetMissionService::calculateFleetMissionDistance()` used at
  `app/Domain/Decision/QueueableFleetSavePlanner.php:223-224`, `app/Domain/Decision/QueueableSpyPlanner.php:182`,
  `app/Domain/Perception/PlayerObservationService.php:277`.
- Round-trip fuel — host `FleetMissionService::calculateConsumption()` at `RaidPlanner.php:169`.
- Tech/class combat multipliers (M13/M14) — applied inside the host battle engine from host data, so the
  module never restates them.

## REFUSE — list with one-line reason

- Rust→WASM engine (`src/lib.rs`) and Python reimplementation (`python-simulator/combat_simulator.py`)
  — a second battle engine duplicating the host's `PhpBattleEngine`/`RustBattleEngine`; forbidden.
- Hardcoded `UNITS`, `SHIP_SPEEDS`, `SHIP_DRIVES`, `CARGO_CAPACITY`, `DEFENSE_STRUCTURES` tables in
  `browser-extension/app.js` — gate-1 hardcoded object universe (ids, prices, requirements as source of truth).
- Sync/CI scaffolding (`check-ogamex-updates.sh`, `sync-ogamex.yml`, `auto-sync-ogamex.yml`, `SYNCING.md`)
  — machinery that exists only to reconcile the hardcoded tables; gate-2 maintenance liability.
- Per-shot combat mechanics as module code (M01–M12: bashing, rapidfire, explosion, cleanup) — the host
  engine is the single authority; reimplementing any of it in PHP is forbidden duplication.
- IPM damage/targeting (M24/M25) — host `app/GameMissions/MissileMission.php:320-390`
  (`calculateDefenseDestruction`) already implements the same formula; the module need only queue the mission.
- Ship speed / drive-bonus / slowest-ship tables (M28/M29/M34) — hardcoded; host
  `calculateFleetMissionDuration()` already derives duration from host data.
- Debris/moon/recycler/reaper/fair-loot helpers (M17/M18/M21/M22/M23) — host battle engine and missions
  already compute debris and moon chance; no module decision consumes recycler/reaper math yet.
- Share-code encoding (M31) — client-side URL sharing ergonomics; irrelevant to a server-side AI.
- Fleet API 1/2 import-export (M32) — client form ergonomics; the host already has its own API.
- Update check (M33) — browser-extension self-update; irrelevant.

## Priority recommendation — single highest-value change, 3-5 lines

Make the defended-raid subsidy real. `RaidPlanner` already lowers the loot tier for defended targets on
the claim that "the debris subsidises it", but `NativeRaidEstimator` throws `$result->debris` away.
Capture sampled debris in `RaidEstimate` and compare the defended tier against the debris-inclusive P20
profit. It is the smallest diff (three files), host-read, and turns an existing comment into code.

## Open questions / risks

- Whether `PhpBattleEngine::simulateBattle()` populates `debris` for all battle shapes (it is on
  `BattleResult`, but confirm non-ACS single-attacker runs before reading it in the estimator).
- The round-trip duration check (ADOPT-IDEA) needs a persona-own threshold (e.g. fleet home before the
  next save window); do not invent a constant until a fleet-save slice names it.
- Both ENHANCE/ADOPT-IDEA items are raid-policy changes, so they must be re-run through the three
  cognition gates and `scripts/ogamex gate` before `done`, not merged on this plan alone.
