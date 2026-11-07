# Enhancement plan — PiecePaperCode/barakis

## Verdict summary (one line)

Barakis is a thin, hardcoded build-queue bot whose every real mechanism we already ship in a
host-read, human-shaped form — the only net value is two small contrasts that expose gaps in our
own planners (solar-satellite energy and a standing defence floor), everything else is ALREADY-DONE
or REFUSE.

## ADOPT-IDEA — table: | Mechanism | What it does | Where in our module | Why worth it |

None. Every mechanism barakis has is either already shipped in a stronger host-read form (see
ALREADY-DONE) or fails a gate (see REFUSE). Per `gameforge-policy.md` there is nothing to port, and
per the cognition gates there is no mechanism worth building a second, parallel version of.

## ENHANCE — table: | Our current | What the repo does better | Proposed change | Files touched |

| `EnergyCapacity` only ranks objects `BuildingQueueObject::accepts()` accepts, so solar satellites — a unit-queue energy producer — never answer an energy deficit | barakis always falls back to `solar_satellite` when energy is short and its building candidates are exhausted (M06), which is ordinary play | let `EnergyCapacity::pending()` also consider host-reported energy producers outside the building queue (units like solar satellite), keeping cheapest-first and the existing `outdrawn()` trigger unchanged | `app/Domain/Decision/EnergyCapacity.php` |
| `QueueableUnitPlanner` builds defence only reactively, when `currentPlayerUnderAttack()` is true; a turtle/miner that is never probed sits at zero defence forever despite `TurtlePolicy` weighting `QueueUnits` at 1.0 | barakis builds defence proactively and continuously up to a cap (M34–M40), regardless of an inbound attack | add a standing-defence branch: when no attack is inbound and the persona is turtle/miner, queue the best attack-per-cost defence piece up to a persona-scaled floor; keep the existing reactive branch first | `app/Domain/Decision/QueueableUnitPlanner.php`, `app/Domain/Decision/Policies/TurtlePolicy.php` |

## ALREADY-DONE (confirmed by grep) — list with file path

- Energy-first ordering (solar plant before mine, cheapest capacity first, host-read) — `app/Domain/Decision/EnergyCapacity.php`
- Payback-ranked mine upgrades (better than barakis's fixed `< 25 / < 15` level caps) — `app/Domain/Decision/EconomyUpgrades.php`
- Facility prerequisite chain (robotics/shipyard/lab/nanite, host-read, no caps) — `app/Domain/Decision/FacilityChain.php`
- Storage-overflow building, triggered by the account's own absence — `app/Domain/Decision/EconomyUpgrades.php` (`storage()`)
- Reserve floor before a spend (mining while short) — `app/Domain/Decision/ReserveFloor.php`
- Research toward host missions (astrophysics for colonise/expedition, host-read) — `app/Domain/Decision/FacilityChain.php` (`missionRequiredResearch()`)
- Colony ship + colonisation (host-read colonise mission, bounded empty-slot walk) — `app/Domain/Decision/QueueableUnitPlanner.php`, `app/Domain/Decision/QueueableColonyPlanner.php`
- Reactive defence (best attack-per-cost from `getDefenseObjects()`) — `app/Domain/Decision/QueueableUnitPlanner.php` (`bestDefense()`)
- Human-shaped session cadence (dark period, Weibull jitter, absences) vs barakis's 24/7 5–60 s loop — `app/Domain/Routine/SessionPlanner.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php`
- One action per planet per pass (multi-pass: storage → surplus → routine) — `app/Domain/Decision/QueueableBuildingPlanner.php`
- Session lifecycle through the host (no re-login, `PlayerGameStateService::advance`) — `app/Actions/RunAiSessionAction.php`
- Account enable/disable instead of an in-memory credential registry — `AiProfile` (`enabled`), host owns accounts

## REFUSE — list with one-line reason

- Hardcoded ordered build-priority table with fixed level caps (`buildings.py`) — gate 1: object ids, caps and thresholds as source of truth; adding a host object needs a module edit.
- In-memory plaintext-credential account registry + unauthenticated REST `/start`/`/remove`/`/active` — security; the host owns auth/accounts, and a credential sidecar is a forbidden host-side daemon.
- 24/7 fixed-cadence re-login scheduler loop — gate 3 machine-shaped play; our `SessionPlanner` already does the human shape.
- Proxy-rotation anti-detection (`proxys.py`) — broken as shipped (`requests.get('')` raises) and evasion is not our authenticity approach.
- `requirements.txt` redis + `stop()` dead code — nothing to adopt.
- `queue()` re-login per pass (`del empire`) — pointless against our host-native session; the host keeps the session.

## Priority recommendation — single highest-value change, 3-5 lines

Standing defence (ENHANCE row 2). It closes a real coherence gap: `TurtlePolicy` already says the
turtle queues units first, but the unit planner only ever emits defence while `underAttack` is true,
so a turtle that is never probed keeps zero defence. The change is one reactive-first branch plus a
persona-scaled floor in `QueueableUnitPlanner`, host-read via `getDefenseObjects()` — small, nameable
("a miner keeps a modest standing defence"), and it makes the shipped turtle archetype actually
behave like one.

## Open questions / risks

- Standing-defence floor size: tie to persona (`Miner`/`Turtle`) and planet count, not a fixed count (gate 1).
- `bestDefense()` ranks by `attack` only, so shield domes (HP, no attack) are never selected — confirm whether a dome-only floor is needed before touching it.
- Solar satellites are destroyed by attack; a satellite energy fallback must not rebuild them after every raid or it reads as machine-shaped.
- Both enhancements touch the unit planner's ordering; verify no existing Pest dataset asserts defence is strictly reactive before changing the branch.
