# Premium officers — plan (AI)

Status: **OFF-001 implemented 10 October 2026** (module commits 9cf2801, 21b6b4e); the rest below is still plan.
Built: `QueueableOfficerPlanner` (reads `OfficerService::COSTS`/`DURATIONS`, doctrine in
`resources/behavior/officers.yaml`, reserve floor, 7 or 90 days, renewal inside 2 days), `QueueAiOfficerAction`
(the host's `OfficerService::purchase`), candidate kind `AiCandidateActionType::Officer = 21`, work kind `Officer = 21`.
The errand choice seam already carries it: the errand encoder one-hots every candidate action type, so the officer is
one more errand row (encoder VERSION 3, every earlier model and dataset is the old width). Staged late accounts now
hold 60,000 dark matter so the data shows purchases. No separate choice point was needed, so OFF-002 reduces to
retraining on v3 data (BC v5, full cognition, regenerating). Not built: OFF-003 taste beyond the five doctrine rows,
OFF-004 scorecard aspect. Test lanes now key their migration stamp on the host migrations (the `officers` table).
(owner-requested 10 October 2026). The host side shipped: the
premium officers system merged into the host `main` on 10 October 2026 (imported from upstream
lanedirt/OGameX#1385). This file is the module half; the host connection points it needs are in
[Host connection points](#host-connection-points) below and are mirrored into
[`host-change-request.md`](host-change-request.md) and
[`module-extension-points.md`](module-extension-points.md).

## Why the accounts must use them

The host now sells five officers plus the Commanding Staff bundle for Dark Matter, for 7 or 90 days.
They are not cosmetic — each moves a number the cohorts' scorecard already reads:

| Officer | What the host does with it | Scorecard aspect it moves |
| --- | --- | --- |
| Commander | **Is the building queue**: with her a planet holds 5 building orders, without her only the one being built (`BuildingQueueService::getMaxQueueItems`). | `aspect:economy`, `aspect:build` |
| Admiral | `getFleetSlotsMax` +2, `getExpeditionSlotsMax` +1, and it is the flag the tactical-retreat 3:1 gate reads (`PlayerService::hasAdmiral`). | `aspect:fleet_breadth`, `aspect:expeditions`, `aspect:fleet_save` |
| Engineer | Halves the defence losses the battle engine's repair rate leaves behind. | defence shape reads; `aspect:raids` survival |
| Geologist | Multiplies mine output (through the existing `hasGeologist()` hook in `GameObjectProduction`). | `aspect:economy` (growth) |
| Technocrat | −25 % research time, +2 espionage levels. | `aspect:research`, `aspect:espionage` |

Two consequences make this urgent rather than nice-to-have:

1. **The Commander is now load-bearing.** The same host change that made the queue her benefit means an
   account that never buys her can only ever queue one building per planet. The accounts' growth curve
   (`plan/details/specs/play-coverage.md`) already stalls; leaving the queue capped at one structure
   makes it worse.
2. **Not buying officers is itself unauthentic.** An experienced player buys the Commander early and the
   Geologist while mining; an account that sits on Dark Matter and never does reads as a bot.

## What a good professional player does (gate 3)

The behaviour is nameable and boring — the planner encodes taste, not machinery:

- **Commander, early and always**, the moment the account queues more than one building. This is the
  first officer an ordinary player buys.
- **Geologist while mining**, because it multiplies every mine already running.
- **Admiral once fleets exist** (slots, expeditions, and the retreat it enables).
- **Engineer before a stretch where defence matters**; **Technocrat before a long research push**.
- **Never spend the last of it.** Keep a Dark Matter reserve for a relocation, merchant or halving; the
  module already guards those spends (`QueueableRelocationPlanner`, `QueueableTradePlanner`).
- **Duration is a cadence:** 7 days to try or to bridge, 90 days when the account means to keep it
  (cheaper per day). A player who keeps the Commander renews her rather than letting her lapse.

## Design — behind the seams that already exist (gate 2)

One planner, one action, one choice kind, one data file. No new framework.

### 1. Perception (read, do not restate)

Add officers to the account state the planners already receive: the Dark Matter balance, the per-officer
remaining days, and the derived benefit flags. Read them through the host — never mirror the host's
catalogue:

- the catalogue and prices come from `OGame\Services\OfficerService` (`TYPE_MAP`, `COSTS`, `DURATIONS`),
  which are public host constants, so the module enumerates whatever the host sells with no list of its
  own (gate 1);
- affordability and the reserve use `OGame\Services\DarkMatterService::canAfford()`;
- the active/benefit flags use the host `PlayerService::hasCommander()/hasAdmiral()/hasEngineer()/
  hasGeologist()/hasTechnocrat()/hasCommandingStaff()`.

`OfficerService` is a host singleton (`AppServiceProvider`, verified), so a per-request officer read is
cached across call sites; the module resolves it once via `app()` and does not keep its own copy.

### 2. Decision — `QueueableOfficerPlanner`

A peer of `QueueableRelocationPlanner`, which already spends Dark Matter, so the pattern and its tests
exist. It produces candidates of a new kind, `AiCandidateActionType::Officer = 21`:

- one candidate per (officer × duration) the host sells whose cost the account can afford **above the
  reserve** and whose benefit is not already active (or is close to lapsing, i.e. a renewal);
- scored through the shared `CandidateActionFactory::features()` taste table (benefit ÷ weighted cost,
  reserve preserved), the same table every other candidate uses — no officer-specific scorer;
- the Commanding Staff bundle competes as one candidate (it is all five at a discount), so the sort key
  decides bundle vs five separate buys without a special case.

### 3. Action — `QueueAiOfficerAction`

Calls the host's ordinary purchase under the module's per-player lock:
`OfficerService::purchase($player->getUser(), $key, $days)`. The host validates type and duration, and
debits and saves atomically (the upstream review's transaction fix), so the module adds no re-check of a
host rule. The receipt records the officer key, duration and cost.

### 4. Choice seam — this is the "teach the AI" half

Add a **new choice kind** `ChoicePoint::OFFICER = 'officer'`, teacher-neutral like the others: row 0 is
*wait*, the teacher is the planner's pick, and a learned policy may choose any legal row. This lets the
trained model decide *whether and which* officer to buy and which duration, rather than the deterministic
planner alone. Rules that apply, from
[`.github/skills/ogame-rl-training/SKILL.md`](../../../.github/skills/ogame-rl-training/SKILL.md):

- the planner exposes the candidate list and its own pick; the action applies the choice through the host
  action above; the host re-validates;
- `EconomyChoiceEncoder::VERSION` (or the new officer encoder's version) **must be bumped** — any change
  to what the encoder emits is a new data version, and versions never mix;
- features are **host-derived numbers only** (cost, days, benefit flags, dark-matter balance, economy
  speed) — never an officer id or machine name as a feature (gate 1);
- the teacher path stays byte-identical: with `ai.rl.policy=teacher` and no recorder, `DecideAiOfficer…`
  returns the planner's pick exactly, guarded by a Feature test (the `EconomyChoiceSeamTest` pattern);
- data → model → closed loop → evaluation follows the RL workflow; a new choice point does **not** need a
  new model — the candidate scorer takes it as another choice point with the same
  `forward(state, cands, mask)` signature.

### 5. Behaviour data — `resources/behavior/officers.yaml`

The doctrine lives in data the planner loads by name, the way every other behaviour value does (gate 1
amended: doctrine data may name buildings/ships/research/officers; code names nothing):

- which officers to prioritise and the trigger for each (queue pressure for the Commander, mines for the
  Geologist, an owned fleet for the Admiral, a research push for the Technocrat, an inbound-threat season
  for the Engineer);
- the Dark Matter reserve floor (a value that varies with the account, so it is not a constant);
- the default duration and the renewal threshold (renew when fewer than N days remain).

An officer the file does not mention is simply not bought by taste — but the generic derived pilot (a
candidate per host officer, scored by benefit ÷ cost) keeps every host officer reachable with no module
edit, so a mod-added officer still competes.

## Host connection points

The module needs a small, non-speculative set. Each has a named consumer, per
[`module-extension-points.md`](module-extension-points.md) ("reuse first; avoid speculative extension
points").

| # | Ask | Size | Consumer | If it never lands |
| --- | --- | --- | --- | --- |
| O1 | *(shipped)* `OfficerService::purchase()/isActive()/getOfficer()` with public `TYPE_MAP`/`COSTS`/`DURATIONS`; `DarkMatterService::canAfford()`; the `has*()` benefit flags | — | The planner's candidate list and the action | — |
| O2 | A read-only expiry accessor: `OfficerService::expiresAt(User, string $key): ?Carbon` (or on `PlayerService`) | trivial | "already active" vs "renew soon" in perception; the renewal candidate | Read `has*()` only, and let a renewal be decided by the planner's own recorded purchase time — more work, worse |
| O3 | The Commander's queue cap stays host-side: the module reads `BuildingQueueListViewModel::isQueueFull()` and never restates the cap | — | `QueueableBuildingPlanner` refusal | — |
| O4 | *(generic, only if a UI need ships)* a curated premium-information slot usable by a module | small | A future owner-console / disclosure need | No module UI change |

The general "connection points for the modules" work is the E-table in
[`module-extension-points.md`](module-extension-points.md); the recommendation there stands — **do not
add a point without a consumer**. The officers work adds exactly one (O2) and confirms one reuse (O3).
Update both spec files when a point lands.

## Proof (north-star steps)

Every row below names an `aspect:` or `situation:` step, so the harness, the ledger and the writer accept
it and `task.py done` can run the proof.

- `OFF-001` (perception + planner + action, no learned policy yet)
  - `aspect:economy` — with the planner active, an account holding Dark Matter and a planet whose build
    queue refuses a second item buys the Commander and then holds a second order.
  - `situation:premium-officer` (new kit) — seed an account with Dark Matter, no Commander, and a planet
    that has one building in progress; run a session; read back that the Commander is active and the
    queue depth is 2. Diagnosis when it fails: the reserve floor or the trigger never fired.
- `OFF-002` (choice seam + behaviour data)
  - `aspect:economy` moves further once the policy is served, and
    `tests/Feature/OfficerChoiceSeamTest.php` proves the teacher path is byte-identical with no recorder.
- `OFF-003` (Geo/Admiral/Engineer/Technocrat taste)
  - `invariant:` an account with an active mine and ≥ reserve Dark Matter never reaches a full day
    without the Geologist; an account owning a fleet never reaches a full day without the Admiral.
- `OFF-004` (scorecard)
  - add `aspect:officers` to `scripts/play-scorecard.php` (does the account hold each officer the map
    says it should?), so the evidence run reports it.

## Rows to add (owner unfreezes; these are new capability, currently P3/frozen)

Add once the owner lifts the freeze for officer work (the row proves an aspect, so it is on the
north-star path):

```
python3 plan/tasks/task.py add OFF-001 "Officer planner, action and Commander queue benefit" impl P2 \
  --file app/Domain/Decision/QueueableOfficerPlanner.php --file app/Actions/QueueAiOfficerAction.php \
  --notes "aspect:economy; situation:premium-officer. Reads OfficerService catalogue; reserve floor."
python3 plan/tasks/task.py add OFF-002 "Officer choice point behind app/Domain/Choice" impl P2 \
  --depends OFF-001 --file app/Domain/Choice/OfficerChoiceEncoder.php \
  --notes "aspect:economy; teacher path byte-identical; bump encoder VERSION; retrain per RL skill."
python3 plan/tasks/task.py add OFF-003 "Officer behaviour data and the four non-queue officers" impl P2 \
  --depends OFF-001 --file resources/behavior/officers.yaml \
  --notes "invariant: account with mines/fleet never a full day without Geo/Admiral."
python3 plan/tasks/task.py add OFF-004 "Scorecard officers aspect" impl P2 \
  --depends OFF-001 --file scripts/play-scorecard.php --notes "aspect:officers."
```

Do not add them while the officer freeze holds; this section is the handoff, not the claim.

## Out of scope

- No host change to the officer prices, durations or benefits — the host is the authority.
- No new model or training run until `OFF-001` passes on the cohort; the choice point rides the existing
  candidate scorer.
- No officer UI in the owner console unless O4 ships with a real consumer.
