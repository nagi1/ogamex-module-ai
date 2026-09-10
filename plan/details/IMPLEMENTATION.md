# Implementation map

This is the practical work map for the five roadmap phases. It describes proposed changes after inspecting OGameX Next at host revision `350657a3` and the AI module at `45f8a276`. Names below are planned interfaces, not claims that they already exist. Do not begin a later row until its listed dependencies and acceptance tests pass.

The module repository owns AI policy, AI-only persistence, scheduling, decisions and test scenarios. The host repository owns game rules, actor validation, legal observations and committed game events. The host must not import `Modules\\AI` or contain AI strategy.

## First delivery: one AI queues one building

This is the only implementation scope to begin now. It proves that an AI is an ordinary player account and that retries do not create duplicate orders.

| Repository | Proposed file | Change | Why it exists |
| --- | --- | --- | --- |
| Host | `app/Services/PlayerGameStateService.php` | Add `advance(playerId)`. Move player update, current-planet update and due-fleet processing now coordinated by `app/Http/Middleware/GlobalGame.php` into this service; let the middleware call it. | A scheduled account needs the same game refresh as a web request without faking HTTP or a login. |
| Host | `app/Services/ModulePlayerActionService.php` | Add a typed building-queue entry point. Resolve a fresh player and owned planet, advance state, validate legality and affordability, then delegate to `BuildingQueueService`. Return the created queue identity and outcome. | UI and module share authoritative validation. |
| Host | `app/Services/BuildingQueueService.php` | Move or add ownership and eligibility checks missing from the low-level `add` path. Route the controller through the shared action service. | The module cannot bypass checks a human action receives. |
| Host | `tests/Feature/ModulePlayerActionServiceTest.php` | Cover wrong owner, unavailable resources, impossible prerequisite, legal order and duplicate request identity. | Proves the contract without AI strategy. |
| Module | `database/migrations/*_create_ai_profiles_table.php` | Create one profile per player: `player_id` unique, `archetype`, `skill_band`, `random_seed`, `enabled`, `settings`, timestamps. | Stable personality without duplicating `users`. |
| Module | `database/migrations/*_create_ai_work_items_table.php` | Create scheduled work: `player_id`, `kind`, `due_at`, `payload`, `schedule_generation`, `idempotency_key` unique, `state`, `attempts`, `lease_until`, timestamps; index due state/time. | Safe retries and multiple workers. |
| Module | `database/migrations/*_create_ai_action_receipts_table.php` | Create one receipt per action: `player_id`, `idempotency_key` unique, `action_type`, `state`, `result`, timestamps. | The final duplicate barrier. |
| Module | `app/Models/AiProfile.php`, `AiWorkItem.php`, `AiActionReceipt.php` | Add Eloquent models and relationships. | AI state stays module-local. |
| Module | `app/Jobs/ProcessAiWork.php` | Lease one due item, take a per-player lock, call the host service, write receipt, then complete or make a bounded retry. | A job owns one attempt; it never scans all players. |
| Module | `app/Domain/Decision/BuildFirstBuilding.php` | Make exactly one deterministic building choice from account seed, legal state and archetype. Record reason code and input revision. | Prove execution before strategy. |
| Module | `app/Console/Commands/RunDueAiWork.php` | Dispatch due work in batches; it makes no game decision. | Scheduler entry point. |
| Module | `tests/Feature/ProcessAiWorkTest.php` | Test success, duplicate delivery, lock contention and host validation failure. | Proves retry safety. |

`AiWorkItem` transitions are `pending → leased → completed` or `pending → leased → retry/failed`. A worker may complete only with its matching lease token. Pass the same idempotency key to the host action service. Do not use a language model, browser automation or controller calls in this delivery.

Definition of done: an AI profile chooses an owned planet, creates one legal building queue, repeated delivery creates no second queue, an invalid choice changes no state, and a human request still uses the same validation service.

## Phase 2: deterministic player loop

Phase 2 turns one action into a bounded, believable session loop. Every routine uses game data and seeded randomness; none needs a model call.

| Area | Proposed files | Records | Exact responsibility |
| --- | --- | --- | --- |
| Sessions | `app/Domain/Routine/SessionPlanner.php`, `RoutineProfile.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php` | `ai_schedules`: `player_id` unique, `next_due_at`, `session_ends_at`, `generation`, `last_activity_at`; index `next_due_at`. | Pick a short local-time session from seed/archetype; schedule one next action. No permanent loop or per-minute polling. |
| Legal state | `app/Domain/Perception/PlayerPerceptionBuilder.php`, `PerceptionSnapshot.php` | No snapshot table first. | Build immutable state from host observations and owned state, limited to what the player can see. |
| Choice | `app/Domain/Decision/CandidateActionFactory.php`, `UtilityScorer.php`, `DecisionTrace.php` | `ai_decision_traces`: player, work item, candidates, selected reason, score components, input hash, time; short retention. | Score save resources, build/research/units, fleetsave, spy, raid, colonize or do nothing. |
| Safety | `app/Support/SeededRandomSource.php`, `AiClock.php`, `ActionReceiptStore.php` | Existing work/receipt data. | Replayable variation, test time and idempotency support. |
| Profiles | `app/Domain/Decision/Policies/` | Profile settings. | Miner, Turtle, Fleeter, Trader and Casual differ in targets, cadence, risk and recovery; no hidden power. |

Always offer **do nothing** and **fleetsave** when eligible. Score named components: resource need, energy/blocker need, safety, target confidence, travel cost, recovery, archetype preference and seeded variation. Candidate generation cannot read unobserved target ships, resources or activity.

### Required host extensions

| Proposed file/change | Exact work |
| --- | --- |
| `app/Services/PlayerObservationService.php` | Add actor-scoped reads for owned state, visible galaxy/system data, incoming-fleet intelligence, espionage reports and permitted alliance data. Reuse `GalaxyController` and `IncomingFleetIntelService` logic; controllers remain presentation. |
| `app/Services/FleetDispatchValidationService.php` | Extract `FleetController::dispatchSendFleet` checks: ownership, mission availability, speed, coordinates, hold duration, ACS timing and composition. Controller and `ModulePlayerActionService` call it before `FleetMissionService::createNewFromPlanet`. |
| `ModulePlayerActionService.php` | After the validator exists, add research/unit queues, fleet dispatch, recall and colony creation. Every call includes actor, owned origin and idempotency identity and returns a domain result, never an HTTP response. |
| `app/Services/BattleEstimateService.php` | Add pure estimate adapter taking AI force plus explicitly supplied visible/assumed target snapshot. It may use PHP/Rust battle engines but cannot query live opponent state, write data or emit events. |
| `app/Events/Game/*` | Add committed correlated events: `FleetMissionDispatched`, `EspionageReportCreated`, `BattleReportCreated`, `FleetMissionCompleted`, `PlayerEnteredAlliance`, `PlayerLeftAlliance`. Dispatch after commit with player IDs, report/mission ID, time and event version. |

`BattleResolved` currently fires inside `BattleEngine::simulateBattle`, before mission persistence and report creation. It is not a durable module trigger. Preserve it only as an internal calculation signal if required; the AI subscribes to after-commit `BattleReportCreated`. `FleetMissionArrived` can also run in a transaction, so the module does not treat it as committed memory.

Work order: land Phase 1; add observations and compare them with controller outputs; extract fleet validation; add pure battle estimates; add sessions/perceptions/candidates; then add committed events whose listeners only schedule work. Tests cover a Miner that never attacks, a Fleeter that fleetsaves, rejected raids supported only by stale intel, recovery after loss, reproducible seed/clock traces, and traces that never contain unseen target values.

## Phase 3: relationships, alliances and bounded language

Start with facts from actual game events. Chat is optional and never the source of gameplay memory or actions.

| Proposed file | Table/fields | Work |
| --- | --- | --- |
| `app/Models/AiObservation.php` | `ai_observations`: owner, source type/id, subject, observed time, payload, confidence, expiry; unique owner/source. | Legal, time-bounded observations. |
| `app/Models/AiMemoryFact.php` | `ai_memory_facts`: owner, subject nullable, predicate, value, valid from/to, expiry, source type/id; index owner/subject/expiry. | Compact facts such as raid, active agreement or stale spy report. |
| `app/Models/AiRelationship.php` | `ai_relationships`: owner/other unique, trust, threat, affinity, debt, updated time. | One bounded relationship per meaningful counterpart, never an N×N server matrix. |
| `app/Models/AiCommitment.php` | `ai_commitments`: participants, type, terms, start/end, state, source message/event. | Explicit treaty, trade, ACS or alliance obligation with expiry. |
| `app/Listeners/RecordAiGameEvent.php` | Existing records. | Normalize committed host events into observations, facts, relationship updates and review work. |
| `app/Domain/Decision/RelationshipPolicy.php` | None. | Makes trust/threat/commitment score inputs; cannot bypass rules. |
| `app/Domain/Conversation/ConversationBudget.php`, `ReplyPlanner.php` | `ai_conversation_usage`: universe/date, requests, tokens, cost; `ai_pending_replies`: player, source message, due time, state. | Delayed replies, universe/player budgets and templates when provider fails. |

Native tables are the first memory system and cost zero model tokens to collect/retrieve/score. Facts require source and expiry. A maintenance job compacts expired observations. Do not send complete history to a model.

Only then benchmark Mem0 `infer=false`, Mem0 extracted memory, Zep/Graphiti temporal graphs and Letta-style persistent blocks against sanitized AI conversations and facts. Measure correct recall, stale-fact rejection, context bytes, p95 latency, provider calls and cost per active player. Vendor accuracy/latency comparisons are not an OGameX result. Adopt an adapter only if it beats the native fact baseline and can be disabled without losing core behavior.

Host work is committed chat and alliance lifecycle events. `ChatService` remains the permission authority; the module calls it only after `ReplyPlanner` permits a reply. Test provider outage, budget exhaustion, agreement expiry, duplicate delivery and relationship change from a real event.

## Phase 4: pilot, tools and population control

Use the existing `admin.nav` slot for the first module page: create/disable profile, inspect redacted decision trace and replay a saved synthetic scenario at frozen time. Do not request a general UI extension until a player-facing design needs it.

Add `ExplainAiDecision.php`, `ReplayAiScenario.php` and `SeedAiTestUniverse.php` commands. Replay is read-only. Seeding refuses production by default. Add `ai_population_limits`: universe profile cap, active-session cap, dispatch batch size, action cap/session and language budget. The dispatcher stops at a cap and records why.

Pilot gates: disclosed AI presence, staff kill switch, model-free core play, worker/action success metrics, server-tick latency, cost per active AI and human feedback on pressure, recovery and alliance value. Expand only after a fixed cohort meets limits for a full play cycle.

## Phase 5: cooperative PvE universe

This is a separate universe mode using the same AI accounts, action gateway, perceptions, schedules, combat estimate and event store. It adds no AI-only combat engine, ships or resource rules.

| Proposed file | Table | Responsibility |
| --- | --- | --- |
| `app/Models/AiCampaign.php` | `ai_campaigns`: universe, faction AI player, state, phase, start/end, seed, configuration. | One announced faction campaign. |
| `app/Models/AiCampaignObjective.php` | `ai_campaign_objectives`: campaign, target, type, health/progress, deadline, state, reward configuration. | Observable shared objective. |
| `app/Models/AiCampaignContribution.php` | `ai_campaign_contributions`: campaign, objective, player/alliance, type, value, source event, awarded state; unique source event. | Credits scouting, defense, logistics and combat once. |
| `app/Domain/Campaign/CampaignDirector.php` | Existing schedules/events. | Schedules faction scouting, raids, retreats and escalation through ordinary actions. |
| `app/Domain/Campaign/RewardAllocator.php` | Contribution records. | Uses configured caps/diminishing returns after verified contribution. |

Campaigns begin with advance warning, shared targets, scout/defend/logistics/combat roles, contribution credit and recovery after defeat. These adapt researched browser-game mechanics; they are not claimed to be existing OGame mechanics. Start with one objective and faction, then test fairness and retention.

### Required host safety contract

Add generic `HostilityPolicy` and `ModeHostilityPolicyRegistry`. The host asks the registry before hostile mission creation and resolution. The AI module registers a cooperative policy only for active cooperative universes. It permits human↔faction conflict and blocks human↔human hostility.

Apply the policy in `FleetDispatchValidationService` and hostile/convertible paths: attack, espionage counter-battle, missile, moon destruction and ACS invitation/join. Old missions must remain safe. In cooperative mode, missing, disabled or failing module policy rejects human-human hostility; it never silently reverts to PvP. Ordinary universes are unchanged.

Integration tests: two humans cannot attack, spy-counterattack, missile or ACS each other in cooperative mode; both fight faction normally; ordinary mode remains unchanged; disabling AI keeps human-human hostility blocked; contribution counts once from a committed source event; a failed coalition gets a recoverable next objective.

## Pull-request sequence

1. Host: extract `PlayerGameStateService`; regression-test `GlobalGame`.
2. Host: building-only `ModulePlayerActionService`; route controller through it.
3. Module: profile/work/receipt migrations, first decision and processing job.
4. Host: actor-scoped observations and fleet validation extraction.
5. Host: pure battle estimator and committed/correlated lifecycle events.
6. Module: session planner, perception, candidates and deterministic profiles.
7. Module: fact/relationship/commitment store and event listeners.
8. Module: optional conversation adapter and memory benchmark harness.
9. Module: pilot tools, caps and operational metrics.
10. Host + module: cooperative policy, campaign director and hostile-path tests.

Each PR links its row, changes one repository unless a tested host/module contract needs a paired release, records required host revision, and feature-gates module behavior until its host extension is deployed.

## Raw-plan coverage

The untouched [raw plan](reference/raw-original-plan.md) remains the complete source record. The table below accounts for every numbered topic in it; it does not preserve its duplicated prose or code samples.

| Raw-plan topics | Where the chunked plan carries them |
| --- | --- |
| 1–5: summary, module rationale/direction, core boundary and extension points | Root roadmap decisions; this map’s repository ownership, Package 1 host gateway and Package 2 host extensions. |
| 6–8: AI identity, normal-play identity, target behaviour | Root goal; Package 1 profile; Package 2 profile policies and legal perception. |
| 9–13: routine, sessions, latency, imperfect play, fair information | Package 2 session planner, seeded variability, do-nothing choice, limited observations and trace acceptance tests. |
| 14–18: state/perception, legal actions, utility, skill and personality | Package 1 profile/work records; Package 2 perceptions, action gateway, candidates, scorer and profile policies. |
| 19–22: memory, chat, response timing and limited LLM use | Package 3 facts/relationships/commitments, delayed reply planner, budgets, outage fallback and measured memory-provider evaluation. |
| 23–27: scheduler, AI tick, game progression, queues/locks and event wakeups | Package 1 player-state service, work lease/lock/receipt and scheduler command; Package 2 after-commit event wakeups. |
| 28–31: Rust battle engine, human-like estimates, simulation budget and combat flow | Package 2 pure `BattleEstimateService`, explicit observed/assumed inputs, no-write rule and stale-intel rejection tests. |
| 32–33: fleetsave/defence and normal-mode alliances | Package 2 fleetsave candidate and action validation; Package 3 commitments, relationships and alliance event handling. |
| 34–38: Empire mode, mode comparison, coordination, strategy and relationships | Root separate-mode decision; Package 5 campaign director, objectives, contributions, reward allocation and policy safety. |
| 39–40: scalability and Laravel/Rust division | Package 4 population caps/metrics; Package 2 keeps scheduling/policy in Laravel and battle calculation behind the existing engine adapter. |
| 41–42: testing and determinism | Acceptance tests in every package; seeded randomness, injected clock, read-only replay and frozen-time trace tests. |
| 43–44: suggested order and first MVP | The five merged packages and strict 1→5 integration order; Package 1 is the concrete first MVP. |
| 45: avoid-perfect-bots, hidden knowledge, duplicated rules, unbounded combat, LLM gameplay, separate Empire engine and framework overbuild | Package constraints and acceptance criteria: shared validation, legal observations, bounded estimates, model-free core, same action gateway for Empire mode and narrow host contracts. |
| 46–47: architecture rules and long-term architecture | Repository boundary at the top of this document, host/module contract sequence, feature gating and Package 4 operational controls. |

Before declaring a raw-plan item omitted, add it to this table with a destination and acceptance check. The raw plan itself stays unchanged for audit and comparison.
