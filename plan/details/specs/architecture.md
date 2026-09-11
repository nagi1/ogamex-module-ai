# Architecture within the existing module

Owner: AI module. Current evidence: [Phase 3 assessment](../research/phase-3-current-state.md). Possible generic gaps, not mandatory host work: [extension assessment](module-extension-points.md). Detailed cognition: [Phase 3](phase-3-cognition.md).

## Preserve the installed structure

The existing AI provider registers module resources through nWidart. Keep its alias, route/config/view namespaces and module-local migrations/tests. Extend the existing Domain areas for Perception, Decision, Routine, Scheduling and Memory. Jobs perform short work; Listeners ingest permitted notifications; Support contains adapters and budgets. Add the PvE coordinator within the same module when Phase 5 starts.

The module is an independent ordinary Git checkout, not a submodule and not host-owned code. Generic events, action validation and view slots belong to separate host changes. No AI-specific conditionals spread through core gameplay.

## Decision flow

Core advances authoritative state. A module adapter obtains the account's permitted observations. Policies generate and score a bounded set of candidates. Executable intents use the normal core action path; current session candidates otherwise remain recorded intents. Phase 3 reducers will update memory only from actual correlated committed outcomes, never from a selected but unexecuted action.

Observation, cost/time quotation, execution, simulation and memory are separate responsibilities, not a new generic agent framework. Policies receive values rather than unrestricted game models. Include observation age, source and ruleset version; unknown enemy data stays unknown.

An intent records actor, action, relevant parameters, observation version, expiry and a stable decision identifier. Revalidate before execution. Explanations record feature scores and rejection reasons, never secret state.

## Runtime and persistence

Use an indexed module-owned due-work table with schedule generation, retry count and leases. Claim bounded batches atomically; stale jobs become no-ops. Serialize state-sensitive work per account. A stable operation receipt closes the retry gap: when execution status is uncertain, reconcile the existing core operation before retrying.

Use existing player/planet module metadata for small identity pointers. Put schedules, indexed histories, commitments and campaign state in module-owned tables. Metadata namespacing prevents accidental collisions; it is not a security boundary between PHP modules.

Separate decisions, simulation and optional language queues. Never hold a gameplay lock during network generation. Recheck current module/mode status in long-lived workers. Refresh actor-specific services between jobs to prevent one account's cached state reaching another.

## Events and game progression

Six game events already exist, but their presence does not prove after-commit delivery. The existing battle event occurs inside calculation and lacks mission/report correlation. First use module-owned after-commit observation and bounded reconciliation of owner-delivered records. A separate generic host hook is considered only for a demonstrated gap; event presence alone does not authorize durable fact ingestion.

Reuse existing fleet arrival/recovery processing and the existing generic player refresh service independently of HTTP middleware. Do not manufacture requests, impersonate accounts in shared worker state or repeat universe-wide progression per account.

After downtime, materialize current state and replan. Do not replay missed attacks in a burst. Disablement stops new decisions while core flights continue. PvE additionally follows its protected shutdown contract.

## Two modes, one engine

Normal mode selects personal goals. PvE adds coalition membership, campaign objectives and limited faction coordination. Both reuse observation, economy, fleet policies, memory, scheduling, battle estimates and execution. Campaign bookkeeping may inspect committed results for scoring; it must not pass hidden human state to enemy policies.

## Internal cognitive abstraction

Phase 3 adds six concrete contract boundaries: affect, social cognition, experience ranking, long-term recall, language and context construction. Native module actions own state reduction, case persistence and high-level intent resolution. Laravel's existing provider/container supplies replaceability; no framework kernel, independent package or general game planner is introduced.

Generic payloads contain scoped actors, goals, stimuli, beliefs, relationships, experiences and intentions. OGame adapters retain planet/fleet/resource/technology knowledge and legal action mapping. Optional FAtiMA/CiF, CBRKit and AgentOS drivers never become alternate sources of game truth. PsychSim, embeddings and compression remain gated future capabilities, not an interface tree to scaffold in advance.

Ordinary gameplay, known social exchanges and native memory require no generative calls. Human-language escalation may make one bounded request including proposals, followed by deterministic validation and current permission checks. Rare advice is a disabled post-baseline experiment. The [Phase 3 specification](phase-3-cognition.md) owns detailed data lifetimes, fallback rules, examples and the final architecture diagram.
