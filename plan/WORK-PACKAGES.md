# Agent work packages

Assign one package to one module agent. Agents work only in the AI-module worktree. No agent edits this plan while implementing. The coordinator alone updates planning documents after integrating a result.

Every agent begins by reading the assigned package and the named section of [details/IMPLEMENTATION.md](details/IMPLEMENTATION.md). It then checks the current target branch, implements only its allowed scope, runs the named checks, and returns: changed files, commit, checks/results, unresolved risks and the next package now unblocked. It does not start adjacent work because it looks easy.

## Package 1 — safe module bridge

**Status:** foundation committed; included in the Phase 2 baseline `e1c48a2`. **Owner:** one module agent.

**Goal:** an AI-controlled existing player queues one building through the exact validated game path; duplicate work cannot queue a second building.

**Dependencies:** none.

**Module scope:** profile/work/receipt migrations and models, `QueueAiBuildingAction`, `ProcessAiWork`, `BuildFirstBuilding`, `RunDueAiWork`, and focused module tests. Use lease token, per-player lock and one idempotency key through the module receipt. No fleets, combat, chat, memory provider or UI.

**Allowed repositories:** `Modules/AI` only. **Do not modify:** host services, controllers, fleet code, events, battle engine, social systems, core module loader or any later package.

**Acceptance:** valid building queues once; duplicate delivery queues once; invalid input writes no game state; no model/provider request.

## Package 2 — legal perception and deterministic sessions

**Status:** deterministic slice committed at `e1c48a2`; unsupported capabilities remain recorded intents. **Owner:** one module agent.

**Goal:** varied AI accounts make reproducible zero-token choices using only legal information.

**Module scope:** module-owned observation reduction, schedules, session planner, perception snapshot, candidate factory, utility scorer, seeded random source, clock, decision traces and Miner/Turtle/Fleeter/Trader/Casual policies. Always evaluate do-nothing and eligible fleetsave. List score components and source timestamps in traces. Unavailable game actions remain recorded, safe intents.

**Do not modify:** relationships, generated chat, PvE campaign code, population UI or provider adapters.

**Acceptance for this completed slice:** frozen clock + seed reproduce trace; traces expose no hidden target data; stale intel blocks a risky raid intent; Miner selects legal building intents and never attacks; Turtle selects units/defence intents and never attacks; Fleeter selects fleetsave ahead of a visible raid; Trader selects colonization intents and never attacks; Casual selects do-nothing when no safe action exists; novice and veteran skill bands select differently within their defined near-equal-choice bounds; recovery input is bounded and visible in the recorded score. These are dedicated intent/decision feature scenarios, not proof of unit queuing, fleetsaving, colonization or simulated combat execution. Future executable adapters/estimation need their own host-effect and no-write tests; the existing first-building path is tested separately.

**Test-fixture rule:** use real OGameX services, models and validation paths. Mockery is prohibited. A narrow container override is permitted only to verify an explicitly replaceable package/action boundary, never as a substitute for testing production mechanics.

**Container rule:** actions, jobs, services, policies and collaborators are resolved through `app()` or `app()->makeWith()` in module code and tests. Direct `new` is limited to plain value objects and deliberately non-container data.

**Pest rule:** all AI-module tests use native Pest 5 syntax and named datasets for repeated mechanics. Run PCOV with PAO and `--tia`; never enable Xdebug for this suite. Pest dependencies remain module-local. PHPUnit-style test classes/assertions and Mockery are prohibited.

## Package 3 — social cognition, experience and bounded conversation

**Status:** slices 3A–3L implemented and committed; 3J's scenario/ablation harness and export/delete acceptance are done. By owner decision of 14 September 2026 the capacity runs stay at the very end of the phase, rescaled from 100/500/1,000 players to 2/5/10 accounts, and a 10-account pilot stands in for them until then; that pilot has run and its result is recorded in [details/DECISIONS.md](details/DECISIONS.md), and the gap that pilot measured is closed by slice 3M, which publishes only executable capabilities and queues a real building through the existing action path. Package 2 is the existing baseline. Source durability, schema and chat permissions are resolved by the first Phase 3 slice, not an indefinite dependency on a future Phase 2 rewrite. **Owner:** one module agent per non-overlapping milestone, integrated in order.

**Goal:** believable, persistent social behavior and outcome-based learning with no generative calls for ordinary gameplay; authored dialogue handles known exchanges and optional language handles unrestricted human conversation.

**Required reading:** [actual Phase 2 state](details/research/phase-3-current-state.md), [Phase 3 architecture and milestones](details/specs/phase-3-cognition.md), [memory/language](details/specs/memory-and-language.md), [Laravel AI SDK integration](details/specs/laravel-ai-sdk.md) for 3H, [budgets](details/specs/budgets.md), [validation](details/specs/validation.md). Read [driver evaluation](details/research/memory-comparison.md) only for a driver/benchmark milestone.

**Scope:** native facts/claims/relationships/commitments, affect and goal pressure, significant emotional episodes, structured social protocols/authored dialogue, real outcome cases and CBR, coalesced replies, context selection, atomic budgets, safe normal-host delivery, six concrete module contract seams and repeatable experiments. New optional external adapters are separate focused slices after their native boundaries work.

**Integration:** reuse existing module discovery, provider bindings and actor-scoped services/records. Candidate scoring may consume bounded accepted cognitive/experience inputs when a scenario requires them; do not replace the scheduler/utility engine. Validate source durability and human-equivalent chat permission rules in module actions. Consider a separate generic host hook only for a demonstrated gap.

**Do not build:** a framework/package split, generic game planner, mandatory sidecars, automatic reflection/extraction, per-event summaries, AI-to-AI LLM chat, a new fleet/combat engine or mandatory semantic/vector storage. Track progress in the [delivery slices](details/specs/phase-3-cognition.md#delivery-slices-and-completion-evidence) and keep each change inside its milestone. Mem0 is rejected; FAtiMA, CBRKit, AgentOS and PsychSim remain candidates behind boundaries.

**Milestones:** 3A sources; 3B facts/obligations; 3C native affect; 3D social protocols; 3E experience; 3F authored delivery; 3G context/budgets; 3H optional language; 3I separate driver experiments; 3J acceptance/measurement; 3K deterministic conversation cycle; 3L provider escalation; 3M capability publication and intent execution. Each has a testable outcome in the [implementation sequence](details/specs/phase-3-cognition.md#delivery-slices-and-completion-evidence). Optional experiments do not make every sidecar a release dependency.

**Acceptance:** the eight specified flows and behavioral matrix pass using real module/host paths; no leaked/stale truth, duplicate side effects or unapproved commitments; known social exchanges work with no LLM; one bounded foreground generation includes any proposals; provider-off gameplay continues; driver replacement preserves module-owned persona/obligations; benchmark reports actual behavior, prompt/token use, latency, CPU/RAM and failures rather than claiming unmeasured capacity.

**Engineering rules:** Nagi instructions apply: descriptive action methods, no forwarding-only wrappers, no `else` branches, enums for defined values, Laravel `app()` bindings, native Pest 5 datasets, PAO, PCOV and TIA. No Mockery or Xdebug. Tests cover behavior/edges/branches in addition to changed-area line coverage; real adapter integration and opt-in provider quality experiments are distinct.

## Package 4 — operability and disclosed pilot

**Status:** implemented, run at 10 accounts on 14 September 2026, and **signed off on 15 September 2026** — every code gate is green (598 Pest tests, 100.00% PCOV) and the acceptance is recorded in [details/DECISIONS.md](details/DECISIONS.md). The gap that run measured — a population that decided without ever acting — was closed by slice 3M the same day, so the cohort now acts as well as decides. Package 3's slices 3A–3M are implemented and committed, its 3H real-provider artifact now exists (4/4 sanitized cases against `deepseek-flash`, every interpretation matching, injection refused), and only the driver Gate 2 evidence is open — recorded as disabled on evidence. **Owner:** one module agent.

**Goal:** operators can safely inspect, replay, cap and disable AI before increasing population.

**Scope:** existing `admin.nav` page only; profile enable/disable; redacted decision trace; read-only replay; production-refusing synthetic seeding; population/session/action/language caps; stop reasons and metrics.

**Do not modify:** host UI extension system, game mechanics, action contracts or PvE behavior.

**Acceptance:** staff kill switch stops new work; replay writes nothing; production seed refusal is tested; dispatcher respects every cap; pilot report contains action success, worker failure, tick latency, cost and human feedback.

**Delivered:** `ai:seed-test-universe`, `ai:explain-decision`, `ai:replay-scenario` and `ai:pilot-report`, the admission caps with their recorded stop reasons, the staff switch, and the operator page that shows the switch, the caps, today's refusals and recent decisions and can replay a shipped scenario. Two acceptance wordings are met with narrower evidence and say so: replay is read-only over a saved scenario rather than over live state, and the report gives the module's own scheduling lateness because this host has no server tick to measure — resources progress lazily and fleet arrivals are queued jobs. Human feedback is read from an operator-supplied file and reported as not recorded when absent, because the module cannot measure it.

**Ran at 10 accounts (14 September 2026):** ten accounts seeded through the host registration path, one dispatcher pass, ten sessions completed, ten successors scheduled, zero provider requests, zero worker failures, and lateness of p50 0.8 / p95 0.9 minutes. Every one of the ten sessions chose `DoNothing`, because the ordinary-play observation publishes no ability and nothing creates the one executable building work item — the finding and its evidence are in [details/DECISIONS.md](details/DECISIONS.md).

**Follow-up the same day, after slice 3M:** the gap that run measured is closed, and the cohort's next sessions act as well as decide. Two accounts queued real buildings through the ordinary host action path — player 29129 a solar plant, player 29132 a crystal mine, each the building its own planner published — so the report reads `actions: Accepted 2` where the original window read `none`, with three build intents and zero provider attempts. The measured result is recorded in [details/DECISIONS.md](details/DECISIONS.md).

## Package 5 — external drivers, full utilization and native↔external collaboration

**Status:** implemented on 15 September 2026 — all gates green (Rector, Pint, PHPStan 0 errors, 614 Pest tests, 100.00% PCOV) and the hybrid mode measured against the real sidecars; not yet signed off. **Owner:** one module agent.

**Goal:** on a host with measured headroom the optional external cognition drivers are used to their
full extent — not as swap-only decorations — and the native engines keep running alongside them, so
both contribute to one decision instead of one replacing the other.

**Scope:** the `hybrid` cognition mode (`ai.cognition.mode = native | external | hybrid`), the widened
contracts (`AffectAppraisal`, `SocialExchangeEvaluation`, `RankedExperience`, memory recall), the
per-contract combiners that blend native + external output, and the full-surface adapters — FAtiMA's
appraisal/decision/social-importance depth, CiF's per-mode volition and step, CBRKit's own retrieval
measure (the ported `retriever.py` formula is deleted), and AgentOS's recall diagnostics plus the
first real `LongTermMemory` caller.

**Do not modify:** the host, the native engines' shipped behavior under `mode = native`, the authority
rule that a driver can never grant what the module refuses, or the cooperative PvE package. No driver
is enabled on the reference profile by this package.

**Acceptance:** with `ai.cognition.mode = native` the reference profile behaves exactly as today and
makes zero external calls; with `hybrid`, each selected driver contributes its own signal *in addition
to* the native floor, every failure degrades per call to native alone, and the measured comparison
(`ai:cognition-conformance`) names the gain each driver adds. Milestones 5A–5F and their proofs live in
the [external-drivers spec](details/specs/external-drivers.md).

## Packages 1–5 completion gate (owner rule, 14 September 2026)

Package 6 (cooperative PvE) does not start until Packages 1–5 are **fully and completely finished,
with every gate closed** — not merely implemented, run and signed. Those are three different states
and none of them is sufficient on its own. This is the checklist that separates them; every **open**
item blocks Package 6. Package 5 (external drivers) is not gated by this checklist; it is gated by
its own acceptance in the [external-drivers spec](details/specs/external-drivers.md), and its
completion becomes one more row here before Package 6 starts. Completeness is audited against the
goal rather than against this list alone: the [gap register](details/GAP-REGISTER.md) holds the
eighteen gaps that audit found, and an empty register is the evidence that Packages 1–4 are actually
finished. Every item is also checked against
the three [cognition gates](details/specs/cognition-gates.md) — no static hardcoded AI, relatively
simple, and what a good professional OGame player does — because an item can satisfy its own wording
and still fail the game.

| # | Item | Package | Status |
| --- | --- | --- | --- |
| 1 | 3J runs at **2, 5 and 10** AI accounts, recorded as figures (memory gate G3). Rescaled by owner decision of 14 September 2026 from 100/500/1,000 players: at this size they show that behaviour, lateness and per-player cost hold as the population grows rather than measuring capacity at reference-profile scale | 3 | **Post-sign-off** — parked at the very end by owner decision; the 10-account pilot stands in until they run |
| 2 | A Gate 2 verdict for each of CBRKit, FAtiMA/CiF and AgentOS. A driver that fails Gate 2 closes this item by being recorded as disabled on evidence: the gate asks for a measured verdict, not for adoption | 3 | **Recorded as disabled on evidence (15 September 2026).** AgentOS fails Gate 2 on the reference profile (~920 MB node_modules + a local-embedder need); FAtiMA (108.9 MiB) and CBRKit (142.1 MiB) fit but stay disabled pending a measured gain — which is the verdict the gate asks for, not adoption |
| 3 | The disclosed pilot at pilot scale, run with real humans | 4 | **Post-sign-off** — run once the cohort acts; Package 4 is operability and holds the evidence to schedule it |
| 4 | Human feedback present in the pilot report, read from an operator-supplied file | 4 | **Post-sign-off** — read from the operator-supplied file when the disclosed pilot runs |
| 5 | Two acceptance wordings met only with narrower evidence: replay is read-only over a saved scenario rather than live state, and lateness is the module's own scheduling lateness because this host has no server tick to measure | 4 | **Accepted as permanently narrower (15 September 2026).** Replay over a saved scenario and module-own scheduling lateness are honest statements of what the host offers; recorded in DECISIONS.md |
| 6 | The owner's acceptance written down in [details/DECISIONS.md](details/DECISIONS.md) | 4 | **Signed off 15 September 2026.** All gates green — Rector, Pint, PHPStan (0 errors), 598 Pest tests passed, 100.00% PCOV — and the acceptance is written down in [details/DECISIONS.md](details/DECISIONS.md) |
| 7 | Executor coverage for what the decision engine can select: `build`, `research`, `queue_units` (cargo + colony ship + probe), `colonize`, `fleet_save`, `spy` and `raid` all execute; only `save_resources` remains a traceable intent with no executor | 2/3 | **Complete** — every published capability now has a host path, 14 September 2026 |
| 8 | The building chain the executors depend on: the planner must reach the facilities the later capabilities are gated behind, and must pick among targets the host already accepts rather than the best target overall | 2/3 | **Implemented (14 September 2026)** — measured: a seeded account owns no buildings and no research, so without this every fleet, unit and research capability stays permanently unavailable on that account. `FacilityChain` derives the steps from the host catalogue rather than naming them (gate 1), and `QueueableBuildingPlanner` walks those steps plus the persona ranking until the host accepts one, so a refused favourite no longer costs the account its whole build capability. Covered by `BuildingChainReachabilityTest`, whose expectations are computed from the same catalogue |
| 9 | Package 5 acceptance — the external drivers used to their full extent under the hybrid mode, measured per its spec | 5 | **Implemented 15 September 2026** — all gates green and the hybrid mode measured against the real sidecars; deferred and named: mood/decision depth beyond valence, CBRKit's own retrieval measure, the per-fact relevance surface |

Already closed: the global A1–A8 gates are evidenced, and Gate 1 passes for all three drivers.

## Package 6 — cooperative PvE mode

**Status:** blocked by Packages 1–5, blocked by every open item in the [completion gate](#packages-15-completion-gate-owner-rule-14-september-2026) above, **and gated by sign-off.** This package does not start until Packages 1–5 are complete *and signed*: every acceptance criterion met with recorded evidence, the pilot report reviewed, and the owner's acceptance written down in [details/DECISIONS.md](details/DECISIONS.md). Implemented is not signed — an operator who has not read the pilot report has not accepted it — and by owner rule of 14 September 2026 neither is finished: Packages 1–5 are done only when the checklist above has no open items left. **Owner:** one host safety agent and one module campaign agent, merged only as a paired release.

**Goal:** a dedicated cooperative universe lets humans fight an AI faction using normal game systems while human-on-human hostility stays blocked.

**Host scope:** generic `HostilityPolicy` and registry; enforce it at fleet dispatch and attack, espionage counter-battle, missile, moon-destruction and ACS join/invitation paths. In cooperative mode, missing/disabled/erroring module policy must reject human-versus-human hostility.

**Module scope:** campaign, objective and contribution records; campaign director; reward allocator; cooperative policy registration. Use normal accounts, mission actions, combat estimation and committed events. Start with one faction and one objective type.

**Do not modify:** ordinary universe behavior, core battle rules, ships or resources. Do not create an AI-only combat engine.

**Acceptance:** humans cannot attack, counter-spy, missile or join ACS against humans in cooperative mode; both can fight faction; ordinary mode stays unchanged; disabling module remains safe; contributions are idempotent; failed coalition gets a recoverable next objective.

## Standing after delivery — the review loop

Not a package and not assignable: the coordinator owns it, because reading the results is what decides
what the next package is. After each closed slice and each pilot window, one [review record](details/reviews/)
reads what the accounts actually did and answers the goal-shaped questions in
[the review loop](details/specs/improvement-loop.md). Findings enter the [gap register](details/GAP-REGISTER.md)
with an evidence class, are closed through the named algorithm in
[gameplay algorithms](details/specs/gameplay-algorithms.md), and a material change is recorded in
[DECISIONS.md](details/DECISIONS.md). The latest record is part of the sign-off evidence for Package 4 and
for any population increase; an open finding that touches play quality is an open item on the
[completion gate](#packages-15-completion-gate-owner-rule-14-september-2026) the same way any other
measured gap is. It adds no runtime: it reads artifacts the module already writes, and a question it
cannot answer is a register entry rather than a new dashboard. **Reading must stay cheap** — one bounded
pass per window, a stable machine-readable answer the review parses instead of prose it re-reads, counters
aggregated at write time, and no generative call in the read path — because a review nobody can afford to
run is a review that stops happening. It is also invisible to play: no part of it runs inside a session or
a job, its one added write is a scheduled hourly sample whose cost is measured before it ships, and
`ai.review.enabled` (default on) switches that collection off without touching the records the operator
page and the pilot report are made of.

## Integration order

Merge packages strictly 1 → 2 → 3 → 4 → 5 → 6. A package first uses existing OGameX services, models and module extension points. Only a proven missing generic capability may produce a separate host pull request; the next module agent then works only from that merged revision, recorded in its handoff.

The [strategy mining](details/specs/strategy-mining.md) workstream is **not a package**: it is a
research/planning pass over Packages 1–5 that enriches the existing algorithms. Its implementation
slices (wave-6 blocks T6–T8, N4/N5, V6–V8, F1–F6, and the U-series fleet-composition block) merge in
the order 13–18 recorded in
[gameplay-algorithms.md](details/specs/gameplay-algorithms.md#delivery-order), gated per reviewed
cluster by the integration gates in the mining spec — they never precede the package they depend on,
and none is executable until its cluster passes review.
