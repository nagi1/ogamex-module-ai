# Reopened repo value — the mined ideas still not implemented (17 September 2026)

The per-repo residue audit ([`research/repos/WORK-PACKAGE.md`](../research/repos/WORK-PACKAGE.md#per-repo-residue-audit--do-the-23-per-repo-plans-still-carry-work-17-september-2026))
closed the `RP-*` family as provenance and left its P3 seam audit as the record of what the mined
corpus offers and we do not ship. This doc re-checks every one of those verdicts against the running
code, corrects the four that no longer hold, and defines one claimable slice per remaining idea so a
worker can pick any of them up from `plan/tasks/tasks.db`.

Rows minted here use the `RV-` prefix. Module paths are written `Modules/AI/…`, host paths `app/…`,
because both appear in `file_ref` and a bare `app/` is ambiguous.

## Verification, not transcription

Each claim below was read out of the source rather than taken from the audit text. Four moved:

| Audit verdict | Verified in code | Result |
| --- | --- | --- |
| purge/inactivity exemption is host code the module cannot reach | `SessionDecisionService::livenessFloor()` already caps the next session at `SettingsService::inactivePlayerDeletionDays() - 1 day`, and the host's own `DeleteInactivePlayers` command is the thing it is staying ahead of | **already shipped** — no slice |
| archetype→character-class affinity is host provisioning work, not a module change | `SeedAiTestUniverseAction::characterClass()` already maps Miner/Casual→Collector, Turtle/Fleeter→General, Trader→Discoverer; the host wires the whole path (`CharacterClassController`, `routes/web.php`) and the policy already consumes the held class | **already shipped** — no slice |
| pre-flight round-trip duration: seam confirmed, not built | `QueueableFleetSavePlanner::plan()` already quotes the host duration and refuses a destination the save would outlast | **already shipped** — no slice |
| per-resource production percentages: the host exposes only a read-only energy factor | `PlanetService::setBuildingPercent()/getBuildingPercent()` exist, and a player path already calls them (`ResourcesController`); the module uses neither | **wrong** — the lever is reachable today (`RV-004`) |

Two more facts the audit left open, now settled: `PhalanxService` and `JumpGateService` exist in the
host and the module calls neither, but no cohort account owns a moon (`planets.planet_type = 3`
counts one moon in the whole grand universe, owned outside the cohort) — so the two moon slices are
real and gated on a deployment fact, not on code. And `EnergyCapacity::pending()` filters its
candidates through `BuildingQueueObject::accepts()`, so unit-based energy producers are structurally
unreachable (`RV-001`).

## Claimable slices

### RV-001 — A solar satellite answers an energy shortfall the building queue cannot

**Why.** `EnergyCapacity` asks the host for every production object and then keeps only the ones the
*building* queue accepts, so when the account cannot pay for the next plant or reactor the plan falls
through to something else while the planet mines at a fraction of what its mines say. A player in
that position builds solar satellites: they are cheap, they are produced by the unit queue, and they
are lost with the planet, which is the trade-off he accepts.

**Seam.** `ObjectService::getGameObjectsWithProduction()` (already used) filtered by the unit queue's
own accepts-check instead of the building queue's; `QueueableUnitPlanner` already sends units.

**Files.** `Modules/AI/app/Domain/Decision/EnergyCapacity.php`,
`Modules/AI/app/Domain/Decision/QueueableBuildingPlanner.php`,
`Modules/AI/app/Domain/Decision/QueueableUnitPlanner.php`.

**Smallest mechanism.** After the building candidates come up empty, run the same
cheapest-first scan over the production objects the *unit* queue accepts, and publish the result as a
unit intent. No new class, no new list of names — the same host read, a different accepts-check.

**Accept.** On a frozen clock, a planet in deficit whose affordable capacity buildings are exhausted
offers a solar-satellite unit intent, and one that can still afford a plant still offers the plant
first. A mod-added energy unit is offered with no module edit (gate 1).

**Gates.** Named play: "build satellites when you cannot afford the next plant". Gate 2: one
accepts-check and one extra source in an existing loop.

**Shipped 17 September 2026 (`RV-001`).** `EnergyCapacity::outdrawn()` became `shortfall()` (the
magnitude, not a boolean), `QueueableBuildingPlanner::queueablePlanetId()` became public `canQueue()`
so the yard asks the building queue's own gate instead of restating it, and the unit planner gained an
account-wide power pass before its habits, guarded by "no hostile inbound". Live: 0 power orders ever,
then 8 in the first 20 s after the worker reload, all `queued`, with the planner returning the role for
11 of 20 accounts. Recorded as [Wave 12](../GAP-REGISTER.md) and in `DECISIONS.md`.

### RV-002 — U6: launch the counter subset, not the whole stock (W6-4 remainder)

**Why.** `W6-4` shipped only the payload-sized cargo half. Production and launch are two decisions:
the stock is what the account owns, the launch is the minimum hulls that win against *this* target.
Today a raid that qualifies still launches from the whole stock, which is the error the split exists
to prevent — and it is also the prerequisite for `RV-007`.

**Seam.** The host's own rapid-fire graph and unit properties (`ObjectService`, `GameObjects`) and
the battle engine `NativeRaidEstimator` already samples. Counter pairs are host data, never a module
map.

**Files.** `Modules/AI/app/Domain/Decision/QueueableUnitPlanner.php`,
`Modules/AI/app/Domain/Decision/RaidPlanner.php`,
`Modules/AI/app/Infrastructure/Battle/NativeRaidEstimator.php`,
`Modules/AI/plan/details/specs/gameplay-algorithms.md` (blocks `U5`, `U6`).

**Smallest mechanism.** One extra decision between the unit planner and the raid dispatch: derive
the launch subset from the target's reported hull mix and defence using the host's rapid-fire
properties, then simulate that subset once before dispatch. Fodder rides along only when the target
can threaten the heavies.

**Accept.** A light-fighter swarm draws cruisers as the launch subset; the same stock, shown an
undefended farm, sends kill ships plus cargo instead of the whole fleet. A mod-added rapid-fire pair
changes the selection with no module edit.

**Gates.** Named play: "send the counter, not the garage". Gate 1: the counter graph is read from the
host.

### RV-003 — Measure the defended-raid loot tier against the host's own debris

**Why.** `RaidPlanner::LOOT_TIER_DEFENDED = 2.0` is a constant whose comment says the debris
subsidises a defended run, while `RAID-014` decided debris stays *out* of the single-raid profit
gate. The two can both be right, but the constant has never been checked against what a defended
target actually returns in debris plus what its defence costs to repair. This row is the measurement;
`RV-006` is the change it justifies.

**Artifacts.** The module already writes what the read needs: `ai_decision_traces` score components,
`ai_action_receipts`, and the estimator's own P20 output.

**Files.** `Modules/AI/app/Domain/Decision/RaidPlanner.php`,
`Modules/AI/plan/details/reviews/` (new record).

**Method.** One bounded read over existing traces and receipts naming defended-vs-farm targets: does
the fixed tier let a defended run through that the host's debris and defence-repair values do not
pay for, and does it refuse one they do? Record the before figure; name the ceiling.

**Accept.** A dated review record with the before figure and a verdict: keep the constant, or change
it and point at `RV-006`. No code change in this row.

**Gates.** Gate 2: measurement before the change. Gate 3: the review has to be nameable as a player
decision ("do I hit the turtle for the debris?").

### RV-004 — Mine production percentages as a real lever

**Why.** The host lets a planet run each mine at a percentage of its output (`planets.*_percent`,
`PlanetService::setBuildingPercent()`, exposed to players by `ResourcesController`). The module never
touches it, so it plays a lever no human ignores: a player throttles a mine when energy is short and
turns it back up once the plant lands, instead of leaving a planet stalled or mining into a deficit.

**Seam.** `PlanetService::setBuildingPercent()/getBuildingPercent()` — the host's own validated
setter, the same path a player uses.

**Files.** `Modules/AI/app/Domain/Decision/EnergyCapacity.php`,
`Modules/AI/app/Domain/Decision/QueueableBuildingPlanner.php`, a new
`Modules/AI/app/Actions/SetAiMinePercentAction.php` alongside the other `QueueAi*` actions, and the
budget/cap surface the action is admitted through.

**Smallest mechanism.** One action, one rule: when the planet is in deficit, lower the mine with the
worst output-per-energy to the highest percentage the remaining energy covers; when a new capacity
building lands, restore it. Percentages come from the host's own reported production, never a module
table.

**Accept.** A frozen-clock case: a planet in deficit throttles exactly one mine, the host's energy
balance recovers, and a later session restores the percentage. The receipt records the before/after
percentage.

**Gates.** Named play: "turn the mine down when the power is short". Gate 1: the object is chosen
from host output, never a machine name.

### RV-005 — Confirm a moon exists before the moon slices (precondition gate)

**Why.** `RV-008` and `RV-009` cannot start without an owned moon, and none exists in the cohort
today: the whole grand universe holds one moon and it is owned outside the cohort.

**Method.** Read `planets` for `planet_type = 3` among admitted accounts and record which account (if
any) owns one, plus which of the moon facilities it has. If no admitted account has one, record that
and leave the two rows blocked; a moon arrives through ordinary play (the host's own
`calculateMoonChance` on a fleet crash), not through a slice.

**Files.** `Modules/AI/plan/details/specs/reopened-repo-value.md` (this doc),
`Modules/AI/plan/details/reviews/` (the record).

**Accept.** A dated line naming the owning account and its moon facilities, or stating that none
exists yet. `RV-008`/`RV-009` unblock the day it does.

**Finding — 17 September 2026.** No admitted account owns a moon: the whole universe holds one
(`planets.id = 29`, `planet_type = 3`, user 1 "Legor"), outside the cohort (admitted accounts are
players 12–31). `RV-008`/`RV-009` stay blocked; a moon must arrive through ordinary play.

### RV-006 — Ground the defended loot tier in the host's own numbers *(depends: RV-003)*

**Why and change.** Replace the fixed `LOOT_TIER_DEFENDED = 2.0` with the tier `RV-003` measured —
derived from the host's own debris and defence-repair values rather than a constant — so a defended
raid is priced by what the game actually returns.

**Files.** `Modules/AI/app/Domain/Decision/RaidPlanner.php`.

**Smallest mechanism.** Whatever `RV-003` shows: keep the constant (then this row closes as
not-a-defect), or compute the tier from the host read and delete the constant. No config knob.

**Accept.** The frozen-clock raid tests still pass, and a new test fails if a defended run is
admitted at a tier the host's own debris/repair numbers do not cover.

### RV-007 — CRN screen→confirm ladder over launch subsets *(depends: RV-002)*

**Why.** With one candidate the estimator's shared `seed + i` stream is enough. The moment `RV-002`
compares counter-selected subsets of the same stock, candidates can only be compared through a common
random number stream, and the cheap screen should run over all of them with the wide confirmation
spent on the winner.

**Files.** `Modules/AI/app/Infrastructure/Battle/NativeRaidEstimator.php`,
`Modules/AI/app/Domain/Decision/RaidPlanner.php`.

**Smallest mechanism.** One seed stream per candidate and a two-stage sample count. Dead machinery
before `RV-002`: with fewer than two candidates the ladder has nothing to choose between, which is
why this row is gated rather than ready.

**Accept.** Two launch subsets scored through the same stream rank identically to a single-stream
run, and the wide pass runs only on the winner (assert on the sample counter).

### RV-008 — Sensor phalanx: build it on the moon, then scan before the raid *(depends: RV-005)*

**Why.** `PhalanxService` ships in the host and the module never calls it. A fleeter with a moon
scans a target before committing — that is ordinary play and it is the only way to see a fleet that
is inside the planet, which the espionage report cannot show. Prerequisite inside the row: the moon
facility (the sensor phalanx) must be built first, through the existing building path.

**Seam.** `PhalanxService` (range, cost, scan). Host `canScanTarget` is the only legality gate and it
must be respected exactly: a scan outside range is a ban risk, not a profit.

**Files.** a new `Modules/AI/app/Domain/Decision/QueueablePhalanxPlanner.php`,
`Modules/AI/app/Domain/Decision/FacilityChain.php` (the phalanx as a chain step),
`Modules/AI/app/Actions/` (the scan intent alongside the other `QueueAi*` actions), receipts/traces.

**Smallest mechanism.** One candidate source: when the account owns a moon with a phalanx and a raid
target is otherwise viable, scan the target's origin once and fold the result into the existing
observation path — no second observation service, no new scheduler.

**Accept.** A frozen-clock case: without a phalanx no scan candidate is published; with one, the
target's stationed fleet appears in the raid decision's input and changes it. A scan refused by the
host records the refusal and writes no game state.

### RV-009 — Jump gate: move the fleetsave between own moons *(depends: RV-005)*

**Why.** `JumpGateService` ships and is unused. With two owned moons a player moves the parked fleet
between them instead of flying it, which is a save that costs no flight time — and the module's
fleetsave already ranks own bodies.

**Seam.** `JumpGateService` (`getEligibleTargets`, cooldown).

**Files.** `Modules/AI/app/Domain/Decision/QueueableFleetSavePlanner.php` (one more destination
kind), `Modules/AI/app/Actions/QueueAiFleetSaveAction.php`, `FacilityChain.php` (the gate as a chain
step).

**Smallest mechanism.** Add the jump-gate move as a destination option the existing ranking can pick
when it is cheaper in exposure than a flight, and only when the cooldown and both facilities allow.
Low value with one moon — the gate is a destination pair.

**Accept.** With one moon nothing changes; with two, a frozen-clock case picks the jump over the
flight and a cooldown-blocked one falls back to the flight.

## Deferred, with the trigger that opens them

| Row | Idea | Trigger |
| --- | --- | --- |
| RV-010 | Metal-dump research while saving (trilogi77) | A measured metal-capped state that `EconomyUpgrades::spendSurplus()` and `FacilityChain` both leave unresolved. Today the full-warehouse spend (`W8-L4`) and the facility chain already cover it, so this is real only if a read shows metal being discarded with a queue idle |
| RV-011 | Game-phase classification (shinigallo) | A named consumer wants it. Derivable from host reads (own rank, object levels, host highscore) with no host change, but with no consumer it is config for a value nothing varies on |
| RV-012 | Consultation confidence gate + horizon (shinigallo) | The 6A/7A consultation lane enabled **and** a confidence source in the brief. The brief carries no confidence signal today, and the lane is off by default |
| RV-013 | Moonshot (trilogi77) | A host self-battle seam. `AttackMission::checkOwnPlanet` refuses self-attacks by design, and sending a fleet to die on a neighbour to farm debris is machine-shaped against gate 3, so this stays cut unless the owner wants the seam |

## Dropped

- **Per-ship expedition points** (ogame-infinity): the host exposes no such property, and inventing
  one is the gate-1 hardcode the corpus is full of. `WP-009` already composes the expedition from
  host-read attack/speed/capacity properties.
- **Top-1 expedition bracket** (ogame-infinity): the host reads rank 1 itself inside
  `ExpeditionMission`, so the data is reachable — but no module decision varies on it, and the plan
  does not add a mechanism with no consumer.

## No host extension point is required

The tier-3 premise this doc was written to serve was that purge, production percentages, expedition
ranking and class provisioning are host-owned and need new read-only seams. Reading the code says
otherwise, so **no host contract, hook or read-only extension point is planned here**:

- the inactivity policy is already read (`SettingsService::inactivePlayerDeletionDays()`) and the
  clamp shipped;
- the production-percentage levers are already settable by a player path the module can follow
  (`RV-004`);
- the ranking data is already a plain host read;
- the character class is already provisioned, already wired (`/characterclass/select`) and already
  consumed by the policy.

The single host-side change the corpus could still justify is a **registration-time hook** so a
module-owned provisioner could pick the class at account creation, which is exactly what
`SeedAiTestUniverseAction` does for the test universe. It is not minted as a row because the module
never creates users by design (`L2`/`I8`); it becomes a row the day provisioning moves into the
module.

## Handoff

- Add rows with `python3 plan/tasks/task.py add …` (the CLI does not set `doc_refs`; set it to this
  file in the same statement, as `WP-*` rows do), then re-dump: `python3 plan/tasks/dump_seed.py`.
- Anything touching `app/…` ships as a **paired host + module commit**, per the standing rule.
- Every row here is a normal slice: claim one, follow this doc, run the module test runner and the
  Gate 2 review, and leave the before/after figure in the handoff.
