# Agent work packages

Assign one package to one agent. Agents work in separate Git worktrees for the repository they change. A host-package agent uses a host worktree; a module-package agent uses an AI-module worktree. No agent edits this plan while implementing. The coordinator alone updates planning documents after integrating a result.

Every agent begins by reading the assigned package and the named section of [details/IMPLEMENTATION.md](details/IMPLEMENTATION.md). It then checks the current target branch, implements only its allowed scope, runs the named checks, and returns: changed files, commit, checks/results, unresolved risks and the next package now unblocked. It does not start adjacent work because it looks easy.

## Package 1 — safe module bridge

**Status:** complete locally; host bridge and module foundation remain
uncommitted. **Owner:** one host agent, then one module agent after the host
contract merges.

**Goal:** an AI-controlled existing player queues one building through the exact validated game path; duplicate work cannot queue a second building.

**Dependencies:** none for the host slice. The module slice depends on the merged host action contract.

**Host scope:** only `GlobalGame`, a new `PlayerGameStateService`, a building-only `ModulePlayerActionService`, `BuildingQueueService`, the related controller delegation and focused host tests. Extract player refresh from middleware. Resolve fresh actor and owned planet. Put ownership, eligibility, resource and prerequisite validation on the shared path. Return a domain result, not an HTTP response.

**Module scope after host merge:** only profile/work/receipt migrations and models, `ProcessAiWork`, `BuildFirstBuilding`, `RunDueAiWork`, and focused module tests. Use lease token, per-player lock and one idempotency key from work item through action receipt to host call. No fleets, combat, chat, memory provider or UI.

**Allowed repositories:** host for host slice; `Modules/AI` only for module slice. **Do not modify:** fleet code, events, battle engine, social systems, core module loader or any later package.

**Acceptance:** valid building queues once; duplicate delivery queues once; invalid input writes no game state; human build action still works through the shared path; no model/provider request.

## Package 2 — legal perception and deterministic sessions

**Status:** blocked by Package 1. **Owner:** one host agent for contracts, one module agent after those contracts merge.

**Goal:** varied AI accounts make reproducible zero-token choices using only legal information.

**Host scope:** `PlayerObservationService`; extraction of fleet validation from `FleetController` into `FleetDispatchValidationService`; action-gateway additions for research, units, fleet dispatch, recall and colonization; a pure `BattleEstimateService`; after-commit correlated lifecycle events. Preserve existing controllers and mission behavior. Do not let AI subscribe to existing in-transaction `BattleResolved` or `FleetMissionArrived` as durable outcomes.

**Module scope:** schedules, session planner, perception snapshot, candidate factory, utility scorer, seeded random source, clock, decision traces and Miner/Turtle/Fleeter/Trader/Casual policies. Always evaluate do-nothing and eligible fleetsave. List score components and source timestamps in traces.

**Do not modify:** relationships, generated chat, PvE campaign code, population UI or provider adapters.

**Acceptance:** frozen clock + seed reproduce trace; traces expose no hidden target data; an estimate has no writes/events; stale intel blocks risky raid; Miner does not attack; Fleeter saves exposed fleet; loss leads to bounded recovery.

## Package 3 — facts, relationships and optional conversation

**Status:** blocked by Package 2 committed events. **Owner:** one module agent; host event additions, if missing, are a separate narrowly scoped host pull request.

**Goal:** accounts remember meaningful, expiring facts and agreements; conversation remains optional and budgeted.

**Scope:** observations, facts, relationship and commitment migrations/models; reducer listener for committed events; relationship policy; compacting expired data; reply planner, usage budget and provider adapter boundary; a native-memory benchmark harness.

**Do not modify:** deterministic core decision loop, game action validation, battle engine or PvE rules. Do not select or install Mem0, Zep, Graphiti or Letta based on marketing claims.

**Acceptance:** duplicate event produces one fact; expired agreement changes choice; provider outage and exhausted budget still leave normal gameplay working; benchmark compares native facts first on recall, stale rejection, context bytes, latency and cost.

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

Merge packages strictly 1 → 2 → 3 → 4 → 5. A package that needs an unmerged host contract produces its contract pull request and stops. The next module agent works only from the merged contract revision, recorded in its handoff.
