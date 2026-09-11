# Phase 3 current-state assessment

Inspected 11 September 2026: AI module `e1c48a2`, OGameX host `02e23c13`. This assessment describes code read for the planning revision; it does not claim a new test run. The earlier [repository inspection](repository-inspection.md) remains a dated historical record.

## Implemented starting point

| Area | Actual implementation | Phase 3 implication |
| --- | --- | --- |
| Module ownership | Independent `Modules/AI` Git checkout, nWidart provider/resource discovery, module-local config/routes/migrations/tests | All cognitive state, actions, contracts and adapters stay here. No framework extraction or AI host service. |
| Scheduling | `RunDueAiWork`, `ProcessAiWork`, `AiWorkItem`, `AiSchedule`, `SessionPlanner`, `NextDueTimeCalculator`, `SessionDecisionService` | Actual fields are work `due_at` and schedule `next_due_at`/generation, not a new `next_action_at` system. Reuse concepts without inserting network work into the gameplay lock. |
| Work identity | Work state/lease token, per-player cache lock, idempotency keys and action receipts | Existing retry patterns are useful, but do not prove exactly-once network generation or message delivery. Phase 3 requires dedicated race/crash scenarios. |
| Decisions | `CandidateActionFactory`, `UtilityScorer`, `DecisionEngine`, reasoned `DecisionTrace` | Existing utility competence stays authoritative. Add bounded accepted evidence/intent inputs only with a concrete scenario and regression tests. |
| Persona | `AiProfile`, `AiArchetype`, `AiSkillBand`, five registered archetype policies, seed and `AiProfileSettings` | Miner, Turtle, Fleeter, Trader and Casual already differ. There is no independent HEXACO/OCC personality engine; new cognition projects the one canonical module profile. |
| Perception | `PlayerObservationService`, `PlayerPerceptionBuilder`, `PerceptionSnapshot` | Owned-state reduction and explicitly supplied legal inputs remain the information boundary. No driver receives unrestricted game models. |
| Gameplay execution | `QueueAiBuildingAction` uses `PlayerGameStateService`, `PlanetServiceFactory` and `BuildingQueueService`; first-building work has a normal validated path | This is a real building adapter. Do not describe every candidate type as an executable action. |
| Session scope | `RunAiSessionAction` / `SessionDecisionService` record a decision and schedule the successor | Session choices are recorded intents. Fleet saves, research, colonies, raids or trade are not proven executed merely by selecting their candidate. Phase 3 outcome cases require actual correlated host operations. |
| Existing contracts | `QueueAiBuilding`, `RunAiSession`, `BuildingScoringPolicy`, `ArchetypePolicy`, `AiClock`, `RandomSource`; provider bindings and policy tags | Extend the current Laravel seam. No external driver manager is necessary. |
| Persistence | Five module tables: profiles, work items, action receipts, schedules, decision traces | Memory facts, social state, commitments, cognition, CBR cases and conversations need new module migrations. |
| Goals/planning | Named utility components and profile/routine/recovery inputs | No durable goal graph, GOAP runtime or learned strategy engine exists. Use bounded goal state only where social/appraisal behavior needs it. |
| Memory/relationships | `Domain/Memory` and `Listeners` contain placeholders | The structured-memory design is still planned. Do not assume a fully populated memory baseline already exists. |
| Conversation / AI-to-AI | No module conversation pipeline, social-exchange implementation or language adapter | Phase 3 adds these; the host's human chat services are integration inputs, not completed AI chat. |
| Configuration | `config/config.php` currently declares module name | Driver choices, feature flags, scope, budgets and sidecar endpoints are proposed module configuration, not deployed settings. |
| Tests | Native Pest unit and feature files for work, sessions, persona mechanics, decisions and routines; module Pest/TIA, PCOV/PAO workflow | Preserve these tests and add real social/memory/delivery paths. Existing tests do not establish future cognition quality or driver capacity. |

Relevant test files include `tests/Feature/ProcessAiWorkTest.php`, `ProcessAiSessionTest.php`, `DeterministicSessionLoopTest.php`, `PersonaPolicyMechanicsTest.php`, `RunDueAiWorkTest.php` and the decision/routine unit suites. The previous handoff recorded 35 tests and 141 assertions; that historical run is not a Phase 3 test result.

## Existing host extension points and important gaps

Reuse nWidart discovery, `AIServiceProvider` registration, Laravel bindings/listeners/observers, module-owned migrations, existing metadata for small pointers and `admin.nav` when operator UI is eventually needed. Read the host's `docs/modules.md` before implementation. High-volume AI memory is not host metadata.

`OGame\Services\ChatService` has direct/alliance send methods, but `ChatController` performs some validation outside those methods: recipient/self checks, nonblank and maximum message length, ignore policy and alliance membership. `canMessagePlayer` is a separate host method. A module delivery action must recheck the same policy before using normal message persistence. The plan must not call the send service a complete permission gateway.

The inspected controller does not establish reply-to conversation scope/visibility validation. Phase 3 adds that explicit module ACL guard; it is not an existing host check to assume or merely cite.

`ChatMessageSent` implements broadcasting, without an intrinsic after-commit delivery contract. `ChatMessage` uses soft deletion and reply/alliance/recipient pointers; the host does not establish per-member historical alliance-read tracking. Module ingestion/retrieval must use current and historical visibility rules explicitly rather than treating the event stream as public or omniscient.

Host `Message` records identify the receiving player and associated espionage/battle reports. A committed report delivered to the AI is a more appropriate factual source than a battle-calculation event. `BattleResolved` lacks sufficient operation/report correlation; `FleetMissionArrived` likewise does not prove transaction durability. Neither is automatically a durable Phase 3 reducer trigger.

Alliance services already have actor-aware validation and transactions, but the inspection found no complete alliance-lifecycle event stream. First evaluate module-owned after-commit observation plus bounded reconciliation. A generic host hook is a separately justified fallback only when a concrete path cannot be covered. Do not add speculative chat, alliance or cognition APIs to OGameX.

No established universe table was found. Use a configured deployment/universe namespace in module records until an actual host concept exists. Match ID types before adding foreign keys: host user IDs and chat/alliance IDs differ. Do not edit previously merged migrations or silently assume a `foreignId` matches every parent.

## Corrections to the conversation and old plan

- Some conversation graphs put FAtiMA, CiF and CBR into Phases 1–2. None exists in this checkout; they are new Phase 3 candidates/capabilities.
- The old roadmap still said scaffold-only/Package 1 and described an AI-specific host building gateway. Current module code owns the building adapter; the roadmap is updated to this actual boundary.
- The conversation sometimes treated every model-proposed intent or relationship delta as ready to apply. All are proposals; native policy validates them, and no driver owns canonical truth.
- The old active budget spec prohibited strategic calls while other documents allowed rare advice. The revised baseline has none; a named later experiment may enable bounded major-event advice explicitly.
- Earlier guidance described an interpretation LLM followed by a realization LLM. The accepted one-request rule requires combined output and deterministic post-validation, with authored fallback if the result is unsuitable.
- Earlier advice said batching only meant foreground coalescing. The full conversation also explored idle/end-of-conversation processing and provider Batch APIs. The revised plan distinguishes deterministic episode processing from disabled, experimental generative batches.
- External library compatibility, maintenance, APIs, licenses and capacity claims in the chat were not verified by this repository. A focused pinned-driver spike is needed; the architecture cannot depend on those claims being true.

## Plan changes authorized by this revision

Document new module capabilities, contracts, data ownership, real-host acceptance tests and driver experiments. Do not implement PHP, migrations, vendor installation, sidecars or a general framework in this revision. Phase 3 implementation starts from the [milestones](../specs/phase-3-cognition.md#delivery-slices-and-completion-evidence) after planning review, with actual source/permission prerequisites resolved in the first slice.
