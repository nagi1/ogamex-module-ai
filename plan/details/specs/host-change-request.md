# Host change request — what the module needs from OGameX

14 September 2026. The other direction of the same survey as
[the host capability map](../research/host-capability-map.md): that file asks what the host already
answers, this one asks for the small number of things it does not.

Rules for this file: a host obligation is closed by the host change and then the module is re-checked
against the merged revision — never the other way round. Every item states the **minimal** form, so scope
can be cut without losing the point, and states what the module does if the item never lands, so nothing
here is a silent blocker.

Ordered by what it unblocks, not by size.

| # | Ask | Size | Unblocks | If it never lands |
| --- | --- | --- | --- | --- |
| R1 | A read-only, seedable battle question on the battle engine | medium | Raids and any estimator (`G6`), plus byte-stable replay for the offline corpus | Raids stay recorded intents with a stated reason; the estimator has no deterministic replay |
| R2 | Queue-upgrade predicate: "may this object be upgraded now?" | small | Deletes `AiBuildingMachineName`, the last object-name list in module code (gate 1, `A3`/`B2`/`C3`) | The module keeps restating two machine names |
| R3 | Vacation/ban refusal inside the queue **services**, not only the controllers | small | Correctness of every module-issued queue entry (`O2`) | The module re-checks under its own lock and accepts a small race window |
| R4 | `getGameObjectsWithStorage()` covering stations, not only buildings | trivial | Storage enumeration stays honest for a mod-added station (`C5`, gate 1) | A mod-added station's storage is invisible to the planner |
| R5 | Ownership check inside `FleetMissionService::cancelMission()` | trivial | Recall safety once fleets exist (`G4`, `G8`) | The module re-does the check before every recall |
| R6 | The five remaining controller-only rules, published together | small | Removes five more places where module code restates host rules | The module keeps its own copies, each of which can drift |
| R7 | *(optional)* hourly `highscores` snapshot | small | Measuring the growth curve against human accounts on the same universe, not only against ourselves | The module records its own series and can only compare cohorts to each other |
| R8 | *(optional)* a way to stop `advance()` stamping `last_ip` from a queue context | trivial | `last_ip` honesty for scheduled work (`A3`/`A5`) | Accounts appear to log in from an empty address |

## R1 — A read-only, seedable battle question

**Where.** `app/GameMissions/BattleEngine/BattleEngine.php` and its `Php`/`Rust` implementations.

**What is true today.** `simulateBattle()` returns a `BattleResult`, but it is not a question:

- `applyTacticalRetreat()` writes to the world — `$this->defenderPlanet->deductResources(new Resources(0, 0, $decision->deuteriumCost, 0))`;
- `simulateBattle()` fires `BattleResolved`;
- the stochastic parts (`UnitObject::didSuccessfulRapidfire()`, `BattleUnit::damagedHullExplosion()`, `checkHamillManoeuvre()`, `rollMoonCreation()`) draw from `random_int` with **no seed parameter**, and the Rust engine has its own RNG.

**Why the module needs it.** The plan's raid estimator samples the engine and reports a lower-tail
statistic. Two properties are required and neither exists: the call must not change the world (or a
"what if" changes the account), and it must be seedable (or the same decision cannot be replayed, and two
candidate fleets are compared through different luck). The module has no intention of computing combat —
it wants to ask.

**Minimal shape.** Any of these satisfies the contract; the internal design is the host's call:

1. `simulateBattle(?int $seed = null, bool $pure = false)` on the existing class, with `$pure` suppressing
   the resource write and the event and `$seed` seeding every draw (including the Rust side, or the Rust
   path documented as not supporting pure mode);
2. a separate read-only entry point beside it — e.g. `BattleSimulator::estimate(array $attackers, PlanetService $defender, array $defenders, int $seed): BattleResult` — that reuses the same engine internals without the side effects.

The contract, explicitly: **no writes, no events, an explicit seed, and the engine stays the authority on
the rules**. `EspionageMission::executeCounterEspionageBattle()` is the existing precedent — it already
temporarily removes defence with `save = false` and restores it.

**What lands in the module.** `T2` becomes executable: candidates sampled with one shared seed stream,
screened at n = 50 and confirmed at n = 200, reported as losing-run count plus a P20 net profit, with a
byte-stable replay test. Until then the estimator is specified but unpublished, and the recorded reason is
this item.

**Verification when it lands.** The module re-checks: two calls with the same seed and the same inputs
return identical results; a pure call leaves `planets` resources, `fleet_missions`, debris fields and
events unchanged; the hostile-fleet path still behaves identically with `$pure` at its default.

## R2 — Queue-upgrade predicate

**Where.** The rule lives only in `AbstractBuildingsController::addBuildRequest()`:

```php
if (($request->input('technologyId') === '21' || $request->input('technologyId') === '15') && $player->isBuildingShipsOrDefense()) {
    return response()->json(['success' => false, 'errors' => [['message' => __('The Shipyard is still busy.')]]]);
}
```

`PlayerService::isBuildingShipsOrDefense()` exists and says units are building; nothing says **which
object** that blocks.

**Minimal shape.** One service-level answer, e.g. `PlayerService::isObjectUpgradeBlocked(int $object_id): bool`
(or `PlanetService`), returning the same answer the controller gives.

**Why the module wants it.** The module currently restates the rule as
`AiBuildingMachineName::unitQueueBlockers()` returning `['shipyard', 'nano_factory']` — a list of two
machine names in module code, which is exactly what gate 1 forbids. It cannot be deleted without this
predicate, because `BuildingQueueService::add()` accepts the request and the queue processor cancels it
later: the account would spend a queue slot and receive a receipt for an action that never happened.

**Verification when it lands.** The module deletes the enum and asks; a test asserts the module's answer
matches the controller's for a busy shipyard.

## R3 — Vacation and ban refusal inside the queue services

`AbstractBuildingsController`, `AbstractUnitsController` and `ResearchController` each refuse on
`isInVacationMode()`; the services check only while *processing*. A caller below the controller can
therefore add work that will never run and never be reported as failed.

**Minimal shape.** The same refusal inside `BuildingQueueService::add()`, `UnitQueueService::add()` and
`ResearchQueueService::add()`, or one shared `canAddToQueue` predicate the three call.

**Note on the interaction with `isBanned()`.** A ban is checked today only by the module's building action.
Making the ban behaviour explicit in one place — either the services or a documented predicate — is worth
more than the vacation half, because O2's finding is that a banned account keeps deciding.

**If it never lands.** The module re-checks immediately before the add and accepts that a state change in
between produces one lost queue entry.

## R4 — Storage enumeration that includes stations

`ObjectService::getBuildingObjectsWithStorage()` returns buildings with storage. A mod-added **station**
with storage is invisible to it, which makes the host's own catalogue the source of a gate-1 hole for the
module's storage rule.

**Minimal shape.** Either widen the method to all object kinds, or add
`getGameObjectsWithStorage(): array<GameObject>` beside it.

## R5 — Recall ownership in the service

`FleetMissionService::cancelMission()` performs no owner comparison; `FleetController::dispatchRecallFleet()`
does. Any non-controller caller must re-do it, and the module will be exactly such a caller once fleets
exist.

**Minimal shape.** The comparison inside `cancelMission()`, or a `cancelMissionFor(PlayerService, FleetMission)`
variant.

## R6 — The five remaining controller-only rules

Publishing these together turns five module-side restatements into five questions. Each is a
service-visible predicate, not a behaviour change:

| Rule | Controller location | Module consequence today |
| --- | --- | --- |
| Attack-block response | `FleetController::dispatchSendFleet()` → `SettingsService::missionBlockedByAttackBlock()` | The module must consult the setting and mirror the controller's decision |
| Expedition holding-hours bounds | `FleetController::dispatchSendFleet()` (`1 … astrophysics level`) | The module validates its own bounds |
| Fleet-speed whitelist | `FleetController` (1.0–10.0 in 0.5 steps; 0.5 for one class) | The module keeps its own whitelist |
| Coordinate bounds | `FleetController::validateCoordinates()`, `UniverseConstants` | The module validates before dispatch |
| Chat length, self-send and existence | `ChatController` | Already re-implemented in `DeliverAiDirectReplyAction` |

**Why it matters.** This is the same failure mode as R2, repeated: a rule that lives in a controller is
restated wherever else it is needed, and the two copies drift. The module's copies are currently correct —
the point is that nothing checks them.

## R7 — *(optional)* An hourly highscore snapshot

`highscores` holds current points with per-column ranks and **no history**, so the "public hourly growth
curve" that authenticity is judged on cannot be read from the schema. The module will record its own
series, which measures the cohort against itself; a host-side snapshot would let the curve be compared
against **human accounts on the same universe** — the measurement the goal actually names.

Minimal shape: one `highscore_history` row per player per hour (or one command writing a compact daily
rollup), plus the existing scheduler pattern.

## R8 — *(optional)* Let scheduled work not stamp `last_ip`

`PlayerGameStateService::advance()` is the documented seam for scheduled actors, and it writes
`users.time` **and** `last_ip = request()->ip()` from the ambient context — which for a queue or CLI run is
empty or loopback. The module calls it and wants the activity stamp; it does not want the address. Two
options: a parameter that suppresses the IP write, or a documented convention that a null/empty address
means "server-side work".

Related decision on the module side (no host change needed): whether that stamp should be *shaped* like
the routine or left incidental — recorded in
[`gameplay-algorithms.md` AG3](gameplay-algorithms.md#ag3--request-and-activity-footprint).

## Not requested — keep the host scope small

- **No new `QueueName` lane.** `FleetArrivals` / `FleetArrivalsHeavy` and the module's own Horizon setup
  are sufficient.
- **No new `ModuleSlotService::SLOTS` entry.** `admin.nav` is all the module registers.
- **No new `app/Events/Game/*` event.** `FleetMissionArrived` plus a `FleetMission` model observer covers
  the inbound-fleet observation (G8); `FleetMission` is already observable.
- **No marketplace, trade request or resource exchange.** None exists, the plan now says so plainly, and
  the trader persona is expressed through transport instead.
- **No mission-type enum.** The module reads `GameMissionFactory::getAllMissions()`; an enum in either
  direction would be the static list gate 1 forbids.
- **No combat implementation, in any language.** A public Rust/WASM restatement of OGameX's own formulas
  already exists; adopting or mirroring it would create a second authority that drifts.

## What the module does when an item lands

1. Re-run the module gate: Rector, Pint, PHPStan level 8, Pest, 100% PCOV.
2. Delete what the item makes dead — `AiBuildingMachineName` for R2, the recall ownership branch for R5,
   the duplicated chat validation for R6 — because gate 2 treats an item left dead as a defect.
3. Update [`host-capability-map.md`](../research/host-capability-map.md) and the corresponding disposition
   in [`GATE-AUDIT.md`](../GATE-AUDIT.md), and mark the item closed here with the merged revision.
