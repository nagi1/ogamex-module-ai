# Enhancement plan — halfguru/ogamebot

## Verdict summary
We already ship every mechanism the repo's fleet-save, build, farm and colonize workers describe — mostly in a more gate-compliant, host-read form — so the repo's value is two small ideas (colonize the bigger slots; never fleetsave into a body that is itself under attack), not any of its code.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Colonize the bigger slots (`preferPositions [4,5,6,7,8]`) | Prefers the mid slot positions (largest/most valuable planets) instead of the first empty slot | `app/Domain/Decision/QueueableColonyPlanner.php` `emptySlot()` | Pure position *taste* over host coordinates (Gate 1 safe). Named play: "an experienced player colonises the bigger slots." |
| Never fleetsave into a body under attack (escape `SafetyScore +1000`) | Excludes/penalises an own body that has an inbound hostile when picking where to park the fleet | `app/Domain/Decision/QueueableFleetSavePlanner.php` `rankedDestinations()` | Closes a real fleet-loss risk; named play: "don't deploy the save into another incoming attack." Host-read via the inbound picture we already assemble. |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `rankedDestinations()` orders own bodies moon-first, then distance — an own planet under attack is a candidate like any other | Escape-route scoring adds `+1000` to a destination that is itself under attack, logs "no safe destination" when none is left | Filter own-body destinations whose `planet_id` appears in the inbound fleet picture before the moon/distance sort; if none remains, return no save | `QueueableFleetSavePlanner.php` (`saveFor`/`rankedDestinations`); inbound rows already flow through `PlayerObservationService::inboundThreat()` |
| `emptySlot()` returns the first empty `canColonizePosition` slot from a seeded walk | Position scoring `len(preferPositions) − index`, sorted score-desc then distance-asc | Score each empty slot by position (mid slots first, seeded walk keeps accounts from converging), then distance; return the best | `QueueableColonyPlanner.php` (`emptySlot`) |

## ALREADY-DONE (confirmed by grep)

- Host-read hostile detection + reaction latency — `PlayerObservationService::inboundThreat()`.
- Save that sometimes fails — `SaveFailurePolicy.php` (deterministic 1/30).
- Proactive save before a real absence — `QueueableFleetSavePlanner::proactivePlan()`.
- Phalanx-safe deploy + split save — `rankedDestinations()`, `shadowDestinationPlanetId()`, `QueueAiFleetSaveAction`.
- Recall the parked save — `recallPlan()` + `QueueAiRecallAction`.
- ROI mine ranking + trade band — `EconomyUpgrades.php` (`M + 1.5C + 2D`).
- Storage-overflow trigger — `EconomyUpgrades::storage()` / `spendSurplus()`, `RaidPlanner::RAID_STORAGE_FILL_RATIO`.
- Energy-first + facility chain — `EnergyCapacity.php`, `FacilityChain.php`.
- Seeded variation / near-tie choice — `UtilityScorer.php` (`seeded_variation`, `select()`).
- Spy targeting that skips active targets — `QueueableSpyPlanner.php::target()`.
- Raid gating — `RaidPlanner.php` (bashing limit 6, lower-tail profit, loot tier, fresh intel).
- Colony planner — `QueueableColonyPlanner.php` (host-read empty slot, `MAX_SCANS`, seed).
- Session scheduling with jitter + material-event wake — `SessionDecisionService` / `SessionPlanner` / `NextDueTimeCalculator`.

## REFUSE

- Hardcoded object universe (`BuildingDefs`, `ResearchDefs`, `shipDB`, `maxLevels`, `researchOrder`) — Gate 1.
- 60s polling state manager + goroutine worker loops — forbidden host-side daemon/poll loop.
- Dead rate-limit config — nothing to adopt.
- "Probe 10 inactives then attack every profitable one in one cycle" — machine cadence.
- Colonizer firing up to 3 colony ships in one poll — machine cadence.
- Builder cooldowns (10 min fail / 2 min success) — artifact of a tight poll loop.
- Non-production ROI magic numbers — Gate 2 machinery with no human name.
- SolidJS web dashboard — not module scope.
- `universeSpeed` hardcoded to 1 and hand-rolled flight/fuel formulas — host supplies both.

## Priority recommendation

Adopt the **"never fleetsave into a body under attack"** guard in `QueueableFleetSavePlanner::rankedDestinations()`. It is a safety fix, not cosmetic: the planner can park the save on any own body, including one with its own inbound hostile. One filter over the inbound picture we already assemble; a one-liner test fails it (a destination whose `planet_id` is in `inbound_fleets` must never be ranked first).

## Open questions / risks

- Filter semantics: exclude a threatened destination outright, or only deprioritise? Excluding is simpler; confirm it never leaves a save un-dispatchable when *every* own body is under attack (then we correctly do nothing).
- Slot-size preference: read "larger slot first" from host data where available, not a fixed `[4,5,6,7,8]` list, to stay Gate 1 pure.
- Where the destination filter reads inbound data: pass the already-computed `inbound_fleets` `planet_id_to` set from `PlayerObservationService::inboundThreat()`, avoiding a second host query.
