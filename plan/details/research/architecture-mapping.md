# Architecture mapping — strategy principles → current code

Created 15 September 2026 by the [strategy mining](../specs/strategy-mining.md) architecture-mapper
pass. For every **researched** principle it names what the code does today, which signals are missing,
where the change would land, its size and priority. No implementation is implied; this is the map the
later implementation slices consume. Source of truth for the principles themselves:
[`strategy-principles.md`](strategy-principles.md); for host mechanics:
[`host-capability-map.md`](host-capability-map.md).

Legend: `none` = no code expresses the principle today.

## Raid + espionage (RAID-004..014, INT-003..010)

| principle | current implementation | missing signals | integration point | impact | prio |
| --- | --- | --- | --- | --- | --- |
| RAID-004 | none | target `Planet.time_last_update`, activity star at planning time | `RaidPlanner::plan()` + `PlayerObservationService::targetReports()` | medium | P1 |
| RAID-005 | `'confidence' => 1.0` hardcoded in `targetReports()` | per-type report-age decay | `PlayerObservationService::targetReports()` | medium | P1 |
| RAID-006 | `'travel_cost' => 0.0` placeholder; `NativeRaidEstimator::sample()` = `$loot − $loss` | distance/fuel, slot occupancy | `NativeRaidEstimator::sample()` or fill `travel_cost` | medium | P1 |
| RAID-007 | none | archetype, relationship state | `RaidPlanner::plan()` / `raidCandidatesFromVisibleReports()` | medium | P2 |
| RAID-008 | none | target vs own score | `RaidPlanner::plan()` pre-filter | low | P2 |
| RAID-009 | none | coordinate distance, storage fill, clustering | `RaidPlanner::plan()` / `targetReports()` ordering | high | P1 |
| RAID-010 | none | target `time_last_update` at dispatch | `QueueAiRaidAction::handle()` abort/delay | low | P1 |
| RAID-011 | flat `p20NetProfit <= 0.0` gate | loot/fuel/debris/defence tier (3:1/2:1/1.5:1) | `RaidPlanner::plan()` | low | P2 |
| RAID-012 | `QueueAiRaidAction` sends whole `getShipUnits()`; `FIRST_CARGO_AMOUNT = 1` | report resources, loot multiplier, cargo capacity +20% | `QueueAiRaidAction` / `RaidPlanner` cargo count | medium | P1 |
| RAID-013 | none | target popularity, galaxy density, competitors | `RaidPlanner::plan()` / `targetReports()` | medium | P2 |
| RAID-014 | `NativeRaidEstimator` excludes debris (already correct) | explicit debris/second-trip record | `NativeRaidEstimator` record only | low | P2 |
| INT-003 | `QueueableSpyPlanner::target()` first-fit `orderBy('id')` limit 20 | distance, position, yield, novelty | `QueueableSpyPlanner::target()` score | medium | P1 |
| INT-004 | none | planet `time_last_update`, activity status | `QueueableSpyPlanner` + `targetReports()` | medium | P1 |
| INT-005 | none | planet-context action attribution | activity reader | low | P1 |
| INT-006 | none | moon vs planet `time_last_update` | `inboundThreat()` / save planner | medium | P2 |
| INT-007 | none | own post-dispatch activity | queue actions (don't-add guarantee) | low | P2 |
| INT-008 | `QueueAiSpyAction` sends one probe | espionage tech, `canRevealData` thresholds | `QueueAiSpyAction` / `QueueableSpy` probe count | medium | P2 |
| INT-009 | none | probe count per target, target activity | `QueueableSpyPlanner::target()` | low | P2 |
| INT-010 | none | per-planet `time_last_update` | shared activity reader | low | P2 |

## Fleetsave + fleet composition + fleetcrash (FS, FLE, CRASH)

| principle | current implementation | missing signals | integration point | impact | prio |
| --- | --- | --- | --- | --- | --- |
| FS-001 | reactive only (`currentPlayerUnderAttack`) | offline window, fleet value, exposure | `inboundThreat()` + `QueueableFleetSavePlanner` | medium | P1 |
| FS-002 | only `SaveFailurePolicy` skip varies | landing time/route variation | `QueueableFleetSavePlanner` + `SAVE_SPEED` | low | P1 |
| FS-005 | first other own planet, speed 1.0 | destinations × speed, fuel, exposure | `QueueableFleetSavePlanner::plan()` route loop | medium | P1 |
| FS-006 | `new Resources()` (empty cargo) | planet stock, cargo capacity, loot cap | `QueueAiFleetSaveAction::handle()` | low | P1 |
| FS-007 | none | return time, duration, buffer band | `QueueableFleetSavePlanner` | medium | P1 |
| FS-008 | none | departure timestamp, post-save window | after `QueueAiFleetSaveAction` | low | P2 |
| FS-009 | single deployment of whole fleet | fleet size, slots, recycler count | `QueueableFleetSavePlanner` | high | P2 |
| FS-010 | none (`AiActionType::RecallFleet` unused) | in-flight deployment, recall moment | recall path over `cancelMission` | medium | P1 |
| FLE-004 | none (roles only: cargo/defence/escort/probe) | stage, target composition, debris | `QueueableUnitPlanner` | high | P1 |
| FLE-005 | none | opponent mix, rapid-fire graph, fodder ratio | composition layer | medium | P1 |
| FLE-006 | none | target defence, gauss/plasma presence | `QueueableUnitPlanner` escort | medium | P1 |
| FLE-007 | `bestByProperty('attack')` only | per-unit rapid-fire attributes | unit planner counter selection | medium | P1 |
| FLE-008 | fixed role order | universe age, target defence | `QueueableUnitPlanner` | medium | P1 |
| FLE-009 | none | expected debris, recycler capacity | `QueueableUnitPlanner` + `calculateRequiredRecyclers` | medium | P1 |
| FLE-010 | `FIRST_CARGO_AMOUNT = 1` | payload size, cargo capacity | `QueueableUnitPlanner` amount | low | P1 |
| FLE-011 | none | debris amount, recycler count, class bonus | recycle planner over `DebrisFieldService`/`RecycleMission` | medium | P1 |
| CRASH-001 | debris excluded (correct), no recycle trip | debris amount, recycler count/timing | `RecycleMission` path alongside raid | medium | P1 |
| CRASH-002 | none | phalanx level, range, scan cost, moon | phalanx planner over `PhalanxService` | medium | P1 |
| CRASH-003 | none | flight time, recall window | recall executor over `cancelMission` (ownership re-check) | medium | P1 |
| CRASH-004 | none | moon presence, jump-gate cooldown | save planner geography + jump-gate planner | medium | P2 |
| CRASH-005 | none | moon presence, deathstar, type 9 | offensive `MoonDestructionMission` planner | high | P2 |
| CRASH-006 | none | departure body type, phalanx range | `QueueableFleetSavePlanner` moon endpoints | medium | P1 |
| CRASH-007 | none | own phalanx level/range, scan cost | shared `PhalanxService` use | medium | P1 |
| CRASH-008 | none | DF visibility, recycler arrival, drive tech | observer/recycle timing | medium | P2 |

## Host seams verified (read-only)

- `FleetMissionService::createNewFromPlanet(...)` — module uses it only in `QueueAiFleetSaveAction`.
- `FleetMissionService::currentPlayerUnderAttack()` — hostile types `[1,2,6,9]`, `processed=0`.
- `FleetMissionService::cancelMission(FleetMission)` — early-returns on same-planet relocation and after arrival; **no ownership check** (host R5, deferred).
- `PhalanxService::calculatePhalanxRange` = `level²−1` (Discoverer +20%); `getScanCost` = 5000.
- `JumpGateService::calculateCooldown` = `60 / fleetSpeedWar` min, −10%/level, min 1 min.
- `DebrisFieldService::calculateRequiredRecyclers` = `ceil(totalDebris / recyclerCapacity)`.
- `RecycleMission` type 8, `hasReturnMission`, requires recycler (pos 1–15) or pathfinder (pos 16).

**Conclusion:** the host supports every seam listed; `grep` confirms **zero module callers** in `app/` for
`PhalanxService`, `JumpGateService`, `DebrisFieldService`, `RecycleMission`, `FleetUnionService`,
`AcsDefendMission`, and `cancelMission`. The gap is module wiring, not host capability — matching
[`GAP-REGISTER.md`](../GAP-REGISTER.md) wave 6.

## Integration priority clusters (P1 first)

1. **`PlayerObservationService::targetReports()`** — one change unblocks RAID-004/005/006 and INT-004
   (activity, per-type confidence decay, real travel cost). Highest leverage, smallest diff.
2. **`RaidPlanner::plan()`** — RAID-008/009/011 tiered gate + target ordering.
3. **`QueueableSpyPlanner::target()`** — INT-003 target score (replace id-order first-fit).
4. **`QueueableFleetSavePlanner`** — FS-001/002/005/006/007 proactive save + route scoring.
5. **`QueueableUnitPlanner`** — FLE-004..011 composition, recycler role, cargo sizing.
6. **New planners (fleetcrash)** — CRASH-002/003/005/006/008 phalanx/recall/moon — only after 1–5, and
   only when a validated cluster is reviewed (they are advanced/niche per Pass 4).

## ACS — architecture mapping (summary)

ACS is an **alliance-gated** mechanic (host `allianceCombatSystemOn`, `FleetUnionService` type 2,
`AcsDefendMission` type 5) with **no module planner or action**. The full ACS principles
(ACS-001..012) live in [`strategy-principles.md`](strategy-principles.md) under **ACS / coordination**.
The two Origin-board tutorial anchors (ORG-009/010) remain unfetched (Wayback capture attempt failed);
the Gameforge alliance guide (GF-003) is the verified anchor. ACS execution stays deferred to Package 6
(alliance life), consistent with the existing scope decision.

## Pass-6 gap domains — architecture mapping (15 September 2026)

The gap pass (3 agents) added 24 principles: NIN-001..005, EXP-001/002, CRASH-009..014, FLE-012..015,
COL-003/004, ACS-013/014, INT-011/012, FS-011. The mapping below reuses the integration points
verified in the pass-3 map; the planner code was not re-read for these.

| principles | current implementation | integration point | impact | prio |
| --- | --- | --- | --- | --- |
| NIN-001..005 | none — no defence/reaction planner exists | new ninja executor over `currentPlayerUnderAttack` + fleet timing, after the F-series | high | P2 |
| CRASH-009..014 | none | F1/F2/F4 over `PhalanxService` / `cancelMission` / `RecycleMission` (host-supported, unwired) | high | P1 |
| FLE-012..015 | fixed roles, first-fit, no launch subset | `QueueableUnitPlanner` (production) + `RaidPlanner`/`NativeRaidEstimator` (launch subset) | high | P1 |
| COL-003/004 | `QueueableColonyPlanner` slot choice only | `QueueableColonyPlanner` timing + spread | medium | P2 |
| ACS-013/014 | none (alliance-gated) | ACS planner (Package 6) | medium | P3 |
| INT-011/012 | `QueueableSpyPlanner` ignores origin + time-of-day | `QueueableSpyPlanner::target()` + dispatch origin | low | P2 |
| FS-011 | `QueueableFleetSavePlanner` deployment only | harvest/hold mission routes in the save enumeration | medium | P2 |
| EXP-001/002 | none | new expedition executor — host expedition surface **not re-verified** (ORG-012 only) | medium | P2 |

The **ninja** and **expedition** domains have no current executor at all — they are new integration
points, not wiring of an existing seam (unlike phalanx/recycle/recall, which are host-supported and
unwired). Expedition host support is not re-verified; mark unsupported-until-verified, never discard.
