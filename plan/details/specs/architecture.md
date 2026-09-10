# Architecture within the existing module

Owner: AI module. Host evidence: [repository inspection](../research/repository-inspection.md). Required host changes: [extension work](module-extension-points.md).

## Preserve the installed structure

The existing AI provider registers module resources through nWidart. Keep its alias, route/config/view namespaces and module-local migrations/tests. Extend the existing Domain areas for Perception, Decision, Routine, Scheduling and Memory. Jobs perform short work; Listeners ingest permitted notifications; Support contains adapters and budgets. Add the PvE coordinator within the same module when Phase 5 starts.

The module is an independent ordinary Git checkout, not a submodule and not host-owned code. Generic events, action validation and view slots belong to separate host changes. No AI-specific conditionals spread through core gameplay.

## Decision flow

Core advances authoritative state. A module adapter obtains the account's permitted observations. Policies generate and score a bounded set of candidates. The selected intent goes through the shared core action path. A committed receipt updates module memory and the next due time.

Observation, cost/time quotation, execution, simulation and memory are separate responsibilities, not a new generic agent framework. Policies receive values rather than unrestricted game models. Include observation age, source and ruleset version; unknown enemy data stays unknown.

An intent records actor, action, relevant parameters, observation version, expiry and a stable decision identifier. Revalidate before execution. Explanations record feature scores and rejection reasons, never secret state.

## Runtime and persistence

Use an indexed module-owned due-work table with schedule generation, retry count and leases. Claim bounded batches atomically; stale jobs become no-ops. Serialize state-sensitive work per account. A stable operation receipt closes the retry gap: when execution status is uncertain, reconcile the existing core operation before retrying.

Use existing player/planet module metadata for small identity pointers. Put schedules, indexed histories, commitments and campaign state in module-owned tables. Metadata namespacing prevents accidental collisions; it is not a security boundary between PHP modules.

Separate decisions, simulation and optional language queues. Never hold a gameplay lock during network generation. Recheck current module/mode status in long-lived workers. Refresh actor-specific services between jobs to prevent one account's cached state reaching another.

## Events and game progression

Six game events already exist, but their presence does not prove after-commit delivery. The existing battle event occurs inside calculation and lacks mission/report correlation. Fix the specific lifecycle gaps before treating events as durable facts; lightweight listeners enqueue work after successful commit.

Reuse existing fleet arrival/recovery processing and expose the required player refresh independently of HTTP middleware. Do not manufacture requests, impersonate accounts in shared worker state or repeat universe-wide progression per account.

After downtime, materialize current state and replan. Do not replay missed attacks in a burst. Disablement stops new decisions while core flights continue. PvE additionally follows its protected shutdown contract.

## Two modes, one engine

Normal mode selects personal goals. PvE adds coalition membership, campaign objectives and limited faction coordination. Both reuse observation, economy, fleet policies, memory, scheduling, battle estimates and execution. Campaign bookkeeping may inspect committed results for scoring; it must not pass hidden human state to enemy policies.
