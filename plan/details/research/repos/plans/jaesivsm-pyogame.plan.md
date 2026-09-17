# Enhancement plan — jaesivsm/pyogame

## Verdict summary
One mechanism worth adopting (debris-field recycling), one small enhancement to close the raid→debris loop; everything else is already shipped host-read or is hardcoded/non-human and refused.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Debris-field recycling | Scans a bounded neighbourhood for debris fields above a loot threshold and sends recyclers to harvest them. Host-read: debris from host `DebrisField`, recycler requirement from host `RecycleMission`, threshold is module taste | New: `QueueableRecyclePlanner.php`, `QueueAiRecycleAction.php`, `QueueAiRecycle` contract, `AiCandidateActionType::Recycle`, `AiWorkKind::Recycle`, `CandidateActionFactory`, `ScheduleAiIntentAction`, `ExecuteAiIntentAction`, `AIServiceProvider`, policy weights | The one ordinary-play loop the module does not ship; `RaidPlanner.php:194` already counts debris value it never collects |

**Design shape:** mirror `QueueableSpyPlanner::plan()` — pick an origin planet owning a `recycler`, scan a bounded window for `DebrisField` rows whose `metal + crystal` exceeds a module threshold (same pattern as `QueueableTransferPlanner::MINIMUM_SHIPMENT`), skip fields already covered by an in-flight `RecycleMission`, return the nearest profitable one. Legality is the host's own `RecycleMission::isMissionPossible()`. No second engine, no daemon, no dependency.

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `RaidPlanner::expectedLoot()` prices debris into the defended-raid decision, but the debris is never harvested | pyogame keeps recycle as a first-class routine so a debris field it finds is actually collected | After the recycle slice lands, the new `QueueableRecyclePlanner` finds the raid's own debris via the normal scan — no new coupling in `RaidPlanner` beyond a test asserting the loop closes | `RaidPlanner.php` (+ new recycle planner test) |

## ALREADY-DONE (confirmed by grep)

- Prerequisite recursion, one-level-at-a-time — `FacilityChain.php`.
- Mine/energy/trigger heuristics — `EconomyUpgrades.php`, `EnergyCapacity.php`.
- Tank/storage rules — `EconomyUpgrades::storage()`, `spendSurplus()`.
- Empire build + ship-exact-cost + build-on-arrival — `QueueableBuildingPlanner.php`, `QueueableTransferPlanner.php`.
- Tech plans when not researching — `FacilityChain::pending()`.
- Spy target filter + neighbourhood scan — `QueueableSpyPlanner.php` (`MAX_CANDIDATES = 20`).
- Fleet loading, capacity guard, mission cleanup — host mission/unit services; `QueueAiTransferAction`.
- Build-time estimate — host `PlanetService::getBuildingConstructionTime()`.
- Cost/energy/tank formulas — host `ObjectService` / `PlanetService`.

## REFUSE

- Hardcoded formulas/tables — host answers price/energy/tank; a module copy is Gate-1 duplication.
- Mass repatriation (all ships/resources → capital at 2/3 capacity) — machine play.
- Robot/nanite trigger ratios — hardcoded named-building ratios (Gate 1).
- Empire-global cheapest build — bot-shaped; our per-planet payback + cross-planet storage pass is the human order.
- DOM selectors, mission CSS maps, research numbers, ship ids — scraping + hardcoded catalogues.

## Priority recommendation

Ship the **debris-recycling slice**. It names a real experienced-player action our module genuinely lacks, and `RaidPlanner.php:194` already counts debris it never collects. Build it on the `QueueableSpyPlanner`/`QueueableTransferPlanner` pattern (one value object, one planner, one `QueueAi*Action` bound in `AIServiceProvider`), driven by host `DebrisFieldService`, `RecycleMission`, and the recycler unit the host requires.

## Open questions / risks

- Recycler ownership: a fresh account has no recycler — the planner must fall through cleanly (same as spy/expedition when the probe is absent).
- Debris provenance: bound the scan window to the account's own neighbourhood; skip tiny fields below threshold.
- Ghost debris: filter by actual `metal + crystal > threshold`, not field existence alone.
- Persona weights: which archetypes recycle (raider high, miner/turtle low) is policy taste.
- Raid→recycle coupling: keep it as "the scan naturally finds it" first.
