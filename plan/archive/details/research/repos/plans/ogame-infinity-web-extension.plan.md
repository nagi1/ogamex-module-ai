# Enhancement plan — ogame-infinity/web-extension

## Verdict summary (one line)
OGI is a human-assist browser extension whose only transferable idea is **how a player composes and rotates an expedition fleet**; the rest is either already shipped host-read in our module, or browser/UI surface and hardcoded tables we refuse.

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Expedition fleet composition (M06) | Sends a balanced expedition fleet — a combat escort, a pathfinder, a cargo ship and a probe — not one lone civil hull | `app/Domain/Decision/QueueableExpeditionPlanner.php` | A competent player sends the strongest combat ship so the fleet survives a pirate, a pathfinder for the find/speed bonus, and a cargo to carry the find; ours sends one small cargo, which is machine-shaped and under-earning |
| Expedition system rotation (M07) | After N outgoing expeditions from one system, rotate the origin to another own system | `app/Domain/Decision/QueueableExpeditionPlanner.php` | A player spreads expeditions across their systems instead of hammering one; ours always uses position 16 of the first eligible planet |
| Debris valuation of defended raids (M14) | Values the defender's fleet debris when deciding a defended raid | `app/Domain/Decision/RaidPlanner.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php` | A fleeter raids defended targets partly for the debris; ours only relaxes the loot tier 3.0→2.0 with no yield number — confirm first whether the host battle engine result already returns debris before adding anything |

All three are built host-read (see ENHANCE): ship roles from `ObjectService::getMilitaryShipObjects()` / `getCivilShipObjects()`, stats from `UnitObject::$properties` (attack/shield/speed/capacity), never a machine name or a cost table.

## ENHANCE — table

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `QueueableExpeditionPlanner::disposableShip()` picks the smallest civil hull and dispatches that alone | `expedition()` composes a fleet: combat ship by priority, pathfinder, cargo sized to the find, probe; rotates system | Select the strongest combat hull by host-read `attack` among `getMilitaryShipObjects()`, the fastest civil hull (pathfinder) by host-read `speed` among `getCivilShipObjects()`, plus the existing smallest cargo; carry the cargo count/role into `QueueableExpedition` payload; rotate origin system after N outgoing expeditions (count from `AiWorkItem` payload or decision trace) | `app/Domain/Decision/QueueableExpeditionPlanner.php`, `app/Domain/Decision/QueueableExpedition.php`, `app/Actions/QueueAiExpeditionAction.php` (fleet assembly on dispatch) |
| `RaidPlanner::clearsLootTier()` uses a flat 2.0 tier for defended targets with no debris figure | `recyclingYieldCalculator` computes `fleetCost * debrisFactor` (+ defence when deut in debris) | If the engine result lacks debris, add `fleetValue * debrisFactor` (host `SettingsService` `debrisFactor`, defence value host-quoted) to `p20NetProfit` inside `NativeRaidEstimator::estimate()` | `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php` |

The repo's cargo/points sizing (`EXPEDITION_TOP1_POINTS`, `EXPEDITION_MAX_RESOURCES`, `SHIP_EXPEDITION_POINTS`) is **not** ported: the host exposes no per-ship expedition-points property, so that math would be a hardcoded table (gate 1). We size cargo by capacity only.

## ALREADY-DONE (confirmed by grep)

- ROI / payback ranking of production and storage-fill ETA (repo M31, M29) — `app/Domain/Decision/EconomyUpgrades.php` (`rankedProduction`, `paybackHours`, `storage`, `timeToFill`), host-read via `ObjectService::getGameObjectsWithProduction()`.
- Metal-equivalent trade band / standard unit (repo M36) — `CRYSTAL_WEIGHT`/`DEUTERIUM_WEIGHT` in `EconomyUpgrades.php`, `RaidPlanner.php`, `NativeRaidEstimator.php`, `QueueableSpyPlanner.php`, `QueueableUnitPlanner.php`.
- Energy before mine, cheapest capacity first (repo M30 solar-satellite idea) — `app/Domain/Decision/EnergyCapacity.php`, host-read via `getObjectProduction(…, 100%)`.
- Galaxy activity window and intel freshness (repo M16/M17) — `app/Domain/Perception/ActivityIntelReader.php` (`activityAt`, `activityProbabilityAtEta`).
- Raid policy: bashing limit, loot tier, round-trip fuel (repo custom-mission/raid idea) — `app/Domain/Decision/RaidPlanner.php`.
- Battle sampling via the host's own engine, never a second engine (repo has no engine at all) — `app/Infrastructure/Battle/NativeRaidEstimator.php`.
- Fleet save as own-planet deployment with shadow split (repo M05 collect) — `app/Domain/Decision/QueueableFleetSavePlanner.php`, `SaveFailurePolicy.php`.
- Scouting target selection, freshness, in-flight skip (repo has no planner) — `app/Domain/Decision/QueueableSpyPlanner.php`.
- Ferry between own bodies (repo collect/trade) — `app/Domain/Decision/QueueableTransferPlanner.php`.
- Keep-on-planet floors (repo M42) — `app/Domain/Decision/ReserveFloor.php`.
- Record TTL cleanup (repo M25) — `app/Actions/PruneAiRecordsAction.php`, scheduled by `app/Console/Commands/PruneAiRecords.php`.
- Combat-report parse/appraisal (repo M22/M24) — `app/Actions/AppraiseObservedBattleReportAction.php`, `MapObservedBattleReportToStimulusAction.php`.
- Host-read prerequisite/requirement chain (repo hardcodes it; we derive it) — `app/Domain/Decision/FacilityChain.php`.

## REFUSE

- PTRE activity send / galaxy diff / spy import (M18, M19, M52) — third-party galaxy service, not host data; would need an external account and a daemon-ish pusher.
- Pantry cloud sync (M27) — external JSON store; the module already persists state in the host DB.
- DOM automation, redirect-chain modes, one-click harvest/collect/raid chains (M01–M05, M08–M11, M41) — browser page-context; we act through host APIs, no page.
- Message analyzers and Levenshtein expedition-type classifier (M22, M23) — we read host data, never parse page HTML or message text.
- Hardcoded cost/time/production/storage/crawler/solar-satellite formulas (M30, M32–M35, and M06's points tables) — gate 1 violation; the host's own `ObjectService`/`PlanetService` answers are the source of truth.
- Welcome wizard, keyboard shortcuts, number formatting, coordinate packing, player-status decode, side-stalk, search history, target markers, simulator deep-links, import/export/reset (M43–M56) — browser UI surface, no server-side counterpart.
- Auto-delete messages (M57) — page feature with no host-API analogue.

## Priority recommendation

Make `QueueableExpeditionPlanner` dispatch a real expedition fleet instead of one small cargo: strongest combat hull (host-read `attack`), fastest civil hull as pathfinder (host-read `speed`), the smallest cargo, and a probe, then rotate the origin system after N outgoing expeditions. This is the single largest authenticity gap the repo exposes — our current account sends a lone cargo on expedition, which no experienced player does — and it is fully expressible host-read with no new dependency and no hardcoded table. Debris valuation is second and smaller; verify the battle engine result first.

## Open questions / risks

- The host has **no per-ship "expedition points" property** (`UnitObject::$properties` exposes only structural_integrity/shield/attack/speed/capacity/fuel). The repo's cargo-size-by-points and top-1 bracket math is therefore REFUSED; our cargo stays capacity-sized. Confirm no host API exposes expedition points before reconsidering.
- Pathfinder selection = "fastest civil hull" by host-read `speed`. If a mod adds a faster civil hull, the escort changes — that is gate-1-correct behaviour, but verify `ObjectService::getCivilShipObjects()` and the speed ordering in a test.
- System rotation needs the account's outgoing-expedition count per system; store it in the `QueueableExpedition` payload / decision trace, not a new table, to keep gate 2 small.
- Top-1 bracket (`EXPEDITION_TOP1_POINTS`) needs a host ranking API; verify `PlayerService` exposes the top score before considering any bracket, else keep the fleet composition bracket-free.
- Debris: check whether `PhpBattleEngine::simulateBattle()` result already includes defender debris before adding `debrisFactor` math, to avoid double-counting.
