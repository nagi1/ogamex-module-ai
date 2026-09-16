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

**Status:** implemented on 15 September 2026 — all gates green (Rector, Pint, PHPStan 0 errors, 614 Pest tests, 100.00% PCOV) and the hybrid mode measured against the real sidecars; 5D (CBRKit's own retrieval measure) closed 16 September 2026 and the three consumer-less deferrals closed by decision; **signed off 16 September 2026** (owner direction: "finalize all gated for 5 and start 6"). **Owner:** one module agent.

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
| 1 | 3J runs at **2, 5 and 10** AI accounts, recorded as figures (memory gate G3). Rescaled by owner decision of 14 September 2026 from 100/500/1,000 players: at this size they show that behaviour, lateness and per-player cost hold as the population grows rather than measuring capacity at reference-profile scale | 3 | **Done 16 September 2026.** Measured at 2/5/10 accounts via `local-docker-dev/capacity-run.sh` (fresh `ogamex-cap-{2,5,10}` universes, never the holy grand DB): per-player cost flat (~1.15–1.17 GiB worker RAM, ~375–386 ms / 9-query report read), lateness bounded (p95 1.53→3.82 min), zero stuck/retried, zero provider calls. Figures in [reviews/2026-09-16-capacity-2-5-10.md](details/reviews/2026-09-16-capacity-2-5-10.md) |
| 2 | A Gate 2 verdict for each of CBRKit, FAtiMA/CiF and AgentOS. A driver that fails Gate 2 closes this item by being recorded as disabled on evidence: the gate asks for a measured verdict, not for adoption | 3 | **Recorded as disabled on evidence (15 September 2026).** AgentOS fails Gate 2 on the reference profile (~920 MB node_modules + a local-embedder need); FAtiMA (108.9 MiB) and CBRKit (142.1 MiB) fit but stay disabled pending a measured gain — which is the verdict the gate asks for, not adoption |
| 3 | The disclosed pilot at pilot scale, run with real humans | 4 | **Reclassified 16 September 2026** — runs alongside Package 6; the owner provides the humans once the cohort is disclosed |
| 4 | Human feedback present in the pilot report, read from an operator-supplied file | 4 | **Reclassified 16 September 2026** — read from the operator-supplied file when the disclosed pilot runs alongside Package 6 |
| 5 | Two acceptance wordings met only with narrower evidence: replay is read-only over a saved scenario rather than live state, and lateness is the module's own scheduling lateness because this host has no server tick to measure | 4 | **Accepted as permanently narrower (15 September 2026).** Replay over a saved scenario and module-own scheduling lateness are honest statements of what the host offers; recorded in DECISIONS.md |
| 6 | The owner's acceptance written down in [details/DECISIONS.md](details/DECISIONS.md) | 4 | **Signed off 15 September 2026.** All gates green — Rector, Pint, PHPStan (0 errors), 598 Pest tests passed, 100.00% PCOV — and the acceptance is written down in [details/DECISIONS.md](details/DECISIONS.md) |
| 7 | Executor coverage for what the decision engine can select: `build`, `research`, `queue_units` (cargo + colony ship + probe), `colonize`, `fleet_save`, `spy` and `raid` all execute; only `save_resources` remains a traceable intent with no executor | 2/3 | **Complete** — every published capability now has a host path, 14 September 2026 |
| 8 | The building chain the executors depend on: the planner must reach the facilities the later capabilities are gated behind, and must pick among targets the host already accepts rather than the best target overall | 2/3 | **Implemented (14 September 2026)** — measured: a seeded account owns no buildings and no research, so without this every fleet, unit and research capability stays permanently unavailable on that account. `FacilityChain` derives the steps from the host catalogue rather than naming them (gate 1), and `QueueableBuildingPlanner` walks those steps plus the persona ranking until the host accepts one, so a refused favourite no longer costs the account its whole build capability. Covered by `BuildingChainReachabilityTest`, whose expectations are computed from the same catalogue |
| 9 | Package 5 acceptance — the external drivers used to their full extent under the hybrid mode, measured per its spec | 5 | **Implemented 15 September 2026, 5D closed 16 September 2026.** All gates green and the hybrid mode measured against the real sidecars; CBRKit's own retrieval measure shipped (+25.0pp precision@2 on the synthetic held-out set); FAtiMA mood/decision depth and the per-fact relevance surface closed as deferred (no consumer); the memory consumer disabled on evidence. **Signed off 16 September 2026** |

Already closed: the global A1–A8 gates are evidenced, and Gate 1 passes for all three drivers.

## Package 6 — cooperative PvE mode

**Status:** started 16 September 2026. Packages 1–5 are complete and signed (owner direction 16 September 2026: "finalize all gated for 5 and start 6"), and the three operational completion-gate items — the 2/5/10 capacity runs (item 1), the disclosed human pilot (item 3) and the operator feedback file (item 4) — are reclassified by the same direction from blocking this package to running alongside it; the owner provides the humans and the feedback file when the cohort is disclosed. **Deterministic module slices shipped and verified 16 September 2026** (one explicit pass, all gates green: Gate 2 clean, Rector/Pint/PHPStan clean, 777 Pest tests / 2511 assertions, PCOV 100.00% 6335/6335): the campaign board's records (`AiCampaign`, `AiCampaignObjective`, `AiCampaignContribution`) with the open/declare/contribute actions; objective resolution from a committed coalition victory (IMPL-026); the campaign director lifecycle (IMPL-027); the reward allocator's AI-exclusion guard on top of the operation-level contribution dedup (IMPL-028); the cooperative policy registration — the host read-only extension point `OGame\Contracts\HostilityPolicy` + `OGame\Services\HostilityGuard` (enforced at `GameMission::isMissionPossible` for attack/espionage/moon-destruction and at the missile launch path, fail-closed in a `cooperative` universe with no policy) and the module's `CooperativeHostilityPolicy`, which blocks exactly coalition-versus-coalition hostility; 6C operator control and fail-closed admission (IMPL-029); and the 6A consultation lane — transport (IMPL-030), redacted brief (IMPL-032), receipt/budget/caps (IMPL-033) and profile-bounded ranking adjustment (IMPL-034). The 6A lane is off by default and is never wired into a campaign decision point until 6B's measured gain lands. **Remaining:** none in this package — 6B (IMPL-031, driver-signal utilisation) shipped 16 September 2026 as the opt-in affect-appetite contribution (off by default; the 2/5/10 comparison must still show a player-visible gain before any default), with the conformance utilisation record in [`specs/driver-utilisation-conformance.md`](details/specs/driver-utilisation-conformance.md). The host pair is committed — the hostility half (`30b50cef`) and the fleet-ceiling calculation lookup R11 (`d65ada1e`) — and REV-002 re-ran the module gates on the batch: Gate 2 clean, Pint clean, module PHPStan 0 errors, 789 Pest tests / 2535 assertions, PCOV 100.00% 6431/6431. The ordinary-play defects the 16 September mid-day live read found are closed as [Wave 8](details/GAP-REGISTER.md#wave-8--grand-test-live-play-read-16-september-2026) fixes (IMPL-035…040 shipped, IMPL-041 diagnosed) and recorded in [DECISIONS.md](details/DECISIONS.md). **Owner:** one host safety agent and one module campaign agent, merged only as a paired release.

**Goal:** a dedicated cooperative universe lets humans fight an AI faction using normal game systems while human-on-human hostility stays blocked.

**Host scope:** generic `HostilityPolicy` and registry; enforce it at fleet dispatch and attack, espionage counter-battle, missile, moon-destruction and ACS join/invitation paths. In cooperative mode, missing/disabled/erroring module policy must reject human-versus-human hostility.

**Module scope:** campaign, objective and contribution records; campaign director; reward allocator; cooperative policy registration. Use normal accounts, mission actions, combat estimation and committed events. Start with one faction and one objective type.

**Do not modify:** ordinary universe behavior, core battle rules, ships or resources. Do not create an AI-only combat engine.

**Acceptance:** humans cannot attack, counter-spy, missile or join ACS against humans in cooperative mode; both can fight faction; ordinary mode stays unchanged; disabling module remains safe; contributions are idempotent; failed coalition gets a recoverable next objective.

### New Package 6 work items — driver-informed campaign decisions and player divergence

These are additive cooperative-PvE work items. They do not alter Package 5's driver contracts or
its accepted 5A–5F evidence, and they apply only to a cooperative universe/campaign. They must not
change ordinary-universe player decisions.

| Item | Deliverable | Proof before moving on |
| --- | --- | --- |
| **6A — driver-informed critical campaign consultation** | Add an opt-in critical-decision consultation lane beside chat for material unresolved cooperative-campaign decisions: faction fleet loss, repeated campaign setbacks, contested objective, coalition conflict, new campaign phase or major rank change. Use a fresh non-conversational Laravel AI structured-output agent with the existing provider routing, request ledger, SDK failover, usage/failure events and agent fakes. Its bounded request contains only the current permitted campaign facts and commitments; already-generated legal, executable candidates and native scores; profile revision; FAtiMA/CiF typed appraisal, mood, social-volition and protocol-step evidence; CBRKit ranked outcome cases and driver similarity; and AgentOS recalled authorised facts with relevance, stability and provenance. Every external value is typed, attributed to its driver/version and treated as evidence, never as instructions. The agent returns a strict typed recommendation over only supplied candidate IDs plus bounded risk/reason/evidence IDs. It cannot name a new action, alter terms, use a tool, access memory, invoke a sub-agent or execute host work. The module validates candidate IDs, current legality/visibility, obligations, campaign state and source validity, then may apply only a profile-bounded adjustment to a later ranking; native policy still chooses and dispatches the action. | A campaign fixture proves every healthy selected driver field reaches the serialized, redacted advice brief and a matching recommendation affects only an already legal campaign candidate within its permitted profile bound. Missing, invalid, stale or unauthorised driver evidence is absent. Native mode and unavailable drivers make zero provider calls and preserve the same campaign decision. Provider refusal, malformed output, exhausted budget, timeout and failover preserve the immediate native decision; uncertain usage settles once. Laravel AI structured-agent fakes with stray prompts prevented, SDK failure/failover telemetry and one opt-in sanitized real-provider conformance run prove the provider/model path without a custom HTTP client, parser, retry loop, queue lifecycle or model-memory store. |
| **6B — full driver-signal utilisation for cooperative player divergence** | Add one bounded driver-evidence contribution at the existing campaign utility/social decision points, not a new planner or driver manager. Profile traits decide how much their own account reacts to supported evidence: FAtiMA appraisal/mood changes threat and recovery appetite; CiF volition/step changes coalition and social caution; CBRKit outcome similarity changes confidence in a familiar campaign plan; AgentOS relevance/provenance changes which permitted past relationship or obligation weighs. The host continues to supply every object, price, requirement and executable capability; no object list, universal action rule or driver algorithm is hardcoded. A conformance utilisation record lists every verified external-driver field, its source, consumer and observed effect; a field with no safe consumer is recorded as unused with its reason instead of silently discarded. | With identical legal cooperative state and the same external-driver evidence, differently profiled accounts make reproducibly different but professional-player-plausible campaign choices or social stances from the existing legal candidate set; an individual profile remains reproducible under its seed. No driver can promote an unavailable action, override a native refusal, leak another account's fact, bypass an obligation or weaken the cooperative human-versus-human safety boundary. The 2/5/10-account comparison reports per-profile campaign action, social and recovery divergence, latency, RAM, provider attempts and fallback rate against native-only and hybrid baselines; it must show a player-visible gain before any driver or consultation lane becomes a default. |
| **6C — operator control and fail-closed LLM admission** | Keep campaign consultation disabled by default. Give operators explicit `off` / `observe` / `advice` mode, independent universe, campaign and account enablement, staff kill switch, selected provider/model ladder, allowed campaign-event triggers, per-trigger cooldown, maximum advice age, driver-evidence inclusion policy, and hard universe/campaign/account token, attempt, cost and concurrency caps. `observe` may record a validated recommendation but must not alter ranking, campaign state or scheduled work; `off` must not resolve Laravel AI configuration or contact a provider. Turning the staff switch off stops new admission, prevents retries and requires a current-enabled recheck before an already-running result can affect a later decision. Each receipt records reason, trigger, configuration revision, selected driver evidence IDs, provider/model, usage, validation result and whether it changed a ranking; raw prompts, private fact text and hidden reasoning are never retained. | Disabled, cap-refused, campaign-disabled, account-disabled, trigger-disallowed, cooldown, stale, kill-switched and provider-unavailable paths make zero new LLM requests and retain the exact native campaign decision. Observe mode records no decision-score, campaign-state or work-item change. A concurrent-cap test proves no combination of accounts, retries or provider failovers exceeds the configured ledger; a mid-request kill-switch test proves its result is ignored. The operator page and `ai:explain-decision` expose the stop reason and attributable recommendation disposition without exposing private prompt content. |

**Laravel AI boundary for 6A:** use the package's structured agent, provider/model routing,
provider-failover handling, response decoding, usage reporting and test fakes rather than rebuilding
any of them. The module still owns authoritative facts, bounded context construction, budgets,
receipt lifecycle, deterministic validation, campaign state and host-action adapters. SDK tools,
web/file search, MCP, conversational storage, autonomous agent loops and SDK queue/broadcast lifecycle
remain out of scope because they would grant the provider authority or duplicate module safeguards.

## Package 7 — evidence-gated strategic and social evolution

**Status:** planned; blocked by Package 6 being complete, signed off and reviewed with a real
cohort. **Owner:** one module agent per non-overlapping slice; host changes, if a proven generic
gap requires them, remain a separate paired pull request.

**Goal:** improve strategic judgement, long-term recall, diplomacy and cooperative variety only
where measured play shows a player-visible gap. Native policy, canonical facts, persona and the
normal host action paths remain the authority.

**Dependencies:** Package 6 acceptance and a review record that names the observed gap, its
affected accounts and its baseline figure. A proposed capability without that evidence stays
disabled and is not a Package 7 slice.

**Reuse rule:** 7A reuses Package 6's accepted consultation lane, receipt ledger, operator controls
and validation path. It may widen the eligible scope only after its own evidence gate; it does not add
a second Laravel AI adapter, provider client, advice store or decision authority.

**Scope:**

1. **7A — bounded LLM strategic consultation.** After a fleet loss, war declaration, repeated
   attacks, alliance conflict, new colony or major rank change, make at most one separately
   budgeted advisory request only when there is a material unresolved decision. A bounded brief
   may include current permitted facts, existing legal candidates, relevant outcome cases and
   attributed FAtiMA/CiF, CBRKit and AgentOS evidence. Its typed recommendation is validated
   against current visibility, commitments and executable capabilities, then may adjust a later
   candidate ranking. Event deduplication, per-account cooldown, expiry and a separate global
   sub-budget are required. Normal policy responds immediately; provider failure, refusal or
   timeout leaves its decision unchanged.
2. **7B — semantic recall experiment.** Enable hosted embeddings only after a held-out review
   demonstrates native retrieval misses that exact/entity recall and the selected external-memory
   ranking do not close. Keep canonical facts and permission/current-validity checks in the
   module. Projection is bounded asynchronous work, never a session-time or automatic
   summarisation path; no local model runs on the reference VPS.
3. **7C — small-scale PsychSim diplomacy experiment.** Evaluate only the account and one to
   three relevant counterparts at depth one; depth two needs a recorded measured gain and capacity
   evidence. It proposes social stance or coalition evidence, never fleets, resources, promises
   or a replacement player runtime.
4. **7D — Package 6 social life.** Add alliances, first contact and faction social objectives
   through the existing fact, relationship, commitment, permission and host-delivery boundaries.
   Initiation needs a real recipient and game event trigger; alliance terms, departures and aid
   retain exact source-backed terms and expiry. The account's existing persona, obligations and
   human-scale timing decide whether it engages.
5. **7E — further cooperative campaign objectives.** Add one PvE objective type at a time using
   normal accounts, missions, combat estimation and committed outcomes. Each objective declares
   its recoverable failure path, idempotent contribution/reward handling and the hostility-policy
   proof before another type begins.

**Do not build:** LLM gameplay authority, tools or fleet dispatch; periodic reflection; automatic
per-event summaries; AI-to-AI LLM chat; a local embedding model; a generic planner, campaign
framework or second player runtime. Package 7 does not enable sidecars, embeddings, PsychSim or a
provider on the 2 vCPU / 2 GB reference deployment without measured headroom and a recorded gain.

**Acceptance:** every enabled slice has a before/after measured review result and a disabled-path
test proving native provider-off play is unchanged. LLM advice is one bounded, attributable,
non-executable recommendation and cannot delay or override the existing decision. Semantic recall
improves held-out authorised retrieval enough to justify its cost and never surfaces deleted,
stale or unauthorised truth. PsychSim stays within its counterpart/depth/capacity bounds. Social
life increases meaningful reciprocal interaction without breaking persona consistency or exact
commitments. Each added PvE objective preserves ordinary-universe behaviour and the cooperative
human-versus-human safety boundary.

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

Merge packages strictly 1 → 2 → 3 → 4 → 5 → 6 → 7. A package first uses existing OGameX services, models and module extension points. Only a proven missing generic capability may produce a separate host pull request; the next module agent then works only from that merged revision, recorded in its handoff.

The [strategy mining](details/specs/strategy-mining.md) workstream is **not a package**: it is a
research/planning pass over Packages 1–5 that enriches the existing algorithms. Its implementation
slices (wave-6 blocks T6–T8, N4/N5, V6–V8, F1–F6, and the U-series fleet-composition block) merge in
the order 13–18 recorded in
[gameplay-algorithms.md](details/specs/gameplay-algorithms.md#delivery-order), gated per reviewed
cluster by the integration gates in the mining spec — they never precede the package they depend on,
and none is executable until its cluster passes review.
