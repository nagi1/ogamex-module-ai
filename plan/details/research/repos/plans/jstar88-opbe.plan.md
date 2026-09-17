# Enhancement plan — jstar88/opbe

## Verdict summary (one line)
OPBE is a battle resolver and we already sample the host battle engine as our single battle authority, so every combat formula is ALREADY-DONE via the host or refused as a forbidden second engine; the only surviving work is two small module-side clean-ups in `RaidPlanner`.

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Lower-tail loot on the estimate | Carry a P20 loot quantile (metal-equivalent) next to the existing `p20NetProfit`, both from the same sampled runs, instead of a single point loot | `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php` | The corpus's worst case hid a losing majority behind a single mean; the same argument applies to loot alone. Reporting P20 loot gives the fuel-tier test the host's authoritative plunder rather than a hand-rolled scalar. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `RaidPlanner::expectedLoot()` re-derives loot as `min(metalEquivalent × classLootFraction, cargoCapacity)` from the espionage report's stale resource snapshot | The host engine's `LootService::distributeLoot` applies the real fill order (metal → crystal → deuterium, cargo-constrained) and returns it as `BattleResult::$loot` on every sampled run | Delete `expectedLoot()` and feed `clearsLootTier()` from the estimate's P20 loot: one loot authority, host fill order respected, no module-side loot math | `app/Domain/Decision/RaidPlanner.php`, `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php` |
| `LOOT_TIER_DEFENDED = 2.0` is justified as "the debris subsidises it" | M25: the host rebuilds ~70% of destroyed defense after every battle, so a defended farm stays defended and killing it is a recurring per-trip cost | Re-ground the comment/rationale in defense rebuild (the host's `DefenseRepairService`), not debris — module policy elsewhere explicitly keeps debris out of the raid profit gate (`strategy-principles.md` TP-004), so the current justification contradicts it | `app/Domain/Decision/RaidPlanner.php` |

## ALREADY-DONE (confirmed by grep)
- **Seedable, read-only battle question** — `app/Infrastructure/Battle/NativeRaidEstimator.php` calls `PhpBattleEngine::simulateBattle($seed, true)` (50 samples, P20); explicitly not a second engine.
- **Combat core (M01–M09, M12–M24): 6 rounds, attacker-first, shield regen, rapid fire, hull/explosion model, draw rule** — host `app/GameMissions/BattleEngine/BattleEngine.php` + `PhpBattleEngine.php` (not module code; verified by host file read).
- **Loot with cargo constraint + class loot fraction + fill order (M31/M32)** — host `app/GameMissions/BattleEngine/BattleEngine.php::calculateLootCapacityConstrained()` + `Services/LootService.php::distributeLoot()`; `BattleResult::$loot`.
- **Defense rebuild ~70% (M25)** — host `app/GameMissions/BattleEngine/Services/DefenseRepairService.php`; `BattleResult::$repairedDefenses`, rate from `SettingsService::defenseRepairRate()`.
- **Debris (M27)** — host `app/GameMissions/BattleEngine/BattleEngine.php::calculateDebris()` → `BattleResult::$debris`.
- **Moon chance + creation (M28–M30)** — host `BattleEngine::calculateMoonChance()` / `rollMoonCreation()` → `BattleResult::$moonChance/$moonCreated`.
- **Profit test, bashing limit, fuel tier, fresh intel** — `app/Domain/Decision/RaidPlanner.php` (`BASHING_LIMIT=6`, `LOOT_TIER_FARM`, `LOOT_TIER_DEFENDED`, `roundTripFuel`, `withinBashingLimit`).
- **Dispatch-time activity/ninja re-check** — `app/Actions/QueueAiRaidAction.php` (`activityAt`, `moonOnlyActivity` → `TargetActiveAtDispatch`/`TargetStagingAtDispatch`).

## REFUSE — list with one-line reason
- **Battle-engine core as module code (M01–M24, M26, M29)** — forbidden second battle engine; the host `BattleEngine`/`PhpBattleEngine` already is the single authority we sample.
- **Gaussian/random-RF RNG, shield/hull/explosion model (M10/M11, M15–M20)** — same as above, a port would duplicate the host engine's combat math.
- **Plunder fill order re-implemented in the module (M31)** — host `LootService::distributeLoot` already does it.
- **Defense rebuild / debris / moon chance re-implemented (M25/M27/M28/M30)** — host `DefenseRepairService`, `calculateDebris`, `calculateMoonChance` own them.
- **Bundled integrations' id ranges `SHIP_MIN_ID=202..217`, `DEFENSE_MIN_ID=401..503` + `XG.php` CombatCaps/pricelist/requeriments** — gate-1 hardcoded object universe, prices and requirements.
- **`Number` wrapper, `Math::divide/multiple/rest`, `IterableUtil` dual iteration, `HomeFleet extends Fleet {}`, ubiquitous `cloneMe()`, `POINT_UNIT`** — gate-2 over-engineering the repo itself flags as unused/parallel layers.
- **`ONLY_FIRST_AND_LAST_ROUND` memory save (M33)** — the module never stores per-round battle data; YAGNI.
- **Scheduler / decision engine / humanization** — OPBE has none; nothing to adopt.
- **`serialize(BattleReport)` DB report pattern** — the host already persists combat reports; a module-side copy is a second report authority.

## Priority recommendation — single highest-value change
Collapse `RaidPlanner::expectedLoot()` into a P20 loot quantile carried by `RaidEstimate` and consumed by `clearsLootTier()`. It deletes the module's hand-rolled loot scalar (gate 2), makes the host engine's fill-order-and-cargo plunder the only loot authority (gate 1), and keeps the tier test nameable as "does what I actually bring back clear the fuel ratio" (gate 3). Touches three files, adds one field, deletes one method — the smallest diff that removes a second loot authority.

## Open questions / risks
- Should `RaidEstimate` carry `p20Loot` explicitly, or should the tier test derive it from `p20NetProfit`? Prefer explicit: net and loot answer different gates (survival vs. worth-flying).
- The estimator samples the live target planet state; `expectedLoot()` used the espionage report's stale snapshot. Dropping it makes the live state the sole authority — consistent with the P20 net gate, but confirm no caller still needs the report-resource path.
- The P20 loot must reuse the same `seed+i` sample stream as P20 net (one bounded screen, no second pass).
- Host engine files live outside `Modules/AI` (`/home/nagi/code/ogamex-next/app/GameMissions/BattleEngine/`); the module-only grep cannot confirm host formulas, so the ALREADY-DONE rows above were verified by reading the host source directly.
