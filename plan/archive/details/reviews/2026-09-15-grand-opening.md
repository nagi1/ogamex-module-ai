# Grand opening review — 15 September 2026

## Window

Live read from `ogamex-grand` after the authentic-from-zero cohort was accelerated with the
explicit grand-test cadence controls. This is an evidence record, not a tuning decision.

## Evidence sources

- Host requirement graph and prices: `ObjectService::getRecursiveRequirements()` and
  `ObjectService::getObjectPrice()`.
- Host execution: `building_queues`, `ai_action_receipts`, `planets`, `ai_work_items`.
- Module traces: `ai_decision_traces` and the current `FacilityChain` / `QueueableBuildingPlanner`.
- Community/player research:
  - OGames, [The Optimal OGame Build Order for Your First Week](https://ogames.net/blog/ogame-build-order-first-week)
  - OGames, [Perfect Mine Ratios and Payback Times](https://ogames.net/blog/ogame-mine-ratios-payback-times)
  - OGame forum, [Tutorial 01: Basic economy](https://board.en.ogame.gameforge.com/index.php?thread/813416-tutorial-01-basic-economy/)
  - Module research: `plan/details/research/ogame-automation-algorithms.md` and `veteran-play.md`.

## What happened

The cohort has made real host actions, not only decisions. The live action distribution was:

| Host action reason | Accepted |
| --- | ---: |
| `storage:metal_store` | 100 |
| `storage:crystal_store` | 72 |
| `energy:solar_plant` | 63 |
| `economy:metal_mine` | 43 |
| `economy:crystal_mine` | 31 |
| `chain:deuterium_synthesizer` | 22 |
| `chain:research_lab` | 22 |
| `chain:robot_factory` | 5 |

Current live state at the read:

- all 10 accounts have solar plants and deuterium production;
- 9 accounts have research labs;
- 3 accounts have a robotics factory;
- no account has a shipyard yet;
- all recorded action receipts in the inspected distribution were accepted by the host;
- recent decisions include 459 build selections and 76 research selections.

## Finding A — fixed structural deadlock

**Observed:** all accounts initially had zero deuterium production. Facility prices from the host showed
that robotics factory, research lab and shipyard all require deuterium. The economy ranking never chose
the deuterium synthesizer, so the opening could never reach the facility chain.

**Evidence:** the live queue history had no deuterium synthesizer before the fix; all ten planets had
`deuterium_production = 0`. The host reports robotics factory price `400/120/200`, research lab
`200/400/200`, and shipyard `400/200/100` (metal/crystal/deuterium).

**Fix:** `FacilityChain` now derives producers of a resource with no income from the host production
catalogue and places that producer before an unaffordable chain step. This is Gate 3's “mine while
short”, Gate 1's host-derived object universe, and a small single-class mechanism.

**Verification:** after deployment, all 10 accounts gained deuterium synthesizers; 22 synthesizer
queues and 22 lab-chain queues were accepted. This finding is closed.

## Finding B — current ordering defect, not patched in this review

**Observed:** the host graph says `research_lab` has no prerequisite, `shipyard` requires
`robot_factory` level 2, and early ships require shipyard, robotics and research/drive technology.
Yet all ten accounts had `robot_factory = 0` while nine already had research labs. Several accounts
with an empty building queue and enough resources were planned toward `chain:impulse_drive` rather
than `chain:robot_factory`.

**Reproduction:** for player 12, `FacilityChain::pending()` begins with repeated `chain:shipyard`
candidates and places `chain:robot_factory` after lower-level research prerequisites. The executable
planner returned `chain:impulse_drive` even though player 12 had no building queue and had enough
resources for the host-defined robotics factory price.

**Likely root cause:** `FacilityChain` sorts prerequisite entries by the numeric level requested by
the host across unrelated ambition graphs. A level-1 research prerequisite therefore beats the
opening facility milestone represented by robotics factory level 2. That numeric comparison is not a
valid measure of human opening priority.

**External corroboration:** the current community opening sources agree on economy/deuterium, then
robotics factory, then research lab/research, with shipyard delayed until useful technology exists.
The sources differ on exact mine levels, so no fixed build-order table is adopted.

**Batch 1 status:** patched with host-derived unmet-dependency depth, facility-before-research priority,
and host price/level tie-breaking. The live pre-deployment check changed player 12's next executable
plan from `chain:impulse_drive` to `chain:robot_factory`. After the worker restart and real accelerated
dispatch, new accepted receipts included `chain:robot_factory` for players 12, 15, 18 and 20; player
12's host state moved to `robot_factory = 1`. No planet or queue row was gifted or hand-edited.

The cohort still has accounts with older research decisions already in flight, so this is a live
transition, not yet proof that every account converges. Continue observing the next facility wave
before making another ordering change.

## Other observed behavior

Storage, solar and mine actions are not random failures: they were accepted by the host and align with
storage overflow, energy capacity and marginal economy rules. Their high frequency is a separate policy
question from the facility ordering defect. It should be reviewed against account-day production,
storage fill horizon and the community opening, not “fixed” merely because the rows are repetitive.

## Recovery batch

The live audit found player 16 stopped after session work item 801 exhausted its three queue attempts at
08:13. The item was terminal, its expired building queue remained unprocessed, and its schedule had no
successor. The root cause was twofold: an exhausted session did not advance the schedule, and accelerated
dispatch admitted future sessions that the worker still rejected as not yet due.

The module now keeps the failed row for audit, schedules one idempotent delayed successor for failed
sessions at the terminal retry transition, and lets the worker claim future sessions only when the
explicit accelerated interval is enabled. Future non-session work remains due-gated. A second root cause
was then reproduced: concurrent work for one player lost the per-player lock and consumed the same three
attempts as a real exception. Lock contention now requeues after five seconds and resets the work-attempt
budget; only execution failures can poison a work item. The real host-backed work slice passes 26 tests
and 93 assertions.

## Deployment defect that masked both fixes

Neither fix took effect live, and the first audit read that as a new failure. The code was correct on
disk and every test passed, yet the cohort stalled again: the last session for each of the ten accounts
was terminal `Failed` at `attempts=3` with no successor, and failure timestamps kept advancing *after*
both patches. Horizon workers are long-running processes, and a class already loaded in the process is
never re-read — `opcache.validate_timestamps=On` does not help, because the definition is no longer
coming from the file. The worker was executing the pre-fix `ProcessAiWork` the whole time.

`php artisan horizon:terminate` on `ogamex-queue-worker` fixed it. Freshness was then verified
behaviourally, not by reading files: holding `Cache::lock("ai:player:12")` and dispatching that player's
pending session now lands the item in `Retry` with `attempts=0`, which the old code cannot produce. The
scheduler container was never affected — `schedule:run` spawns a fresh `artisan` process per command.

Evidence that the earlier "9/10 accounts were acting" reading was a sampling artifact, not recovery: the
per-account failure times were staggered from 09:18 to 09:58, each account stopping shortly after its own
terminal failure, while the scheduler log showed `ai:run-due-work` succeeding every 10 seconds throughout.

## Corrected live window (after the restart)

Measured over one 10-minute window with all ten accounts:

- 81 accepted actions and 84 decision traces, from **10/10** accounts; zero new terminal failures.
- Generation advanced sharply per account (e.g. player 12 `78 → 99`, player 21 `93 → 112`).
- Every account idle under 65 seconds, cycling continuously.
- Action mix, last 60 minutes: research 94, buildings 85, units 6, other 4 — all accepted, none rejected.
- Unit roles exercised: `role:cargo:light_fighter`, `role:cargo:small_cargo`, `role:probe`.

## Strategy observation — not yet a patch

Progress is real but strategically inverted. Across the cohort, mines sit at levels 2–6 and deuterium
synthesizers at 1–4, while shipyards reach 6–7, research labs 5–6 and stores 5–7. The dominant build
reasons in the window are `chain:shipyard` (57), `chain:missile_silo` (38) and `chain:research_lab` (34),
and research is dominated by combat lines: armour 26, espionage 24, laser 16, shielding 10, weapon 8.
Total fleet across all ten accounts is still only a handful of cargo and escort hulls.

Metal is also accumulating unspent (up to 865k on one account) while its mine stays at level 5. That is
consistent with the utility scorer's `resource_need: 30` constant term: it is the same value for a cheap
high-yield mine upgrade and an expensive deep shipyard level, so the prerequisite chain, not marginal
economic value, decides. A veteran opening mines first and only deepens shipyard/lab when a specific hull
or technology needs it.

This is recorded as an observation with one window of evidence. It is not patched here: the ordering
question needs the same treatment as batch 1 — host prerequisite facts plus external play research plus
repeated live evidence — before a policy change is justified.

## Review conclusion

The system is algorithmic, host-grounded and now genuinely running: it perceives, decides, queues legal
host actions and sustains that without operator help. It is still not adaptive intelligence. Current
traces select mostly `build` and `research` using fixed safety/resource/archetype terms, with
`target_confidence` zero on ordinary decisions and `recovery` zero everywhere; a single event-reactive
path (`fresh_visible_report` → `spy`, `target_confidence: 30`) is the only non-static scoring seen, and
seeded variation supplies the rest of the visible diversity. Sidecar memory and affect remain inert for
this cohort (0 memory facts, 0 affect states) and the 443 experience cases are 100% success with zero
failures, so learning cannot yet discriminate.

Two operational lessons are now in repository memory: restart the worker after deploying module code, and
verify code freshness behaviourally rather than by grepping files.

## Economic-balance batch (live, after the worker reload)

The imbalance had two distinct causes, both now fixed and verified against the running cohort rather than
against tests.

**1. The chain asked for every ambition at once.** `FacilityChain` took the union of the requirements of
*all* host ambitions, so there was always one more deep unlock to buy and the list never emptied: the
cohort reached shipyard 6-7 and research lab 5-6 over mines at 2-6, with metal unspent up to 865k. Measured
from the host: the cheapest ambition needs **2** prerequisites, the union of every ambition needs **18**.
The chain now serves the cheapest ambition the account cannot yet produce, and the spec that already
described this ("one ambition at a time", "cheapest ambition", "the chain empties once the host graph is
satisfied") is the rule it now follows.

**2. Technologies preempted mining.** With the chain bounded, the treadmill simply moved to research —
`laser_technology` 12, `energy_technology` 10, `combustion_drive` 5 in one window — because cheap
technologies are always the next-cheapest ambition and the chain outranked the economy outright. That is
the spec's unmet R1: "research only when it out-pays the last purchase, otherwise mine". A facility is a
hard gate and still comes first; a technology now ranks behind the economy's own payback horizon.

Live effect, measured on the running cohort: chain steps fell from **44 per window to 4**, `economy:` and
`storage:` reasons appeared for the first time in the run, and no new deep facility is being chased.

**3. Storage preempted mining for a fixed 48 hours.** This one is worth recording as the cause, because
the first reading of it was wrong. Storage was not misbehaving: measured per planet, warehouses were 0-10
hours from overflow (one account at 805k of an 865k cap), so a real player would absolutely buy storage
there. What was wrong is *why* the trigger fired. The class documents its own rule as "a warehouse exists
so that production does not stop while nobody is watching, and it is worth building exactly when it would
fill inside the time the account is likely to be away" — and then measured against the fixed
`STORAGE_FILL_HOURS = 48.0` constant instead. At 1000x a planet produces 200-525k metal/hour while
capacity is not speed-scaled, so fill time was under 48 hours for every planet at every level: the trigger
was permanently true, storage took every building slot, and the mines underneath never got one.

The horizon is now the account's own absence — `24 / sessionsPerDay` from its persona (a Miner visits 5
times a day, so about 4.8 hours) — so it is the same figure the routine already uses, no new setting, and
no storage object is named. It also makes the code match the sentence it already carried.

Live effect: `economy:` mine upgrades appeared for the first time in the run (8 of 18 in the first
window), and over the following 25 minutes average `metal_mine` rose 4.30 → 5.70 and `crystal_mine` 3.10 →
4.10 while storage growth slowed. The mixed window settled at mine 7, energy 6, research 3, storage 3 —
mining-led with capacity, energy and technology still taking their turns, which is the shape the gate
asks for.

## Decisions that went nowhere: the raid candidate (live, after the worker reload)

Watching one more window found the largest single waste in the run. `AiCapability` has no Raid case — raid
candidates are built straight from a visible report — so a candidate appeared on `attack_permitted` and
freshness alone, while `RaidPlanner` additionally applies the bashing limit, the fleet's own capacity and
the profit estimate (`samples === 0 || p20NetProfit <= 0`). The decision was therefore offered targets it
could never act on.

Measured before the fix: **181 raid selections and 27 raid intents in one hour — 154 decisions, about a
third of everything the population decided, producing no work item at all.** The control confirms it was
specific to raids: build and spy intents were created normally in the same window.

`CandidateActionFactory` now asks `RaidPlanner` before offering a target and records the drop as
`raid_not_viable` in the trace's own rejections, which keeps this the module's rule — only publish what the
account can carry out — while leaving the drop visible rather than silent.

Verified over a clean post-reload window (2 minutes, 8 sessions): **4 raid candidates dropped, 0 raids
picked, 6 builds picked**, no new worker errors. The half of decisions that were previously spent on an
impossible raid are now spent on something the account can do, and the trap the module's own decision log
names — a trace claiming an action nobody performs — is closed for raids.

Note for handoff: these fixes were verified live, per instruction, with no unit, quality or coverage run.
A Pest case belongs with each of them before the slice is handed off.

## Assessment: algorithmic, not random — and where it is weakest

Asked directly whether the planner computes or guesses, the code and the live traces give a divided answer,
and the division is worth recording.

**No unseeded randomness exists.** The only stochastic source is `RandomSource`, keyed by the persona's
seed, and `EconomyUpgrades::personaVariation` is `crc32(seed . ':economy:' . objectId)` — reproducible for
a given account. Nothing calls `rand`, `mt_rand` or a clock for a choice.

**Object-level choices are arithmetic over host data.** Which mine: payback = metal-equivalent price ÷
production gain, under an adaptive horizon. Which prerequisite: the host's recursive requirement graph,
ordered by unmet-dependency depth, for the cheapest ambition not yet producible. Which storage: fill time
against the account's own absence. Which raid: bashing limit, fleet capacity and p20 net profit from the
estimator. Every one of those is a computation, and each is why the last four defects were findable at all.

**Capability-level choice is a table, not a computation.** `resource_need` is `1.0` for any account holding
more than `RESOURCE_RESERVE` (1 000) resources — effectively always — and `safety` is `0.2` for every
published economy capability. Those terms are therefore *identical across the candidates and cancel out of
the comparison*. `target_confidence`, `travel_cost` and `recovery` are zero for economy actions. What
remains is `archetype_preference` times 25 plus at most a few points of seeded jitter: build versus research
versus units is a policy table, decided before any of the arithmetic above is consulted. That is where
"random-looking" behaviour would come from if it came from anywhere, and it is why the object-level planners
carry all the intelligence.

**On track, by the numbers.** Mines have recovered under the fixes: metal `4.30 → 8.20`, crystal `3.10 →
6.00`, deuterium `3.10`, against shipyard flat at `6.70` and lab `6.00`. That is a `2.6 : 1.9 : 1` ratio,
close to the `3 : 2 : 1` veterans describe, and the shipyard has stopped inflating. Energy is healthy on all
ten planets (no deficit). No new worker errors.

## Examined and deliberately not patched

- **Miner never builds ships** (`QueueUnits` is absent from its preference table, so it scores 0 against
  `Build`'s 25). Checked whether a weight would help: it would not. When `build` is available a preference
  below 1.0 still loses, and when `build` is unavailable `queue_units` already wins against the only
  remaining rival, `DoNothing`. The score gap is 25 points against a jitter of about 3. So the observable
  outcome is the same and the change could not be verified — it would be tuning, not a fix. Making a miner
  build logistics means letting a real need outrank `build`, which is a design decision, not a number.
- **The consequence is currently harmless.** No colonies, no PvP and raids correctly gated, so a fleet has
  nothing to do yet; `FleetSave` cannot fire without one and nothing is attacking. Colonisation is a later
  package. Revisit when expansion or PvP makes a fleet load-bearing, not before.
