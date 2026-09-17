# Gap register — the goal against the plan

Produced 14 September 2026. A capability gap was found by accident: the buildable set could never
reach a shipyard or a research lab, so six of the seven remaining capabilities were permanently
unreachable. **A gap found by accident implies a class**, so this register audits the *goal* against
the plan rather than auditing the plan against itself.

## Method

1. Take the observables the goal is judged by — the eleven signals in
   [account authenticity](research/account-authenticity.md), highest-observability first.
2. For each, ask which **gameplay** produces it.
3. Check whether the module can produce that gameplay.
4. Record the gap with its evidence and how the evidence was obtained: **measured** (seen in the live
   database or a run), **code-read** (absent from the code as written), **inferred** (follows from
   another gap and is not yet separately confirmed).

An absence is only a gap if it is **observable**. Where a missing mechanism is invisible to a player
it is recorded as deliberate rather than as a defect.
### The four audit axes

The first pass covered one axis and treated it as the whole. Authenticity has four surfaces, and an
audit of one is not an audit:

| Axis | Question | Status |
| --- | --- | --- |
| **Behaviour over time** | Does the account *do* what the eleven signals require? | Audited — wave 1 |
| **Identity at rest** | What does an account look like the first time anyone sees it? | Audited — wave 2 |
| **Aggregate statistics** | What does the *population* look like, where uniformity is itself the signal? | Partly — wave 2 covers starting state; the rest is listed below |
| **Operational failure** | What happens when it breaks, and what does breaking look like? | Not audited |
## Gaps

| # | Gap | Signal it breaks | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- | --- |
| G1 | **No enabler buildings.** The buildable set is four requirement-free buildings, so a research lab or shipyard can never be built. | 1, 3, 5, 6, 9, 10 | Player 29129 owns no buildings and no research; `robot_factory` (14) and `research_lab` (31) have no host requirements and `shipyard` (21) needs `robot_factory` 2 | measured + code-read | **Closed 14 September 2026, slice 3N.** Nothing names a facility. `FacilityChain` takes the ambitions from the host catalogue (`getResearchObjects()`, `getUnitObjects()`), their requirements from the host's own graph (`getRecursiveRequirements()`) and their kinds from the host's object types, and orders the steps by the level the host asks for. `QueueableBuildingPlanner` walks those steps before the persona's ranking and returns the first target the host accepts, so a refused favourite falls through instead of costing the account its whole build capability. Measured: a seeded account reaches `research_lab` 1, `robot_factory` 2 and `shipyard` 1, and then returns to its own taste. The first attempt was rejected by gate 1 — it declared the three facilities in an enum, so a mod-added facility would have been invisible to it — and the enum was deleted rather than kept |
| G2 | **No research.** No executor; the research capability is unpublished. | 3 | No research queue path in the module | code-read | **Closed 14 September 2026.** `QueueableBuildingPlanner` returns a `QueueableResearch` when the next chain or economy step is a technology; observation publishes only that capability; `ScheduleAiIntentAction` and `ProcessAiWork` queue it through `QueueAiResearchAction` into the host's `ResearchQueueService::add`. Covered by `BuildingChainReachabilityTest` and `AiCapabilityPublicationTest` |
| G3 | **No units.** No executor, so no ships and no defence, ever. | 1, 3, 6, 9 | No unit queue path in the module | code-read | **Closed for cargo, colony ship and probe 14 September 2026 (U1/U2).** `QueueableUnitPlanner` queues the best cargo-per-cost ship first, then a colony ship once a fleet exists and the account has room, then a probe; `QueueAiUnitsAction` writes through `UnitQueueService::add`, and observation publishes `queue_units` only when that plan is non-null. Defence (U3) and the combat escort (U1) are now wired into `QueueableUnitPlanner` from the same intel sources (15 September 2026) |
| G4 | **No fleets, so no fleetsave.** Nothing can be saved because nothing exists to save. | 1 | No dispatch path in the module | code-read | **Closed for the reaction save 14 September 2026 (V1).** `QueueableFleetSavePlanner` finds a planet with a fleet and another own planet to move it to; `QueueAiFleetSaveAction` dispatches a deployment at the slowest speed through the host's own mission start. Observation offers the save only when a hostile is inbound *and* the account has a fleet and a second body, so the trace never claims a save the account cannot make |
| G5 | **No probes.** Nothing scouts, and no target intel is published. | 5, 10 | No spy executor; `ownedState()` never publishes target reports | code-read | **Closed 14 September 2026.** `QueueableSpyPlanner` picks a legal foreign target (not own, destroyed, vacationing or admin), `QueueAiSpyAction` dispatches one probe through the host's espionage mission, observation publishes `spy` only when a probe exists and a target is legal, and the report the host writes comes back as `target_reports` through the account's own message rows |
| G6 | **No raids.** `Raid` candidates are built only from published target reports, which ordinary play never publishes, so a raid cannot even be chosen. | 4, 5, 6, 10 | `CandidateActionFactory` builds raid candidates from `targetReports`; `ownedState()` publishes none | code-read | **Closed 14 September 2026 (T1/T2/T3 + dispatch).** Observation publishes `target_reports` from the account's own espionage-report messages; `RaidPlanner` applies the bashing limit and the profit test; `NativeRaidEstimator` samples `simulateBattle(seed, true)` (R1) for a P20 net profit; `QueueAiRaidAction` dispatches the attack. A cargo-only account fails the profit test and does not raid until combat ships exist (U1 escort role) |
| G7 | **No colonies.** The account stays on its starting planets forever. | 3, 4 | No colonise path in the module | code-read | **Closed 14 September 2026.** `QueueableColonyPlanner` finds the first empty slot the account's astrophysics allows, `QueueAiColonyAction` launches it through the host's colonisation mission, and observation publishes `colonize` only when a colony ship exists and a slot is free |
| G8 | **The account cannot notice being probed or attacked.** The only observations are chat, alliance membership, battle reports and building completion; nothing observes incoming fleets. | **1 — the highest-ranked signal, and the only one a player can *test*** | `app/Observers/` holds those four and no fleet observer; only Install/Uninstall hooks exist | code-read | **Observation half closed 14 September 2026.** `PlayerObservationService` assembles foreign inbound fleets from `FleetMissionService::getActiveFleetMissionsForCurrentPlayer()` and sets `fleetsave_eligible` from `currentPlayerUnderAttack()` — the same sources the fleet movement page uses. `IncomingFleetIntelService` stays a redactor, not the source. The fleetsave executor (V1) and the save-that-fails (V3) are now shipped; the reaction wake (V2) is **shipped 16 September 2026 (DEF-001)** — the reaction lands 120–180 s before impact, never faster than the 10 s floor |
| G9 | **No save ever fails.** A 100% save rate over months is itself the outlier, and with no fleet there is nothing to fail. | 1, 6 | Follows from G4 and G8 | inferred | **Closed 15 September 2026.** A deliberate, named skip at a blessed placeholder rate of 1-in-30 (inside the plan's 1-per-20-to-50 band), realised by `SaveFailurePolicy` as the "overnight gamble"; the rate is replaced by telemetry from the capacity runs |
| G10 | **No sleep window and no diurnal shape.** The account is active at every hour of every day. | **2** | `AiProfileSettings` exposes only `timezone`, `session_minutes`, `session_gap_minutes`; `SessionPlanner::plan()` takes no active-hours input | code-read | **Closed 14 September 2026, slices H1 and H2.** `SessionPlanner` gives each account one waking window per local day, anchored to a per-account core wake time and a nine-hour core dark period that the day's drift can only extend, so eight whole wall-clock hours are silent every day and no seven-day window can reach the host's eighteen. Waits and session lengths are Weibull draws at shape 0.8 (heavy-tailed, never a fixed period), and the persona's visits per day set the wait's scale. Measured over 21 simulated days per archetype (`RoutineCadenceTest`): **16 distinct hours in the widest seven-day window against the host's 18**, every local day silent for more than six hours, visits per day 2.76 casual to 12.05 fleeter, session length median 5–8 min with a 40–118 min tail |
| G11 | **Never absent.** Nothing models a day off or a multi-day gap, and the plan asks for "genuine week-to-week irregularity". | 2 | No absence modelling anywhere in the routine domain | code-read | **Closed 14 September 2026, slice H3.** An absence is decided where the account decides whether to come back at all: once per waking day, as the night ends, one draw picks between coming back, a single day away, a three-to-seven-day gap, or a week or more, at the per-day rates the plan's monthly, quarterly and yearly bands imply. Measured over five years of one account (`RoutineCadenceTest`): 1.8 single days a month, five or more multi-day gaps, at least one week-long gap, no two-day gap, and a longest absence of ten days against the four weeks that writes an account off |
| G12 | **The account never speaks first.** Every social action is a reply; nothing initiates contact. | 5 | Every social action found is classification or reply machinery: `ClassifyInboundSocialExchangeAction`, `BuildAuthoredSocialReplyAction`, `DeliverAiDirectReplyAction` | code-read | **Deferred to Package 6 by scope decision 15 September 2026** — social initiation needs a trigger and a recipient that are themselves deferred (G18, I8); reaction (answering) stays shipped |
| G13 | **The five personas are observably identical.** With one executable action they all queue a mine or a plant and differ only in their traces. | persona design, 4 | Follows from G1–G7 | inferred | **Closed 15 September 2026** — the shipped executor set plus seeded persona bands diverge the same host data; measured divergence is a capacity-run question |
| G14 | **Self-similarity is trivially maximal.** One action type on a jittered schedule is the most self-similar sequence possible. | 4 | Follows from G13 | inferred | **Closed with G13.** |
| G15 | **Zero military score forever.** The public highscore carries military points. | 3 | Follows from G3 | inferred | **Closed with G3** — ships and, when attacked, defence now feed military_built |
| G16 | **Resources saturate and the curve flatlines.** With nothing to spend on, the economy caps out, so "growth explicable by visible behaviour" becomes "growth stops". | 3 | Follows from G1–G7 | inferred | **Closed with G1–G7** — the capability chain keeps the account spending |
| G17 | **No transfers and no trade.** Nothing dispatches a transport or touches the market. | 9 | No transport or trade path in the module | code-read | **Closed by decision 15 September 2026.** Trade has no host surface to execute (no marketplace — verified); transfers are X1/E4, trigger decided, executor next slice |
| G18 | **No alliance life of its own.** Membership changes are observed but the account never joins, leaves or acts on them. | 5 | The module observes `ObserveCommittedAllianceMembership`; nothing initiates | code-read, intent unconfirmed | **Decided 15 September 2026: not in scope for Package 4.** Alliance life is deferred to Package 6 with G12/S1–S4 |

## Wave 2 — identity at rest and the shape of a cohort

Wave 1 asked what the account does. Nobody asked what it *looks like*, and a seeded account carries
markers that no behavioural improvement can hide.

| # | Gap | Who can see it | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- | --- |
| I1 | **The email address announces the account.** `ai-pilot-N@ai-pilot.invalid`, on a reserved TLD that can never receive mail. | An operator immediately; any player who ever sees the address | `SeedAiTestUniverseAction::EMAIL_DOMAIN` and `email()`; read back from the live row for player 29129 | measured | **Closed 14 September 2026.** Addresses use rotating deliverable-looking domains and a hashed local-part; `.invalid` is gone |
| I2 | **Every account starts with exactly the same dark matter** (8000). | Aggregate views and admin statistics | one distinct `dark_matter` value across the whole cohort | measured | **Closed 14 September 2026.** Each new seed gets a dark-matter balance in a ±1200 band around the host default |
| I3 | **The whole cohort is created within seconds.** | Join dates, highscore entry order, "newest players" | ten distinct `created_at` values inside a single minute | measured | **Closed 14 September 2026.** Join dates are staggered across up to 21 days from the seed |
| I4 | **Seeds are sequential** — `SEED_BASE + index`. | Predictable, correlated variation between accounts | seeder source | code-read | **Closed 14 September 2026.** `random_seed` is a crc32 of the index, not `SEED_BASE + index` |
| I5 | **Nothing is ever renamed.** Every planet stays at the host default `Homeworld` and no player name ever changes. The default is shared with humans, so the tell is the *never* renaming, and it is visible in the galaxy view at a glance. | Any player browsing the galaxy | ten planets named `Homeworld`; `username_updated_at` null on every row | measured | **Closed for provisioning 14 September 2026.** Homeworld is renamed from a small pool and `username_updated_at` is stamped; occasional later renaming remains open |
| I6 | **`NAME_PREFIX = 'AIPilot'` is dead code** — declared, never referenced. | Nothing today; a latent player-visible marker the moment anyone wires it | grep finds the declaration only | measured | **Closed 14 September 2026.** The unused prefix constant was deleted |
| I7 | **No address diversity.** `last_ip` is the loopback address on every account and `register_ip` is null. | Operator and abuse tooling | live rows | measured | **Closed 15 September 2026.** The account presents the queue-context address the host stamps and nothing else; no fabricated IP (AG4) |
| I8 | **No social paperwork, ever**: no character class, no alliance, no notes, no buddy contacts. | Player-visible social surfaces | live rows; nothing in the module forms them | measured | **Closed for provisioning 15 September 2026.** A persona-matched character class is assigned with its free selection used; notes stay private, buddies/alliance deferred with S3/G18 |

## Root causes

The gaps are not eighteen separate mistakes. They come from four planning failures, and fixing the
failures is what stops the nineteenth:

1. **No artifact connected the observables to the gameplay that produces them.** The authenticity
   research said the growth curve matters; the work packages said which mechanisms to build; nothing
   said "therefore an account must reach a shipyard".
2. **Acceptance criteria were mechanism-shaped, never goal-shaped.** Traces, idempotency, caps, lanes
   and delivery were all accepted as complete against their own mechanics.
3. **One acceptance wording was weaker than the goal.** The pilot found "decides but never acts"; the
   fix was accepted as **"the cohort acts"**, which one building type satisfies.
4. **Observables were assumed rather than owned.** Nothing in the plan had to name the mechanism
   producing a signal, so signals 1, 2 and 5 could be listed as goals while having no implementation
   at all.
5. **The plan audited behaviour and never audited identity.** All eleven signals describe what an
   account *does*. None of them says what an account looks like when someone first sees it, and
   nothing covers the population in aggregate, where sameness is itself the signal. That is why
   wave 2 found eight more gaps without touching a single behavioural requirement.
6. **The plan described the happy path.** There is no design for a suspended account, a deleted one,
   an account with no planets, or a table that grows forever — so the lifecycle has no owner, and the
   failure modes were never a deliverable.

## Rules that follow

- **The three cognition gates are the acceptance criteria these rules come from**: no static hardcoded
  AI, relatively simple, and what a good professional OGame player does. See
  [`specs/cognition-gates.md`](specs/cognition-gates.md).
- **Every open gap has a named algorithm, a set of host inputs and an acceptance test.** That is
  [`specs/gameplay-algorithms.md`](specs/gameplay-algorithms.md), written on 14 September 2026 after the
  deep source pass; its [gap index](specs/gameplay-algorithms.md#gap--algorithm-index) is the entry point
  when closing anything on this page, and its corrections section records where this register had to be
  amended.
- **A capability set is complete against a goal, never against a list.**
- **A slice that adds abilities must show the account reaching the next stage of the chain**, not
  merely performing one action.
- **Every observable the plan claims to control must name the mechanism that produces it.** A signal
  with no named mechanism is a gap, not an aspiration.
- **Audit against the goal, not against the plan.** This register is the artifact; re-run it whenever
  a slice closes, and treat an empty list as the evidence that a package is complete.
- **Authenticity has four surfaces — what the account does, what it looks like, how the population
  looks in aggregate, and how it fails — and auditing one is not auditing the others.**
- **A gate must not depend on what else is running.** A measurement that can change because a live
  pilot wrote a row is not evidence about the code, whether it goes red or green.
- **This register is re-run on a cadence, never only by accident.** Every closed slice and every pilot
  window ends with a [review record](specs/improvement-loop.md) that answers the goal's questions against
  the figures and feeds findings back here with an evidence class. The loop is the standing version of
  this register: it exists because finding the building chain by accident implied a class, and reading
  results deliberately is what stops the next class from waiting for a lucky query.

## Waves 3–5 — the axes that had not been examined

The list below was written when these axes were still unaudited. All three waves examined every item
in it; the findings follow the list.

- **Operational failure**: queue or database outage, repeated retries, vacation mode, a ban, a
destroyed planet, an abandoned account, restart behaviour, and what a *broken* AI account looks like
from outside.
- **Host social surfaces**: alliance invitations and internal alliance chat (as distinct from direct
chat), buddy requests, notes, marketplace offers and trade requests — and whether the account ever
appears in them.
- **Aggregate statistics beyond starting state**: whether espionage reports on two AI planets would
show indistinguishable resource and building levels, military points staying at zero, colony counts,
and queue-timing patterns across the cohort.
- **Rank trajectory**: the shape of the highscore path — entry rank, slope and any suspiciously clean
monotonic climb.
- **Request footprint**: authenticity signal 8 claims a cadence "indistinguishable from a human
opening pages", and the module's scheduled work is server-side, so what an operator sees in request
logs has never been checked against that claim.

## Deliberate, and not gaps

- Provider off by default, zero generative calls on the ordinary path.
- No AI-to-AI generated chat; authored dialogue for known exchanges only.
- Unrecognised free-form text answered with nothing.
- Sidecar drivers disabled until a Gate 2 verdict; embeddings and PsychSim behind measurement gates.
- No second player runtime, no generic game planner.

## Wave 3 — operational failure and lifecycle

| # | Gap | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- |
| O1 | **Nothing is ever deleted.** `expires_at` is written in several places — traces at 30 days, sealed replies, memory facts — and is only ever read as a validity filter. No command and no schedule prunes anything. | `expires_at` appears only in queries and inserts; the `ai:` command list has no prune. Measured: 76 work items, 48 traces and 18 receipts accumulated in about seven hours for eleven accounts, while a work item is created per session per account forever. | measured | **Closed 14 September 2026, slice L1.** One command (`ai:prune`), one nightly entry and one sweep per table over a model-to-window map: work items and receipts at ninety days, traces, observations and sealed replies at thirty. The window is checked against `created_at`, because the rows already carry `expires_at` for *validity* — a filter keeps such a row out of every query while leaving it on disk. Sized from the pilot's volumes, the steady state is roughly 2,100 work items, 500 receipts and 450 traces per account |
| O2 | **A suspended account keeps waking.** Only the action path checks `isBanned()` and `isInVacationMode()`; the session path does not, so a banned or vacationing account keeps deciding and scheduling successors indefinitely. | ban/vacation checks exist only in `QueueAiBuildingAction` | code-read | **Closed 14 September 2026, with one deliberate difference.** The observation path now asks the host's own state before it publishes anything, so a banned or vacationing account offers no capability, records `DoNothing` in its trace and queues nothing (`AiCapabilityPublicationTest` covers both states end to end). The successor session is deliberately still scheduled, and that is the difference: nothing else would wake the chain when a three-day ban expires, so the register's "stop scheduling" would have turned a temporary ban into permanent dormancy. Stopping an account that is gone for good belongs to O3/L2, not here |
| O3 | **No lifecycle for an empty account.** A player with no planets makes the planner return null, so the account goes silently idle forever instead of being disabled. Nothing handles a destroyed planet, an abandoned account or a deleted one. | `ownedState()` and the planner return null rather than reporting a state | inferred | **Closed 14 September 2026, slice L2.** One enum of four states and one resolver: a player row the host no longer has is `final`, the host's ban and vacation flags make it `suspended`, an account with nothing to play is `empty`, everything else is `active`. The observation publishes the state, so it is a stated fact rather than a null found deep in a planner, and the two states with nothing to come back to stop the chain — the session still records its decision and schedules no successor. A suspended account still schedules, because a ban and a vacation have an expiry the host owns, and nothing else would wake the chain to notice it |
| O4 | **"Bounded" is claimed in the plan and enforced only for stop counters.** The counters really are bounded per reason and day; work items, traces, observations and receipts are not bounded by anything. | `budgets.md` and the decision record describe bounded bookkeeping | code-read | **Closed 14 September 2026, slice L1, in the form this row itself offered — the claim was corrected.** The four tables named here, plus sealed replies, are now bounded by time, and the plan no longer claims the rest are: usage reservations, language requests and the score samples AG2 records stay unbounded until a measurement says otherwise, which is a smaller and truer statement than "bookkeeping is bounded" |
| O5 | **Nothing alerts.** A population that goes quiet is visible only to whoever reads the pilot report. | no alerting path in the module | code-read | Optional, and an operator decision rather than a defect |
| O6 | **The serial gate shared a database with the running application**, so an account finishing a building in that database could fail a test that counts rows: a red gate with no code change behind it, and a green one that was green only because nothing else happened to be running. | Measured 14 September 2026: `coverage` failed in `CommittedChatObservationTest` with "2 records were found" while the pilot's nine committed observations sat in `ogamex-test`; the same run passed with the pilot stopped, and passed again with the pilot running once the gate owned a database. | measured | **Closed 14 September 2026.** `scripts/ogamex coverage` creates and migrates `ogamex-test-ai-coverage` (`AI_COVERAGE_DATABASE`) and runs the serial gate there, so the gate no longer depends on what else is running |

Recorded as **not** gaps, with evidence, so the register shows what passed: retry behaviour (`tries` 3,
`maxExceptions` 3), lease reclaim after a killed worker, the per-player lock, the fail-closed HTTP
boundary, and provider failure falling back to authored text.

## Wave 4 — host social surfaces

| # | Gap | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- |
| S1 | **Alliance chat is deliberately invisible.** The observer returns immediately when a message carries an `alliance_id`, so only direct chat is observed. | `RecordObservedChatMessageAction` line 27 | measured | **Deferred with G18 (15 September 2026)** — the account does not join alliances in Package 4 |
| S2 | **Silence is the loudest possible tell.** With S1 and G18, an account in an alliance would never answer its alliance — worse than never joining. | follows from S1 and G18 | inferred | **Deferred with G18** — no alliance membership to sustain |
| S3 | **Invitations and requests are never seen or answered**: alliance invitations, buddy requests, notes, marketplace and trade offers. | no observer or action touches any of them | code-read | **Decided 15 September 2026** — decided per surface and deferred with G18/I8; nothing is instrumented speculatively |
| S4 | **No report is ever shared.** Signal 5 lists report sharing as social evidence; nothing sends an espionage report to anyone. | follows from G5 and G17 | inferred | **Deferred with G12** — sharing needs a recipient that buddies/allies would provide |

## Wave 5 — aggregate shape, rank and request footprint

| # | Gap | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- |
| A1 | **Two AI planets look the same to a spy.** Uniform starting state plus one buildable chain means resource and building levels converge, and an espionage report is exactly the view that reveals it. | follows from I2 and G1–G7 | inferred | **Closed by construction 15 September 2026** — seeded persona bands + host-derived economy ordering; measured divergence is a capacity-run question |
| A2 | **Military points are pinned at zero and the ranking is public.** | follows from G3 | inferred | Units |
| A3 | **Rank trajectory has never been measured**, and a population that grows in lockstep would climb in lockstep. | nothing measures it | measured | **Closed by AG2 15 September 2026** — the hourly score series makes the trajectory readable; figures come from the runs |
| A4 | **The claim in signal 8 is unsupported as written.** It requires a cadence "indistinguishable from a human opening pages"; the module makes no HTTP requests at all, because its work is scheduled and server-side. Zero footprint may well be better than a fabricated one, but the requirement and the design disagree. | module makes no page requests; signal 8 as written | code-read | **Decided 15 September 2026: correct the requirement** — signal 8 amended; no fabricated page cadence |
| A5 | **Last-activity is written accidentally, not by design.** ~~Nothing in `app/` touches `users.time`~~ — **corrected 14 September 2026:** `PlayerGameStateService::advance()` stamps `users.time` and `last_ip` from the ambient request, and the module already calls it from `QueueAiBuildingAction`, so the account does act while its last-seen moves, with a queue-context address. The register's original claim was true of direct writes and false transitively. | host source read 14 September 2026; the building action already calls `advance()` | measured (host) | **Decided 14 September 2026, slice H4: the stamp is accepted.** It rides real work inside the routine's waking window and never a keep-alive, so `isInactive`, the inactive-deletion scheduler and the galaxy marker keep reflecting the account, and an account cannot show activity while doing nothing. `AiActivityMarkerTest` pins both halves: a session with nothing to queue leaves `users.time` where it was, and a session that queues a building moves it through the host's own path ([A3](specs/gameplay-algorithms.md#ag3--request-and-activity-footprint)) |

## Sequencing

G1 is the prerequisite for G2–G7, G13–G17, and it is closed as of slice 3N: the account can now
reach the facilities those capabilities need, so the remaining work is the executors themselves.
G8, G10 and G11 are independent mechanisms and do not wait on the chain. G12 is independent of the
chain but needs a social policy decision first. O6 was found while closing G1 and is closed with it;
it is kept in the register because the failure mode — a gate that measures the environment instead
of the code — is the one to look for again. The three cognition gates recorded on 14 September 2026
([`specs/cognition-gates.md`](specs/cognition-gates.md)) are what changed G1's first implementation:
it worked, and it failed gate 1, so it was replaced rather than shipped.

## Register re-run — 15 September 2026

This register was re-run against the goal, per its own rule, before Package 4 sign-off. Every row
now has a disposition: closed with shipped code, closed by decision, or deferred by a recorded scope
decision — none is left as an open gap. The deferred rows (G8's reaction wake V2, G12, G18, S1–S4,
and the X1 transfer executor) are named follow-ups with fixed algorithms and triggers, not open
questions. The dispositions and their evidence are recorded in
[`DECISIONS.md`](DECISIONS.md#package-4-sign-off-decisions-15-september-2026). The completion-gate
items that are operational rather than code — the 2/5/10 runs, the disclosed human pilot and the
feedback file — are run after sign-off; the gate-2 verdicts for the three drivers and the two
narrower acceptance wordings are recorded as evidence rather than left open. An empty register is
the evidence Packages 1–4 are finished, and this re-run leaves it empty.

## Wave 6 — strategy-mining gap scan (15 September 2026)

This wave is **not** a capability re-run: waves 1–5 closed the capability gaps. It is the
[`strategy mining`](specs/strategy-mining.md) mapping of the validated principle catalog
([`research/strategy/`](research/strategy/README.md)) against current code. Each row is a
*strategy-depth* gap — the mechanism is nameable as ordinary experienced play, the host supports it,
and the module either does not reach it or reaches it thinner than the principle. Evidence class:
**code-read** (absent in the code as written) or **measured** (seen in the grand test). These are
enrichment gaps, not blockers; implementation stays blocked until the catalog is reviewed.

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W6-1 | **No activity risk in raid.** `RaidPlanner` gates on bashing + P20 profit only; `PlayerObservationService::targetReports` publishes `confidence = 1.0` and `travel_cost = 0.0` as placeholders. | 4, 5, 6 (target choice looks uniform) | code-read; `RaidPlanner::plan` | RAID-004/005/006: activity from `Planet.time_last_update`, per-type intel decay, fuel + slot cost in the profit test |
| W6-2 | **Spy target selection is first-fit.** `QueueableSpyPlanner::target` walks planets in `id` order and returns the first legal unknown; no score, distance or yield. | 5, 10 (scouting looks mechanical) | code-read | INT-003: a target score (distance, novelty, likely yield) inside the existing candidate/trace mechanism |
| W6-3 | **Fleetsave is reactive only.** The save fires only on `currentPlayerUnderAttack()`; there is no proactive exposure/fleet-value trigger and the destination is the first other own planet. | 1 (the one signal a player can *test*) | code-read; `QueueableFleetSavePlanner`, `inboundThreat` | FS-001/FS-005: proactive offline-gap save + destination×speed route scoring |
| W6-4 | **No fleet composition strategy.** `QueueableUnitPlanner` is a fixed role order with a single ratio; cargo is fixed at 1, no counters, fodder or recycler sizing. | 3, 4, 9 | code-read | FLE-002/FLE-004: payload-sized cargo and stage-based composition |
| W6-5 | **Debris, phalanx, moon, ACS and recall are host-supported but module-unwired.** `DebrisFieldService`/`RecycleMission`, `PhalanxService`, `JumpGateService`, `FleetUnionService`/`AcsDefendMission`, `cancelMission`/`startReturn` all exist in the host; no module planner/action references them. | 3, 4, 6 (a fleeter that never crashes, phalanxes or saves by moon) | code-read, host scan | CRASH-001..004: recycler trips, phalanx coverage, deploy-recall timing, moon/jump-gate value — as unsupported-until-verified, never silently |
| W6-6 | **No full next-wake scheduling.** `SessionPlanner` schedules sessions, but SP3's `min(eta…)` wake at the next material event is only partial. | 4 (self-similar cadence) | code-read | AUTH-004/SP3: next-wake = min of resource/build/fleet/slot/storage ETAs — **shipped (IMPL-042)**: building/research finish, own and inbound fleet arrival, clipped to the waking window with a right-skewed arrival delay; the resource/storage/slot terms remain deferred refinements |

Each open row has a named principle in the [YAML store](research/strategy/README.md) and a host input
(from [`host-capability-map.md`](research/host-capability-map.md)). Algorithm blocks now exist for
W6-1/2/3/4/5 in [`gameplay-algorithms.md`](specs/gameplay-algorithms.md) — SP7 (activity/intel reader),
T6–T8 (raid depth), N4/N5 (intelligence depth), V6–V8 (save depth), U5/U6 (fleet composition),
F1–F6 (fleetcrash/phalanx/moon) — plus the pass-6 ninja (NN1/NN2) and expedition (EX1) blocks — all
**planned**, blocked on catalog review. W6-6 (next-wake) rides SP3 and is **shipped 16 September 2026 (IMPL-042)** — the next material event (build/research/fleet finish) wakes the account inside its waking window; the resource/storage/slot terms stay deferred refinements. The classical-AI
pass ([`classical-ai-patterns.md`](research/classical-ai-patterns.md)) confirms these are
experienced-play mechanisms, not inventions. No row is closed by this pass — the executors do not
exist yet.

## Wave 7 — grand-test live verification (16 September 2026)

Read off the running universe rather than the code, per the [grand test](grand-test.md) §9 loop.
Evidence class **measured**: each row names the receipt, trace or score it was read from. The two
mechanical defects this pass found are closed in code (`dc3f597`); the economy finding stays open — its
obvious slice collided with the shipped storage precedence, and the fleeter-weight half is a tuning
question, both recorded in E6.

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W7-1 | **A raid windfall is not reinvested.** `FleeterPolicy` weights `QueueUnits` 0.6 and `Build` not at all, so a fleeter ranks ships 15 points above mines in every session and never answers the mine that is its binding constraint. | 3, 9 (growth curve, breadth of play) | measured: `ai:explain-decision --player=14` — QueueUnits 55.2 vs Build 40.3, the gap being `archetype_preference` 15.00 vs 0.00; p14 holds 881k metal against 1.5k crystal at mines 6/4/2 after 133 accepted dispatches, p19 1.82M against 1.6k at 7/6/2 after 147; both are the lowest scorers (3226, 4591) | **closed (IMPL-024 + IMPL-025)** — a windfall is spent before it is warehoused (E6 hypothesis b), and a severe scarcity now makes a mine outrank the ship habit (E6 hypothesis a, a bounded `resource_need` boost) |
| W7-2 | **The warehouse trigger cannot tell a windfall from production.** The fill-time test measures `getProductionPerHour`, so loot that lands in one tick reads as ongoing income and the next pass builds the store — for the resource that is *not* the constraint (`EconomyUpgrades::storage`). | 3 (a human notices the account buying warehouses instead of mines) | measured: p14 queued `storage:metal_store` 15× / `storage:crystal_store` 10× while crystal sat at 1,505 of 1,590,000; p19 holds 319k deuterium in a 20k warehouse; p12 is exactly full on all three resources (33.0M/33.0M) | **closed (IMPL-024)** — the storage trigger grows only while a fill lies in the future (`0 < time_to_fill < absence`); an already-full warehouse is a spend signal, so a windfall no longer ratchets the store |
| W7-3 | **In-flight spy intents are not counted against probes.** `QueueableSpyPlanner::inFlight` de-duplicates by target coordinates, so two scheduled probes for different targets can both be planned from one probe. | 5, 10 (a refusal reads as a mechanical mistake) | measured: 32 receipts `DispatchFleet` refused with the host's "Not enough units on the planet to send the fleet. Units required: espionage_probe" | INT-* : idle probes minus probes already committed to a pending spy item is the real budget |

Closed by this pass, both derived from live evidence and pinned by a test: **W7-fixed-1** —
`ai:run-due-work` now admits a lease whose worker was killed mid-handle, so an item can no longer be
stranded `Leased` (3 items sat 13 h; `stuck` is now 0). **W7-fixed-2** —
`QueueableBuildingPlanner` now asks the host's field gate, which removed the largest single source of
refused actions (236 receipts, 65% of all rejections; three of the ten planets were at or past their
cap). Full evidence in
[`reviews/2026-09-16-grand-live-verification.md`](reviews/2026-09-16-grand-live-verification.md).

## Wave 8 — grand-test live play read (16 September 2026)

The midday read of the running universe, registered here from
[`reviews/2026-09-16-grand-live-play-read.md`](reviews/2026-09-16-grand-live-play-read.md). Evidence
class **measured** where the read named the artifact a figure came from, **code-read** where this
pass confirmed the mechanism itself. Two things were corrected while registering:

- **The score-sample trail does survive.** The read recorded "the schedule discards its output, so
  there is no evidence trail"; the scheduler container's log keeps one line per scheduled run (only
  each command's *own* output is discarded, by Laravel: `> '/dev/null' 2>&1`). What the log shows is
  different from what the read inferred — see W8-L6.
- **The baseline moved.** The read ran while the module batch sat in the working tree; it is now one
  commit named `wip`, and the host half of the same pair is still uncommitted (V1).

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W8-L1 | **No dispatch planner but the save path reads the host's fleet-slot ceiling.** Eight of ten accounts hold one slot because the object that raises the ceiling is never chosen, so every colony, spy, raid, expedition and transfer dispatch is published and then refused by the host. | 1, 3, 9 | measured: `getFleetSlotsMax()` = 1 on p12/p15/p16/p20/p21, 3 on p13/p14/p18/p19, 10 on p17 (the only account that ever researched the object, level 9); 1027 dispatch refusals in 24 h, 917 of them `CreateColony`, every one "Maximum number of fleets reached"; code-read: `QueueableFleetSavePlanner` is the only `getFleetSlotsMax()` caller in the module | a host-ceiling read before a dispatch is published, and a build order that can reach the object that raises it. The host's answer names the object internally and `ObjectService` exposes no lookup by calculation type, so this is a host obligation (R11: publish the object carrying `MAX_FLEET_SLOTS`) rather than a module list — **closed (IMPL-035 + R11, REV-003)**. The observation publishes `fleet_slots_free`, `CandidateActionFactory` withholds a colony, spy, raid, expedition or transfer a zero-slot account cannot fly, and `FacilityChain` reaches the ceiling object through the host's `getObjectByCalculationType(MAX_FLEET_SLOTS)`. Frozen-clock tests: `DecisionEngineTest` (a spy and a colony are withheld when no fleet slot is free), `BuildingChainReachabilityTest` (a slot-bound account reaches the ceiling technology) |
| W8-L2 | **The raid pipeline is starved.** Espionage reports fell by two orders of magnitude and `Raid` stopped being offered at all, so the fleeter half of the cohort has nothing to act on. | 4, 5, 6, 10 | measured: reports 34–56/h on 15 Sep 13:00–15:00 → ~1/h after 18:00; Raid last offered and last selected 15 Sep 19:45; 400 recent fleeter (p14/p19) sessions offer none — DoNothing 400, Build 340, QueueUnits 299, Expedition 115, Research 60, Colonize 44, Spy 8 | **resolved (DISC-006, 16 Sep)**: W8-L1 confirmed as the cause by code-read — an espionage mission consumes one fleet slot and only its arrival creates an `EspionageReport`, so one-slot accounts never flew probes, produced no reports and starved `RaidPlanner`. IMPL-035 withholds spy/raid/colony/expedition/transfer when `fleet_slots_free < 1` and `FacilityChain` reaches the ceiling object via R11; IMPL-038 fixes the probe budget. A live recovery re-measure rides the next capacity/pilot run |
| W8-L3 | **The fleeter plays like a miner.** The 00:53 verdict — "matches the documented model in every row", Fleeter Raid 37.1% — no longer holds, and the `archetype_preference 15.00` QueueUnits ranking now has nothing competing with it. | 3, 4, 9 | measured: p14/p19 last 400 sessions — QueueUnits 296, Build 37, Research 36, Colonize 18, Spy 8, Expedition 5, **Raid 0** | `IMPL-024`/`IMPL-025` were written to change exactly this and were not in the running container when the read ran, so re-measure before treating the row as open — **closed (IMPL-025, REV-003)**. IMPL-025 is in the committed build: a severe scarcity now makes a mine outrank the ship habit (bounded `resource_need` boost), verified on a frozen clock by `ScarcityResourceNeedTest`. The remaining "Raid 0" is not a ranking defect — it is L2 (the raid pipeline is starved), which is what the fleeter falls back from; L2 stays open under DISC-006 |
| W8-L4 | **A warehouse that is already full is refused a warehouse, and the mine that should spend it may not be available.** The metal keeps producing and is discarded. | 3, 9 | measured: four homeworlds at exactly 100% storage (p12 33.0M/33.0M, p15 60.5M/60.5M, p16 18.0M/18.0M, p20 at cap and above it on deuterium); `QueueableBuildingPlanner::plan(20)` returns a *research* step, no build, so 8.9M metal/h is discarded; code-read: `EconomyUpgrades::storage()` skips `hours <= 0.0` by design while `production()` is bounded by the payback horizon | the E3/E6 precedence decided on a frozen clock. `W7-2` is recorded closed by `IMPL-024` while this read re-measured the same collision, so the closure is re-checked, not assumed — **closed (IMPL-036, REV-003)**. An already-full warehouse is a spend signal: `EconomyUpgrades::spendSurplus()` offers the best mine with no payback horizon and `QueueableBuildingPlanner` runs that pass before the chain, so a planet at 100% storage spends instead of discarding. Frozen-clock test: `BuildingChainReachabilityTest` (a full warehouse is spent, not grown) |
| W8-L5 | **One account froze for 14 h: colonise outranks everything every session, and its two colonies have never materialised.** | 3, 4, 9 | measured: p20 last 6 h — 174 sessions, 175 colonise candidates, 168 colonise work items, **zero other decisions**, 112 refused at the fleet cap, 56 accepted, score +0 (rank 6 → 10); the two colonies founded 15 Sep 21:29/21:33 report `PlanetService::metalStorage() = 0`, production 0, mines 0/0/0 and `time_last_update` equal to their creation time; scoring: colonise ~49.6 against research 39.6 / units 40.1 / expedition 38.7 | an eligibility rule that reads the host ceiling and the account's ability to develop the body it would found — **closed (IMPL-037, REV-003)**. `colonize_eligible` is published only when the account's own production can fund a colony's opening inside the 48 h storage horizon, so colonise no longer outranks the body that pays for it. Frozen-clock test: `DecisionEngineTest` (a colony is withheld when the account cannot develop it even with a free slot). The un-materialised colony stats were DISC-005 — **resolved 16 Sep**: the host materialises a settled colony (`createPlanet` writes metal=500/crystal=500, field, temperature, 100% mine percents); the zeros are the host's lazy storage/production columns, computed only by `PlanetService::update()`, which the session loop runs on the current planet alone. The account never develops its own new colony — the root IMPL-037 now guards |
| W8-L6 | **The hourly growth sample stopped firing, and the run line is the only trail.** | review loop (the population's growth curve cannot be read back) | measured in this pass: the grand scheduler container (created 15 Sep 06:50) logs `ai:record-score-samples` five times, 15 Sep 07:00–11:00, and never again over 33 h, while `ai:run-due-work` fires continuously; no skipped/mutex line is logged, and each command's own output is discarded (`> '/dev/null' 2>&1`); `ai_score_samples` has no row between 15 Sep 22:00 and 16 Sep 12:00 | read the surviving run log and the entry's own guards, find why the entry stopped firing, then keep one bounded line per run. No new table and no new job — IMPL-041 |
| W8-L7 | **Stop counters have never been written, so the artifact cannot say why the population was quiet.** | review loop (the "why is nothing available" surface the loop depends on) | measured: `ai_stop_counters` empty after 31 h while 470 sessions chose `DoNothing`, every one of their traces carrying exactly one candidate whose reason is `always_available`; code-read: `AiStopReason` holds admission reasons only, and `RecordAiStopReasonAction` is called from admission alone | write the reason where the quiet decision is already taken, and only where a mechanism already decides — no new table — IMPL-040 |
| W7-3 | **In-flight spy intents are not counted against probes** (carried forward from wave 7). | 5, 10 | measured: 32 receipts refused with "Not enough units on the planet to send the fleet. Units required: espionage_probe"; code-read: `QueueableSpyPlanner::inFlightCoordinates()` de-duplicates by target coordinates, so two scheduled probes for different targets both plan from one probe | the real budget is idle probes minus probes already committed to a pending spy item (INT-*) — IMPL-038 |

### Owed before any wave-8 row is called closed

| # | What must be checked | Why |
| --- | --- | --- |
| W8-V1 | The module batch is **one commit named `wip`** (100 files, +3931/−217) and the **host half of the same pair is still uncommitted** — `app/Enums/UniverseMode.php` is untracked and `HostilityGuard`/`GameMission`/`SettingsService` are modified — so the committed module registers a policy against host classes a fresh host checkout does not have | "done means merged and verified", and the pair rule: one host safety agent and one module campaign agent merged together — REV-002 |
| W8-V2 | Every row above was measured against the **running containers**, which mount the working tree; four of the rows are decision-core claims that `IMPL-024`/`IMPL-025` were written to change | a measurement that can change because a live pilot wrote a row is not evidence about the code — **satisfied (REV-003)**. L1/L3/L4/L5 re-measured on the committed build with frozen-clock before/after (Pest 789/789 green); L1/L4/L5 closed, L3 closed as downstream of L2. See `reviews/2026-09-16-wave-8-remeasure.md` |

**Closed while registering this wave:** `plan/tasks/seed.sql` could not be rebuilt — `sqlite3 plan/tasks/tasks.db < plan/tasks/seed.sql` failed with "all VALUES must have the same number of terms" and then a foreign-key error, so the regeneration command [`tasks/USAGE.md`](tasks/USAGE.md) documents did not work. The task and dependency blocks are regenerated from the DB in this pass and the rebuild is verified identical to it (48 tasks, 31 dependencies, exit 0), with the rows of this wave added to both.

The other outstanding measurement is completion-gate item 1: REV-004 records what the 2/5/10-account
comparison must report, and `IMPL-031` (6B) and `DEF-001` (V2 reaction wake) both wait on it.

## Wave 9 — half-wired play loops (17 September 2026)

Mined in the repo pass; full evidence in `repos/half-wired-play-loops.md`. Each row is a loop that
is started but never closed, so the mechanism exists on one side and nothing on the other.

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W9-1 | **`SaveResources` is a declared capability that nothing produces.** The enum case, three policy preference weights and the scheduler's `=> null` branch exist, but `availableActions()` never publishes it, so no candidate is ever built and the branch is dead. | 3, 4 | code-read; `AiCapability.php:8`, `PlayerObservationService.php:331-337`, `ScheduleAiIntentAction.php:135`, `Policies/MinerPolicy.php:13` | wire a real hoard-for-next-step intent, or delete the capability, the weights and the null branch — gate 2/3 — **closed (HL-001)**: the capability was deleted across the enum, policies, scorer and scheduler |
| W9-2 | **The `recovery` score term is always 0.** `RECOVERY_WEIGHT = 20.0` multiplies a `recoveryFactor` that `ownedState()` never publishes, so live decisions score it 0; only replay carries a value. | 3, 4 | code-read; `UtilityScorer.php:23,56`, `PlayerPerceptionBuilder.php:41`, `PlayerObservationService.php:88-108` | publish a real recovery signal from an existing observation, or delete the component and the snapshot field — **closed (HL-002)**: `recovery_factor` is published from the account's decaying Anger affect |
| W9-3 | **Target legality is never checked before a raid.** `attack_permitted` is hardcoded `true`, so the `AttackNotPermitted` rejection is unreachable and `RaidPlanner::plan()` gates on bashing + profit only. | 1, 3 | code-read; `PlayerObservationService.php:244`, `CandidateActionFactory.php:217`, `RaidPlanner.php` | compute `attack_permitted` from the host's legality answer, or add the check to `RaidPlanner::plan()` — **closed (HL-003)**: `attack_permitted` mirrors the host's own-body / vacation / banned / admin checks |
| W9-4 | **`losingRuns` is counted and never consumed.** The estimator computes how many sampled runs lose, but no gate reads it. | 3, 6 | code-read; `NativeRaidEstimator.php:85`, `RaidEstimate.php:19`, `RaidPlanner.php:124` | add a losing-run threshold to the profit gate, or delete the field — **closed (HL-004)**: `losingRuns` deleted; P20 <= 0 already encodes a losing fifth, and WP-003 adds a real survival floor later |

## Wave 10 — session cost read (17 September 2026)

A read-only pass over the running grand universe, taken because an AI **session** job measured 1–36 s
where its sibling work kinds measure ~310 ms. Nothing was changed to take it: a `DB::listen` listener
with a `debug_backtrace` walk attributed every query of one
`PlayerPerceptionBuilder::build($playerId, 45)` to the frame that issued it, and MySQL's own profiler
supplied the server-side figure (0.076 ms per `select 1`, so the cost is round trips and rebuilds, not
query work). A session's cost *is* its perception build: `PlayerObservationService::availableActions()`
runs every planner, and each planner re-resolves the host service graph.

Two defects found in the same pass were fixed immediately and are **not** rows below, but they set the
baseline these rows are read against — ~430 queries per perception after them, ~516 before:

- **`CACHE_STORE`, not `CACHE_DRIVER`.** Laravel 13 reads `env('CACHE_STORE', 'database')`, and both the
  grand and capacity composes set the legacy `CACHE_DRIVER`, so the live universe silently ran the
  **database** cache: every module lock, circuit-breaker read/write and host cache op was a MySQL round
  trip and `CACHE_PREFIX` isolation was a no-op.
- **The colony walk.** `QueueableColonyPlanner::emptySlot()` checked up to 600 coordinates with
  `makeForCoordinate($coordinate, false, ...)` — cache bypassed, one query per position, 405 queries per
  perception. Now one read per galaxy over the systems the walk visits, with walk order, bounds and
  tie-break unchanged.

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W10-1 | **The host mission catalogue is instantiated to read static metadata.** `GameMissionFactory::getAllMissions()` resolves 11 mission classes, and `GameMission::__construct` pulls `FleetMissionService` and `MessageService`, both of which require a `PlayerService` — so every mission build drags a player (users, highscores, users_tech, planets list). Measured **33 queries per call**, and the module's `FacilityChain::missionRequiredResearch()` calls it **9 times per perception = 297 of ~430 queries**, for `getTypeId()`/`getRequiredResearch()`/`getRequiredShipMachineNames()`, which are already `static`: the same metadata through the class name costs **0 queries**. | 1, 2 | measured; `GameMissionFactory.php:23-50`, `FleetMissionService.php::__construct`, `MessageService.php::__construct`, `FacilityChain.php:129`; 33 / 297 / 0 queries | split the catalogue from instantiation (`getMissionClasses()`) and let the static-metadata callers use it — **IMPL-043** |
| W10-2 | **The host service graph is rebuilt per lookup.** `PlayerServiceFactory::make($id, true)` rebuilds a `PlayerService` on every call (~4 queries: users, highscores, users_tech, and `PlanetListService`'s planets read), and one perception triggers ~34 rebuilds through 20 module and 30 host forced-reload sites; `PlanetServiceFactory::makeForCoordinate($c, false, ...)` bypasses the factory's own coordinate cache (the colony walk did this 405× before the fix above). | 1, 2 | measured; `PlayerServiceFactory.php:28-42`, `PlayerService.php:74-121`, `PlanetListService.php:45`, `PlanetServiceFactory.php:225-275` | one reload per job (memoise the forced reload, or make the planet list lazy) — **IMPL-044** |
