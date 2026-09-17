# Enhancement plan — alaingilbert/ogame

## Verdict summary (one line)
An API wrapper with no decision engine: its object universe and formulas are hardcoded (gate-1 REFUSE) and its scraping/login/fingerprint layer targets the official API we never touch, so the only survivors are two host-read ideas — a defenceless-target intel signal and a phalanx fleet scan — plus one confidence fix.

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Espionage-report "defenceless" signal (`IsDefenceless`, `Has{Fleet,Defenses}Information`) | A report whose fleet and defence sections are both present-and-empty is flagged defenceless, so a caller can act without a battle-engine screen | `app/Domain/Perception/PlayerObservationService.php` (`targetReports()` output) + consumer in `app/Domain/Decision/RaidPlanner.php` | Host `espionage_reports.ships`/`defense` are nullable JSON: `[]` means "probed and empty". One host-read boolean lets the scorer prefer safe targets or the raid gate skip the 50-sample estimator for a provably empty target. |
| Phalanx fleet scan as a fleeter routine | Scan a foreign body's in-flight fleets from an owned moon to time a recall/slowed fleet | New `QueueablePhalanxPlanner` mirroring `app/Domain/Decision/QueueableSpyPlanner.php`, driven by host `PhalanxService::calculatePhalanxRange()` / `canScanTarget()` / `scanPlanetFleets()` | Host already owns range, range-check, 5000-deut cost and the scan; the module only adds feasibility taste (moon + phalanx level + deut). A real fleeter action the module does not ship. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `targetReports()` confidence is age-only: one `intelConfidence(..., 'resources')` decay on the message timestamp | The repo models confidence as *info completeness* — which sections the probe level returned — not just freshness; a resources-only probe cannot answer "raid it or not" even when fresh | Downgrade the published confidence by whether `ships`+`defense` are present on the host report row (`null` = unknown, never a raise): a fresh report with no fleet/defence section scores lower than one that returned both | `app/Domain/Perception/PlayerObservationService.php`, `app/Domain/Perception/ActivityIntelReader.php` |

## ALREADY-DONE (confirmed by grep)
- **Distance / flight duration / fuel** — host `app/Services/FleetMissionService.php` (`calculateFleetMissionDistance`, `calculateFleetMissionDuration`, `calculateConsumption`).
- **Price / raw price / cumulative / downgrade** — host `app/Services/ObjectService.php` (`getObjectPrice`, `getObjectRawPrice`, `getObjectCumulativeCost`, `getObjectDowngradePrice`).
- **Build / unit / research duration** — host `app/Services/PlanetService.php` (`getBuildingConstructionTime`, `getUnitConstructionTime`, `getTechnologyResearchTime`).
- **Mine production / energy** — host `PlanetService::get*ProductionPerHour` + `ObjectService::getGameObjectsWithProduction()`.
- **Requirements & availability** — host `ObjectService::objectRequirementsMet`, `getRecursiveRequirements`, `objectValidPlanetType`; module `FacilityChain.php` names nothing.
- **Object registry & classification** — host `ObjectService::getUnitObjects`, `getShipObjects`, `getDefenseObjects`, `getMilitaryShipObjects`, `getCivilShipObjects`.
- **Plunder / debris / moon chance / defense rebuild** — host `app/GameMissions/BattleEngine/` (`LootService`, `DefenseRepairService`, `calculateDebris`, `calculateMoonChance`); sampled read-only by `app/Infrastructure/Battle/NativeRaidEstimator.php`.
- **Fleet dispatch guards (vacation, slots, attack block)** — host `app/GameMissions/*Mission.php::isMissionPossible`; module re-checks ban/vacation in `app/Actions/QueueAi*Action.php`.
- **Cargo-capacity clamp + atomic debit** — host `FleetMissionService::createNewFromPlanet`; module selects just-enough hulls in `app/Actions/QueueAiTransferAction.php::transportFleet`.
- **Phalanx range / target check / scan cost** — host `app/Services/PhalanxService.php` (`calculatePhalanxRange`, `canScanTarget`, `hasEnoughDeuterium`, `getScanCost`, `scanPlanetFleets`).
- **IPM/ABM target validity** — host `app/GameMissions/MissileMission.php::isMissionPossible`.
- **Mission IDs / fleet speeds** — host `app/GameMissions/*.php` `getTypeId()`; module reads `XMission::getTypeId()` (`QueueableFleetSavePlanner.php`, `QueueableSpyPlanner.php`).
- **Espionage intel freshness / activity / scoring** — `app/Domain/Perception/PlayerObservationService.php::targetReports` + `app/Domain/Perception/ActivityIntelReader.php`.
- **Retry & single-action serialization** — Laravel queue + Horizon job retries (`app/Jobs/`) and host mission locks; the repo's HTTP retry/backoff has no analogue here.

## REFUSE — list with one-line reason
- **Object universe + IDs + per-object files (M03–M08, M11)** — gate-1 hardcoded AI; host `ObjectService` is the authority, adding a host object must need no module edit.
- **Formula library (M12–M26: times, combat scaling, cargo, fuel, speed, distance, flight, phalanx range, production, energy, plunder)** — the host already computes every one; a module port is forbidden duplication and a second authority that can drift.
- **Scraping/parser layer (12 versioned HTML extractors, JSON-shape login detection)** — targets the official API we never call; gate-2 machinery with no host equivalent needed.
- **`pkg/device` browser fingerprint + blackbox cipher (M37–M40)** — anti-detection for botting the API; our authenticity is behavioural (gates/`account-authenticity.md`), and a fake fingerprint plays the host, not the API.
- **`ogamed` HTTP daemon** — a host-side daemon, explicitly out of bounds.
- **Task-runner priority queue (M29) + exponential backoff (M28)** — Laravel queue/Horizon and host mission locks already serialize; gate-2 machinery we would never exercise.
- **Officer / highscore / marketplace input validation (M31–M33)** — the host validates its own endpoints; the module drives none of them.
- **Fleet-speed enum + per-mission speed picker (M02, M36)** — host `createNewFromPlanet` already takes `speedPercent`; the module's fixed persona speeds are gate-3 taste, not a gap.
- **`pkg/simulator`** — stale second battle engine; `NativeRaidEstimator` samples the host `PhpBattleEngine` as the single authority.
- **`UnsafePhalanx` invalid-coordinate scan** — the repo documents an instant account ban; host `PhalanxService::canScanTarget` already rejects out-of-range, keep it that way.

## Priority recommendation — single highest-value change
Ship the **defenceless-target signal**: one `defenceless` boolean in `PlayerObservationService::targetReports()`, true when host `espionage_reports.ships` and `defense` are both present and empty, consumed by the raid scorer/gate to prefer or short-circuit provably-empty targets. It is host-read (gate 1), a one-field addition to an existing reducer (gate 2), and names "the probe says it's empty, raid it" (gate 3). Touches one reducer plus one consumer, no new dependency, and lands exactly in the current P1 cluster.

## Open questions / risks
- **Empty vs unseen:** host must distinguish `ships/defense = []` (probed, empty) from `null` (probe level too low). Only `[]` may count as defenceless; `null` is unknown and must never raise confidence.
- **Phalanx prerequisites:** needs an owned moon with a sensor phalanx and 5000 deut; a fresh account has none — the planner must fall through cleanly, like the spy planner without a probe.
- **Phalanx ban risk:** never scan an invalid or out-of-range coordinate; host `canScanTarget` is the only gate, and the repo's ban warning belongs in a code comment there.
- **Confidence downgrade is additive:** it must not break the existing RAID-005 freshness contract that the scorer and raid gate already consume.
- **Phalanx reads foreign fleet state:** it must enter through the same legal-observation path as `targetReports` (published reports), never a direct reach into a target's mission rows.
