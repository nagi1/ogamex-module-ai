# Agent work packages

Assign one package to one module agent. Agents work only in the AI-module worktree. No agent edits this plan while implementing. The coordinator alone updates planning documents after integrating a result.

Every agent begins by reading the assigned package and the named section of [details/IMPLEMENTATION.md](details/IMPLEMENTATION.md). It then checks the current target branch, implements only its allowed scope, runs the named checks, and returns: changed files, commit, checks/results, unresolved risks and the next package now unblocked. It does not start adjacent work because it looks easy.

## Package 1 — safe module bridge

**Status:** complete locally; module foundation remains uncommitted. **Owner:** one module agent.

**Goal:** an AI-controlled existing player queues one building through the exact validated game path; duplicate work cannot queue a second building.

**Dependencies:** none.

**Module scope:** profile/work/receipt migrations and models, `QueueAiBuildingAction`, `ProcessAiWork`, `BuildFirstBuilding`, `RunDueAiWork`, and focused module tests. Use lease token, per-player lock and one idempotency key through the module receipt. No fleets, combat, chat, memory provider or UI.

**Allowed repositories:** `Modules/AI` only. **Do not modify:** host services, controllers, fleet code, events, battle engine, social systems, core module loader or any later package.

**Acceptance:** valid building queues once; duplicate delivery queues once; invalid input writes no game state; no model/provider request.

## Package 2 — legal perception and deterministic sessions

**Status:** complete locally. **Owner:** one module agent.

**Goal:** varied AI accounts make reproducible zero-token choices using only legal information.

**Module scope:** module-owned observation reduction, schedules, session planner, perception snapshot, candidate factory, utility scorer, seeded random source, clock, decision traces and Miner/Turtle/Fleeter/Trader/Casual policies. Always evaluate do-nothing and eligible fleetsave. List score components and source timestamps in traces. Unavailable game actions remain recorded, safe intents.

**Do not modify:** relationships, generated chat, PvE campaign code, population UI or provider adapters.

**Acceptance:** frozen clock + seed reproduce trace; traces expose no hidden target data; an estimate has no writes/events; stale intel blocks risky raid; Miner builds from legal options and never attacks; Turtle queues units/defence and never attacks; Fleeter saves an exposed fleet ahead of a visible raid; Trader colonizes from legal options and never attacks; Casual selects do-nothing when no safe action exists; novice and veteran skill bands select differently within their defined near-equal-choice bounds; recovery input is bounded and visible in the recorded score. These are dedicated feature scenarios, not only unit or line-coverage assertions.

**Test-fixture rule:** use real OGameX services, models and validation paths. Mockery is prohibited. A narrow container override is permitted only to verify an explicitly replaceable package/action boundary, never as a substitute for testing production mechanics.

**Container rule:** actions, jobs, services, policies and collaborators are resolved through `app()` or `app()->makeWith()` in module code and tests. Direct `new` is limited to plain value objects and deliberately non-container data.

**Pest rule:** all AI-module tests use native Pest 5 syntax and named datasets for repeated mechanics. Run PCOV with PAO and `--tia`; never enable Xdebug for this suite. Pest dependencies remain module-local. PHPUnit-style test classes/assertions and Mockery are prohibited.

## Package 3 — facts, relationships and optional conversation

**Status:** blocked by Package 2's future event-consumption decision. **Owner:** one module agent. Reuse events already published by OGameX; a host change is considered only if a generic, independently useful event is truly absent.

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

Merge packages strictly 1 → 2 → 3 → 4 → 5. A package first uses existing OGameX services, models and module extension points. Only a proven missing generic capability may produce a separate host pull request; the next module agent then works only from that merged revision, recorded in its handoff.
