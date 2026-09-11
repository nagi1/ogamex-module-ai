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

**Status:** slices 3A–3H implemented and committed; 3I driver experiments and 3J acceptance/measurement remain. Package 2 is the existing baseline. Source durability, schema and chat permissions are resolved by the first Phase 3 slice, not an indefinite dependency on a future Phase 2 rewrite. **Owner:** one module agent per non-overlapping milestone, integrated in order.

**Goal:** believable, persistent social behavior and outcome-based learning with no generative calls for ordinary gameplay; authored dialogue handles known exchanges and optional language handles unrestricted human conversation.

**Required reading:** [actual Phase 2 state](details/research/phase-3-current-state.md), [Phase 3 architecture and milestones](details/specs/phase-3-cognition.md), [memory/language](details/specs/memory-and-language.md), [Laravel AI SDK integration](details/specs/laravel-ai-sdk.md) for 3H, [budgets](details/specs/budgets.md), [validation](details/specs/validation.md). Read [driver evaluation](details/research/memory-comparison.md) only for a driver/benchmark milestone.

**Scope:** native facts/claims/relationships/commitments, affect and goal pressure, significant emotional episodes, structured social protocols/authored dialogue, real outcome cases and CBR, coalesced replies, context selection, atomic budgets, safe normal-host delivery, six concrete module contract seams and repeatable experiments. New optional external adapters are separate focused slices after their native boundaries work.

**Integration:** reuse existing module discovery, provider bindings and actor-scoped services/records. Candidate scoring may consume bounded accepted cognitive/experience inputs when a scenario requires them; do not replace the scheduler/utility engine. Validate source durability and human-equivalent chat permission rules in module actions. Consider a separate generic host hook only for a demonstrated gap.

**Do not build:** a framework/package split, generic game planner, mandatory sidecars, automatic reflection/extraction, per-event summaries, AI-to-AI LLM chat, a new fleet/combat engine or mandatory semantic/vector storage. Track progress in the [delivery slices](details/specs/phase-3-cognition.md#delivery-slices-and-completion-evidence) and keep each change inside its milestone. Mem0 is rejected; FAtiMA, CBRKit, AgentOS and PsychSim remain candidates behind boundaries.

**Milestones:** 3A sources; 3B facts/obligations; 3C native affect; 3D social protocols; 3E experience; 3F authored delivery; 3G context/budgets; 3H optional language; 3I separate driver experiments; 3J acceptance/measurement. Each has a testable outcome in the [implementation sequence](details/specs/phase-3-cognition.md#delivery-slices-and-completion-evidence). Optional experiments do not make every sidecar a release dependency.

**Acceptance:** the eight specified flows and behavioral matrix pass using real module/host paths; no leaked/stale truth, duplicate side effects or unapproved commitments; known social exchanges work with no LLM; one bounded foreground generation includes any proposals; provider-off gameplay continues; driver replacement preserves module-owned persona/obligations; benchmark reports actual behavior, prompt/token use, latency, CPU/RAM and failures rather than claiming unmeasured capacity.

**Engineering rules:** Nagi instructions apply: descriptive action methods, no forwarding-only wrappers, no `else` branches, enums for defined values, Laravel `app()` bindings, native Pest 5 datasets, PAO, PCOV and TIA. No Mockery or Xdebug. Tests cover behavior/edges/branches in addition to changed-area line coverage; real adapter integration and opt-in provider quality experiments are distinct.

## Package 4 — operability and disclosed pilot

**Status:** blocked by Package 3. **Owner:** one module agent.

**Goal:** operators can safely inspect, replay, cap and disable AI before increasing population.

**Scope:** existing `admin.nav` page only; profile enable/disable; redacted decision trace; read-only replay; production-refusing synthetic seeding; population/session/action/language caps; stop reasons and metrics.

**Do not modify:** host UI extension system, game mechanics, action contracts or PvE behavior.

**Acceptance:** staff kill switch stops new work; replay writes nothing; production seed refusal is tested; dispatcher respects every cap; pilot report contains action success, worker failure, tick latency, cost and human feedback.

## Package 5 — cooperative PvE mode

**Status:** blocked by Packages 1–4. **Owner:** one host safety agent and one module campaign agent, merged only as a paired release.

**Goal:** a dedicated cooperative universe lets humans fight an AI faction using normal game systems while human-on-human hostility stays blocked.

**Host scope:** generic `HostilityPolicy` and registry; enforce it at fleet dispatch and attack, espionage counter-battle, missile, moon-destruction and ACS join/invitation paths. In cooperative mode, missing/disabled/erroring module policy must reject human-versus-human hostility.

**Module scope:** campaign, objective and contribution records; campaign director; reward allocator; cooperative policy registration. Use normal accounts, mission actions, combat estimation and committed events. Start with one faction and one objective type.

**Do not modify:** ordinary universe behavior, core battle rules, ships or resources. Do not create an AI-only combat engine.

**Acceptance:** humans cannot attack, counter-spy, missile or join ACS against humans in cooperative mode; both can fight faction; ordinary mode stays unchanged; disabling module remains safe; contributions are idempotent; failed coalition gets a recoverable next objective.

## Integration order

Merge packages strictly 1 → 2 → 3 → 4 → 5. A package first uses existing OGameX services, models and module extension points. Only a proven missing generic capability may produce a separate host pull request; the next module agent then works only from that merged revision, recorded in its handoff.
