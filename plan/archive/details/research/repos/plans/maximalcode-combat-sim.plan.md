# Enhancement plan — maximalcode/ogame-combat-sim

## Verdict summary
A Rust Monte-Carlo battle resolver we are forbidden to port (a second battle engine), so every combat formula is ALREADY-DONE via the host `BattleEngine` we already sample; the one surviving idea is the repo's outcome-distribution reporting ("a single battle tells you almost nothing") — carry a fleet-loss rate into our raid estimate so the planner refuses a raid too likely to lose the fleet, not merely one that loses money.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Battle-outcome distribution, not just a profit tail | `CombatResults` reports `attacker_wins / defender_wins / draws` per run so a coin-flip battle is visible, separate from money | `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php` | Our estimate's `losingRuns` counts profit-negative runs, but a fleeter refuses a run that is too likely to *lose the fleet* even when P20 profit is positive; the repo's win/draw/loss split is that reading, derivable for free from `BattleResult::$attackerUnitsResult` |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `NativeRaidEstimator::sample()` reads only `$result->loot` and `$result->attackerResourceLoss` and reduces 50 runs to P20 net + `losingRuns` | The repo reports the full outcome distribution (`attacker_wins`, `defender_wins`, `draws`) per batch, making "the fleet dies in 12 of 50 sims" visible | Add a per-run "attacking fleet wiped" flag — `$result->attackerUnitsResult->units === []` — aggregate it over the same 50 seeded runs, and expose it as a fleet-loss count/rate on `RaidEstimate`; `RaidPlanner::plan()` refuses above a module threshold | `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php` |

## ALREADY-DONE (confirmed by grep)

- **Sampled, seedable, read-only battle question** — `app/Infrastructure/Battle/NativeRaidEstimator.php` (`PhpBattleEngine::simulateBattle($seed + $i, true)`, 50 samples, P20 + `losingRuns`); explicitly not a second engine.
- **Combat core (M01–M17): 6-round cap, shield regen, rapid fire, hull/explosion, 1% bounce, exact damage, class levels, lifeform bonuses, instant-calc gate** — host `app/GameMissions/BattleEngine/BattleEngine.php` + `PhpBattleEngine.php` (outside `Modules/AI`, verified by host file read).
- **Debris (M21/M22/M29)** — host `BattleEngine::calculateDebris()` → `BattleResult::$debris`.
- **Moon chance + creation (M26)** — host `BattleEngine::calculateMoonChance()` / `rollMoonCreation()` → `BattleResult::$moonChance/$moonCreated`.
- **Loot with cargo constraint + fill order + class fraction (M23/M24)** — host `LootService::distributeLoot()` → `BattleResult::$loot`.
- **Defence rebuild ~70%** — host `BattleEngine/Services/DefenseRepairService.php` → `BattleResult::$repairedDefenses`.
- **Recyclers needed (M27)** — host `DebrisFieldService::calculateRequiredRecyclers()` (host-capability-map; 20k per recycler).
- **Profit test, bashing limit, fuel tier, fresh intel** — `app/Domain/Decision/RaidPlanner.php` (`BASHING_LIMIT=6`, `LOOT_TIER_FARM`, `LOOT_TIER_DEFENDED`, `roundTripFuel`, `withinBashingLimit`).
- **Dispatch-time activity/ninja re-check** — `app/Actions/QueueAiRaidAction.php`.

## REFUSE

- **The Rust engine itself (M01–M17, M31, M32)**: a second battle engine is forbidden duplication; the host `BattleEngine`/`PhpBattleEngine` is the single authority we sample.
- **Hardcoded entity stats / rapid-fire cross-table / lifeform tech table (`combat-types/src/entities.rs`, `lifeforms.rs`)**: gate-1 hardcoded object universe.
- **Downscaling for >10M ships (M18–M20)**: a module-side approximation is a second combat authority our 2 vCPU / 2 GB profile never needs.
- **Instant-calc short-circuit (M12–M17)**: the host engine already decides when to skip rounds; a module-side "skip the sim" is a second authority.
- **Debris-in-profit + moon-chance reporting (M25, M26)**: module doctrine (RAID-014, TP-004) keeps debris out of the single-raid profit gate; the host already computes both.
- **axum HTTP server, clap CLI, Rayon pool (M30, M31)**: a host-side daemon/new service — forbidden.
- **`combat-ogame-api` XML metadata client (M33–M35)**: we read object stats from the host `ObjectService`, not public XML; a second loader is duplication.

## Priority recommendation

Add a **fleet-loss rate** to `RaidEstimate`, computed as `count(attackerUnitsResult empty) / samples` over the estimator's existing 50 seeded runs. A fleeter sims a defended hit and refuses when the fleet dies too often, even if the P20 profit is green — that is the repo's "a single battle tells you almost nothing" made operational, it reuses the host's own winner readout, and it is one field plus one threshold, not a new engine.

## Open questions / risks

- Host `BattleResult` has no explicit winner field; outcome must be read as `attackerUnitsResult` empty vs non-empty. Confirm tactical retreat (`tacticalRetreat*`) is not mislabelled as a wipe — treat a retreated fleet as survived (or exclude retreated runs) and cover it in a test.
- The refusal threshold (e.g. loss rate > 0.5) is module taste; name the play ("won't fly a coin-flip into a defended target") and keep it a constant until it demonstrably varies.
- Keep one bounded screen: the loss rate must come from the same `seed + i` sample stream as P20, no second pass over the engine.
- Do not re-claim `jstar88-opbe.plan.md`'s P20-loot collapse or its debris-vs-defence-rebuild rationale fix — those are already owned by that plan; this plan only adds the outcome split.
