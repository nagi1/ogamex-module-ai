# Implementation map

This is the practical work map for the five roadmap phases. The original map used host `350657a3` / module `45f8a276`; the Phase 3 revision uses the [actual baseline](research/phase-3-current-state.md), host `02e23c13` / module `e1c48a2`. Phase 1/2 rows retain delivery context; new Phase 3 names are proposed, not implemented. The current task is planning only.

The module repository owns AI policy, AI-only persistence, scheduling, decisions, legal-state reduction and test scenarios. The host repository owns ordinary game rules and services only. The host must not import `Modules\\AI`, contain AI strategy, or add AI-specific gateways, observations or events.

## First delivery: one AI queues one building

This foundation is already present. It establishes the ordinary-account building path and retry tests; do not redo it when starting Phase 3.

| Repository | Proposed file | Change | Why it exists |
| --- | --- | --- | --- |
| Existing host dependency | `app/Services/PlayerGameStateService.php` | Reuse the existing shared refresh operation through the module adapter. | A scheduled account needs normal game refresh without faking HTTP or a login. No new AI-specific host gateway. |
| Module | `app/Actions/QueueAiBuildingAction.php`, `Contracts/QueueAiBuilding.php` | Resolve a fresh actor, reduce its owned state, and delegate to the existing `BuildingQueueService`. Return a module-owned typed result. | AI behavior and its adapter stay in the module; game rules remain in OGameX. |
| Module | `database/migrations/*_create_ai_profiles_table.php` | Create one profile per player: `player_id` unique, `archetype`, `skill_band`, `random_seed`, `enabled`, `settings`, timestamps. | Stable personality without duplicating `users`. |
| Module | `database/migrations/*_create_ai_work_items_table.php` | Create scheduled work: `player_id`, `kind`, `due_at`, `payload`, `schedule_generation`, `idempotency_key` unique, `state`, `attempts`, `lease_until`, timestamps; index due state/time. | Safe retries and multiple workers. |
| Module | `database/migrations/*_create_ai_action_receipts_table.php` | Create one receipt per action: `player_id`, `idempotency_key` unique, `action_type`, `state`, `result`, timestamps. | The final duplicate barrier. |
| Module | `app/Models/AiProfile.php`, `AiWorkItem.php`, `AiActionReceipt.php` | Add Eloquent models and relationships. | AI state stays module-local. |
| Module | `app/Jobs/ProcessAiWork.php` | Lease one due item, take a per-player lock, resolve its action through `app(QueueAiBuilding::class)`, write receipt, then complete or make a bounded retry. | A job owns one attempt; it never scans all players. |
| Module | `app/Domain/Decision/BuildFirstBuilding.php` | Make exactly one deterministic building choice from account seed, legal state and archetype. Record reason code and input revision. | Prove execution before strategy. |
| Module | `app/Console/Commands/RunDueAiWork.php` | Dispatch due work in batches; it makes no game decision. | Scheduler entry point. |
| Module | `tests/Feature/ProcessAiWorkTest.php` | Test success, duplicate delivery, lock contention and host validation failure. | Proves retry safety. |

`AiWorkItem` transitions are `pending → leased → completed` or `pending → leased → retry/failed`. A worker may complete only with its matching lease token. The module receipt is the idempotency barrier. Do not use a language model, browser automation or controller calls in this delivery.

Definition of done: an AI profile chooses an owned planet, creates one legal building queue through the existing queue service, repeated delivery creates no second queue, and an invalid choice changes no state.

## Phase 2: deterministic player loop

Phase 2 turns one action into a bounded, believable session loop. Every routine uses game data and seeded randomness; none needs a model call.

| Area | Proposed files | Records | Exact responsibility |
| --- | --- | --- | --- |
| Sessions | `app/Domain/Routine/SessionPlanner.php`, `RoutineProfile.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php` | `ai_schedules`: `player_id` unique, `next_due_at`, `session_ends_at`, `generation`, `last_activity_at`; index `next_due_at`. | Pick a short local-time session from seed/archetype; schedule one next action. No permanent loop or per-minute polling. |
| Legal state | `app/Domain/Perception/PlayerObservationService.php`, `PlayerPerceptionBuilder.php`, `PerceptionSnapshot.php` | No snapshot table first. | Build immutable state from module-owned reduction of owned state and explicitly delivered inputs only. |
| Choice | `app/Domain/Decision/CandidateActionFactory.php`, `UtilityScorer.php`, `DecisionTrace.php` | `ai_decision_traces`: player, work item, candidates, selected reason, score components, input hash, time; short retention. | Score save resources, build/research/units, fleetsave, spy, raid, colonize or do nothing. |
| Safety | `app/Support/SeededRandomSource.php`, `AiClock.php`, `ActionReceiptStore.php` | Existing work/receipt data. | Replayable variation, test time and idempotency support. |
| Profiles | `app/Domain/Decision/Policies/` | Profile settings. | Miner, Turtle, Fleeter, Trader and Casual differ in targets, cadence, risk and recovery; no hidden power. |

Always offer **do nothing** and **fleetsave** when eligible. Score named components: resource need, energy/blocker need, safety, target confidence, travel cost, recovery, archetype preference and seeded variation. Candidate generation cannot read unobserved target ships, resources or activity.

### Module-owned integration policy

The module may call existing OGameX services and models only as a narrow adapter for an actor's own state or an existing validated game action. It must not require changes to controllers, fleet validation, battle engines, events, schemas or host services. Missing capabilities are record-only candidate intents and are never executed. `BattleResolved` and `FleetMissionArrived` are not durable module triggers.

Work order: land the module action adapter; add owned-state reduction; add sessions/perceptions/candidates; retain unavailable capabilities as traceable no-ops. Tests cover a Miner that never attacks, a Fleeter that selects a fleetsave intent, rejected stale intel, reproducible seed/clock traces, and traces that never contain unseen target values.

## Phase 3: social cognition, experience and bounded language

The [detailed architecture](specs/phase-3-cognition.md) is the canonical Phase 3 design, with minimal contracts, eight end-to-end flows, failure behavior and PR-sized milestones 3A–3J. Use [memory/language](specs/memory-and-language.md) for persistence, source trust, context and delivery; [validation](specs/validation.md) for feature cases. The implementation map below ties those responsibilities to the current module structure.

| Proposed module area/files | Records | Responsibility |
| --- | --- | --- |
| `app/Models/AiObservation.php`, `AiMemoryFact.php`; new migrations | `ai_observations`, `ai_memory_facts`: scope/owner/source identity, time, evidence kind, attributed speaker, validity and revision | Committed legal observations and explicit fact/claim distinction. Verify ID types; do not invent a host universe table. |
| `AiRelationship.php`, `AiCommitment.php`; `Domain/Memory` | Sparse relationships and exact proposed/accepted obligations with source/fulfillment references | Native policy owns trust and commitment transitions. No driver or generated reply can fulfill a transport. |
| `Listeners` / `Actions/RecordObservedGameEventAction.php` | Source cursors, deduplication and optional projection outbox | After-commit observation plus bounded legal reconciliation; calculation/broadcast events alone are not durable facts. |
| `Contracts/AffectEngine.php`; `Actions/AppraiseObservedEventAction.php`; `Domain/Cognition` | Versioned affect/goals and significant emotional episodes | Native goal-aware appraisal; external FAtiMA is a later tested implementation of the same boundary. |
| `Contracts/SocialCognition.php`; `Actions/EvaluateSocialExchangeAction.php`; `Domain/Conversation` | Exchange participants, typed terms, step, expiry and outcome | Native CiF-style protocols, authored dialogue and bounded AI-to-AI exchanges. |
| `Contracts/ExperienceEngine.php`; `Actions/RecordExperienceOutcomeAction.php`, `ExtractOGameExperienceFeaturesAction.php` | Native pending/final cases with feature/ruleset versions and actual receipt/report outcome | Deterministic case ranking via native implementation or optional CBRKit. Never learn a successful mission from a record-only intent. |
| `Actions/MapObservedGameEventToStimulusAction.php`, `ResolveCognitiveIntentAction.php`; existing policy integration | Typed snapshots, accepted intentions and reasoned traces | Translate OGame observations to generic cognition inputs and back to existing policy/capability paths. No universal planner or new execution engine. |
| `Contracts/LongTermMemory.php`, `ContextBuilder.php`; `Domain/Memory` / `Domain/Conversation` | Native selected source IDs, context/ACL/term revisions | Exact/native recall and deterministic context budget; optional advanced memory returns revalidated projections. |
| `Contracts/LanguageGateway.php`; `Support` provider adapter and disabled implementation | Request ID, response envelope, usage and failure result | One foreground generation containing text and optional proposals; no tool access or separate extractor. |
| `Actions/PlanAiReplyAction.php`, `ValidateAiReplyProposalsAction.php`, `DeliverAiReplyAction.php`; short conversation jobs | Pending reply generation, source range, due/expiry, budget reservation and delivery receipt/core chat ID | Coalescing, route selection, validation, host-equivalent send permissions and crash/idempotency handling outside gameplay locks. |
| `AIServiceProvider`, module config and `tests/Feature` / `tests/Unit` | Driver/capability configuration, versioned fixtures and experiment artifacts | Register only used contracts, native/null defaults, real adapter conformance and behavioral/ablation scenarios. |

Create only records and actions needed by the current milestone; table names are proposals, not permission to create a universal cognition schema. Use new module migrations and explicit indexes/constraints. Canonical persona, obligations and case outcomes survive driver changes. Runtime configuration, native implementation and a narrowly justified null path come before optional sidecars.

Test FAtiMA/CiF and CBRKit only after their native boundaries have real scenarios. AgentOS memory-only, PsychSim, embeddings and ML compression are optional later experiments, not required framework components. Mem0 is rejected. [Driver evaluation](research/memory-comparison.md) defines candidate status, Linux/version verification, bounded ablations and measured adoption gates.

Reuse existing actor-scoped host records and module extension points first. `ChatService` send methods do not enforce every controller permission rule; the module delivery action must apply host-equivalent recipient/ignore/membership/message checks before sending and after generation, plus an explicit module reply-to scope/visibility guard absent from the inspected controller. Add a separate generic host hook only when the first milestone proves an actual durability/visibility gap. No mandatory host chat/alliance rewrite is authorized.

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

1. Module: profile/work/receipt migrations, module-owned queue action, first decision and processing job.
2. Module: session planner, perception reduction, candidates, traces and deterministic profiles.
3. Module: Phase 3 milestones 3A–3G, one concern per PR: observed sources, native facts/obligations, affect, social protocols, experience, authored delivery and context/budgets.
4. Module: 3H optional language, separate 3I driver spikes and 3J evidence/acceptance; do not bundle sidecar adoption with the baseline.
5. Module: pilot tools, caps and operational metrics.
6. Host + module: only if a future cooperative mode cannot use an existing generic boundary, add a narrow generic safety policy and pair it with the campaign director tests.

Each PR links its row and changes the AI repository by default. A paired host release is allowed only for a missing generic capability that is independently useful without AI; module-specific orchestration, observations, actions and records remain in `Modules/AI`.

## Raw-plan coverage

The untouched [raw plan](reference/raw-original-plan.md) remains the complete source record. The table below accounts for every numbered topic in it; it does not preserve its duplicated prose or code samples.

| Raw-plan topics | Where the chunked plan carries them |
| --- | --- |
| 1–5: summary, module rationale/direction, core boundary and extension points | Root roadmap decisions; this map’s module-first repository ownership and the existing generic OGameX services used by Packages 1–2. |
| 6–8: AI identity, normal-play identity, target behaviour | Root goal; Package 1 profile; Package 2 profile policies and legal perception. |
| 9–13: routine, sessions, latency, imperfect play, fair information | Package 2 session planner, seeded variability, do-nothing choice, limited observations and trace acceptance tests. |
| 14–18: state/perception, legal actions, utility, skill and personality | Package 1 profile/work records; Package 2 perceptions, action gateway, candidates, scorer and profile policies. |
| 19–22: memory, chat, response timing and limited LLM use | Package 3 cognition/experience/claims/commitments, authored social escalation, delayed/coalesced replies, one-request proposals, budgets and measured optional drivers. Rare advice/deferred enrichment are explicit disabled later experiments. |
| 23–27: scheduler, AI tick, game progression, queues/locks and event wakeups | Package 1 work lease/lock/receipt and scheduler command; Package 2 schedules and safe record-only intents. Future event wakeups consume only existing published events unless a generic host capability is separately justified. |
| 28–31: Rust battle engine, human-like estimates, simulation budget and combat flow | Package 2 rejects stale intel and retains safe recorded intents. Pure estimation and autonomous combat execution are deferred capabilities; no implemented `BattleEstimateService` is claimed. |
| 32–33: fleetsave/defence and normal-mode alliances | Package 2 fleetsave candidate and action validation; Package 3 commitments, relationships and alliance event handling. |
| 34–38: Empire mode, mode comparison, coordination, strategy and relationships | Root separate-mode decision; Package 5 campaign director, objectives, contributions, reward allocation and policy safety. |
| 39–40: scalability and Laravel/Rust division | Package 4 population caps/metrics; Package 2 keeps scheduling/policy in Laravel and battle calculation behind the existing engine adapter. |
| 41–42: testing and determinism | Acceptance tests in every package; seeded randomness, injected clock, read-only replay and frozen-time trace tests. |
| 43–44: suggested order and first MVP | The five merged packages and strict 1→5 integration order; Package 1 is the concrete first MVP. |
| 45: avoid-perfect-bots, hidden knowledge, duplicated rules, unbounded combat, LLM gameplay, separate Empire engine and framework overbuild | Package constraints and acceptance criteria: shared validation through existing services, legal observations, bounded estimates, model-free core and module-owned action adapters. |
| 46–47: architecture rules and long-term architecture | Repository boundary at the top of this document, module-first action/adapters and Package 4 operational controls. |

Before declaring a raw-plan item omitted, add it to this table with a destination and acceptance check. The raw plan itself stays unchanged for audit and comparison.
