# AI module architecture

This document records the module's ownership and implementation conventions.
The linked Phase 3 specification separates current code from future features.

## Ownership

OGameX core owns authoritative game state, legality, validation, resource
accounting, queues, fleet missions, combat execution, and domain events.

The AI module owns player behavior: routine and sessions, legal perception,
candidate intent, decision policy, memory, relationships, response scheduling,
and module-owned persistence.

The module must call normal OGameX domain actions for any game action. It must
not deduct resources, create queues, or reproduce game rules itself.

## Implementation conventions

### Nagi agent baseline

[`AGENTS.md`](../AGENTS.md) is the module's persistent Codex agent definition
and implementation memory. It requires SOLID/DRY/KISS/YAGNI, action-oriented
business logic, container resolution, early returns instead of `else`, native
Pest 5 with PCOV, and comments only for non-obvious reasons or invariants.
Read it before module work; its module-local rules take precedence over a
generic coding preference.

- Persisted finite state is represented by integer-backed PHP enums. Database
  columns use unsigned tiny integers and enum casts; names remain in code and
  migrations, not in hot indexes.
- Do not introduce magic numbers or strings. Stable IDs, states, payload keys,
  limits and algorithm parameters must have a named enum, value object or
  documented constant.
- Decisions are pluggable scoring policies. A coordinator evaluates candidates;
  it does not hard-code archetype branches with `switch` or `match`.
- Prefer index-aware queries, bounded batches, short transactions and scoped
  locks. New tables must index their actual dispatch and ownership predicates.
- Favor developer experience: typed results, explicit state transitions,
  deterministic injected dependencies and focused parallel tests.
- Follow Laravel conventions before inventing framework infrastructure: use the
  service container for dependency inversion, service providers for bindings,
  jobs for retryable units of work, commands for operator entry points,
  Eloquent models for module persistence, and events only for committed
  lifecycle boundaries.
- Keep implementations swappable. Orchestrators depend on small domain
  contracts, while the provider binds the default implementation. New policies
  or infrastructure adapters must be addable without changing callers.
- Apply SOLID at the module boundary: a job coordinates one work attempt,
  a policy scores choices, a repository/store owns persistence mechanics, and
  normal host domain services remain the only executors of game rules. Prefer a
  module-local adapter with an explicit record-only fallback over expanding
  OGameX. A host change is justified only when it is generic, independently
  useful without AI, and cannot be expressed through an existing boundary.
- Use container-resolved application actions for behavior initiated by jobs,
  commands or listeners. Resolve the small contract with Laravel's
  `app(Contract::class)` at that framework boundary, bind its default action in
  `AIServiceProvider`, and replace it with `$this->app->instance(...)` in
  tests. This is the module's persistent implementation-memory for swappable
  orchestration; do not construct a collaborator directly in a job.

## Queue and Horizon integration

The host runs Laravel Horizon over Redis and owns the host lanes in
`config/horizon.php` (`app/Enums/QueueName.php`). The module owns its own lanes:

| Lane | Queue | Supervisor | Purpose |
| --- | --- | --- | --- |
| AI work | `ai` | `supervisor-ai` | Deterministic `ProcessAiWork` jobs: sessions, building, social and experience work |
| AI language | `ai-language` | `supervisor-ai-language` | Bounded foreground language generation; one provider request per sealed reply |

The plan lives in [`config/horizon.php`](../config/horizon.php) and is applied to
`config('horizon.*')` by `Modules\AI\Support\HorizonConfiguration` from
`HorizonServiceProvider`. Horizon reads its config when the master supervisor
starts, after every provider has booted, so the host horizon file stays generic.
The plan is applied only while the module is enabled, so disabling the module
removes every AI supervisor, wait and queue with no host change.
`HorizonConfiguration` requires the plan file directly when `config('ai.horizon')`
is absent, so the lanes still register when the module was enabled after
`config:cache` had already run. Tune the lanes with the module's own
`AI_HORIZON_*` environment variables.

`ProcessAiWork` sets its lane in the constructor, exposes Horizon tags (`ai`,
`ai:work`, `ai:work-item:{id}`) for dashboard filtering, bounds itself with
`$timeout` and `$maxExceptions`, and reclaims an expired `Leased` work item so a
queue retry or the scheduler can finish it instead of the item staying stuck.
`AIServiceProvider::configureSchedules()` dispatches `ai:run-due-work` every
minute with `withoutOverlapping`, and `ai:reconcile-language-requests` every ten
minutes.

Horizon supervisor timeouts stay below the redis `retry_after` (660s): 30s for the
AI work lane and 60s for the language lane (above `ai.language.timeout_seconds`).
AI queued work needs Redis and Horizon; the database worker pools only drain the
fleet lanes. Do not run Horizon while `QUEUE_CONNECTION` is not `redis` — Horizon
only consumes redis, so module jobs would sit on an unconsumed database queue.

### Language receipt lifecycle

`GenerateAiReplyAction` is the only path that may call `LanguageGateway`. It resolves
an already sealed reply, reserves one attempt through the module ledger, writes the
`ai_language_requests` receipt while it is `Generating`, calls the gateway, and then
settles the receipt and the reservation in one transaction:

| Provider outcome | Request state | Reservation | Reply |
| --- | --- | --- | --- |
| Valid structured envelope | `Completed` | settled at reported usage | generated text plus validated proposals |
| Schema-invalid envelope | `Invalid` | settled at reported usage | authored fallback |
| Definite transport failure | `Failed` | settled at reported usage | authored fallback |
| Timeout | `Uncertain` | stays `Reserved` until reconciliation | authored fallback |

An attempted request always consumes one unit of daily capacity: settling releases
unused tokens but never the attempt, `request_key` is unique, and an existing receipt
prevents a second reservation for the same reply. A timed-out attempt is never resent;
the scheduled `ai:reconcile-language-requests` command charges it at its reserved
maximum once the provider can no longer complete it. `AiUsageBudgetScope` rows are
locked for the universe, player and conversation before a reservation, so concurrent
requests cannot exceed a shared cap.

The opt-in `ai:language-conformance` command is the only caller that may reach a real
provider, and it sends sanitized fixtures only. It exists to record real status,
usage, latency and failure behavior for the conformance gate; it never runs in CI and
it cannot execute a game action.

### Install and uninstall

`Modules/AI/app/Hooks/InstallModule` and `UninstallModule` are discovered by the
host lifecycle commands (`php artisan ogamex:module:install AI`,
`php artisan ogamex:module:uninstall AI`). The install hook reports Horizon and
cache prerequisites; the uninstall hook explains that module data is retained unless
`--drop-data` is given. The host owns enabling, migrations, cache refresh and worker
restart; see the host `docs/module-lifecycle.md`.

`Modules/AI/docker/supervisor/queue-worker.conf` adds the database-driver worker pool
and is applied by the host's generic module loader only while the module is enabled.

## Proposed boundaries

- \`Domain/Perception\`: builds the information available to one AI player.
- \`Domain/Decision\`: turns perceived state into a small set of scored intents.
- \`Domain/Routine\`: models timezone, activity, sessions, and human reaction delay.
- \`Domain/Scheduling\`: finds due work and schedules the next wakeup.
- \`Domain/Memory\`: stores structured memories and relationships with decay.
- \`Jobs\`: performs short, retry-safe units of AI work under a player lock.
- \`Listeners\`: converts core events into non-instant, routine-aware wakeups.
- \`Support\`: deterministic clocks, seeded randomness, budgets, and adapters.

These are boundaries for code ownership, not permission to add speculative
frameworks. New extension points should be added only when a real module need
is demonstrated.

## External engines

Laravel remains the orchestration layer. The existing Rust battle engine should
be reused for battle outcomes and simulations when combat behavior is added.
An optional language model may interpret or express unrestricted human
conversation after authored/social routes are considered. It returns text and
bounded proposals in one request; deterministic module code validates them.
Rare strategic advice is a disabled, later experiment with its own activation
gate. No model executes game actions or accesses the database directly.

## Planned Phase 3 cognition

The [detailed Phase 3 plan](../plan/details/specs/phase-3-cognition.md) defines
native affect/social cognition, structured CBR, exact memory, conversation
escalation and six concrete module contract seams. Bind implementations in the
existing Laravel provider; keep OGame-specific data mapping and intent
resolution outside driver-neutral payloads. No framework extraction or new
host cognition service is planned.

FAtiMA/CiF and CBRKit are replaceable candidates after native behavior works.
AgentOS is an optional memory-only candidate, not the player runtime. PsychSim,
embeddings and ML compression remain gated experiments. Native module records
own persona, accepted state, obligations and outcomes; optional driver indexes
and checkpoints are versioned projections. Provider failure must preserve
ordinary gameplay and authored social behavior.

### Live versus unwired

This distinction matters more than the contract list: a binding can exist without a
working adapter behind it, an adapter can be disabled by default, and an implementation
can exist with nothing in the module actually calling it.

| State | Components |
| --- | --- |
| **Live and driven** | Native affect, appraised from a committed battle report the AI took part in. Native social cognition, evaluated when an exchange is answered. Native long-term memory, read when conversation context is built. Native experience, ranked when the AI chooses which building to upgrade: a settled success on an object raises its score and a settled failure lowers it, bounded so a remembered outcome settles a near-tie without outvoting the persona's own preference. These are the shipped behavior and the fallback for every seam. |
| **Wired, opt-in** (real adapters, disabled by default) | CBRKit behind `ExperienceEngine`; FAtiMA/CiF behind `AffectEngine` and `SocialCognition`. Selecting one requires an explicit setting and a running sidecar. |
| **Unwired** (contract only) | `Embedder` and `SemanticRetriever` are deliberately not scaffolded. Semantic recall and ML compression are not implemented. |
| **Deferred** | AgentOS long-term memory; PsychSim; provider batch enrichment. |

### What an AI can legally observe

Observations are reduced from committed host rows, never from events: chat messages,
alliance membership changes, and battle reports. A battle concerns only the planet owner
and the attacking player named in the stored report, so a player who was neither is never
given an observation even though the row exists. The stored row is the trigger rather than
`BattleResolved`, because the event fires before the result is durable.

The report-to-harm mapping is deliberately narrow (version one). It reads resource loss
only, so a battle can produce harm but never aid, and it derives no threat because whether
the attacker can strike again is not in the row. An AI that did not come off worse is
observed but not appraised, because the emotion set cannot express having won and a
fabricated value would be worse than recording nothing.

Driver selection is one setting per seam, read through a selector that reports an
unrecognised value and falls back to native instead of failing. Because the module's
own bindings are not active in the test suite, each test wires the seam it exercises
rather than relying on the provider.

Every optional driver is bounded by the module rather than trusted to bound itself: connect
and request timeouts, a casebase bound, a response-size bound, a per-driver circuit breaker and
a tested native fallback. The response bound is module policy rather than a driver property,
so no driver can enlarge the input surface the module agreed to read. The module also keeps
owning the outcome: it re-applies its own ordering after a driver answers, and it decides how
much weight ranked evidence carries.

`ai:cognition-conformance` is the opt-in measurement run. It is the only module code that may
contact a real cognition sidecar, it refuses to run without `--confirm`, it records p50/p95
latency, request and response bytes and observed failure modes against the pinned sidecars, and
it never runs in CI. Its artifacts sit in `storage/app/ai-cognition-conformance/`, and the
reviewed numbers are kept in the
[driver decision record](../plan/details/research/phase-3-driver-decisions.md) rather than
restated here.

The [assessment](../plan/details/research/phase-3-current-state.md) records that
these features do not yet exist after Phase 2. It also explains why ordinary
chat sends need host-equivalent permission checks and why current events are
not automatically durable memory sources. Follow the [decision history](../plan/details/DECISIONS.md)
instead of treating every suggestion in the original conversation as current.

## Repository lifecycle

The AI module is maintained as an independent Git repository and installed as
a normal checkout at `OGameX/Modules/AI`. It is not a Git submodule. Module
commits, branches, releases, and tests remain separate from the OGameX parent
repository; integration tests run through the OGameX host application.

See [development.md](development.md) for the installation, local development,
testing, release, and maintenance workflow.

## Phased implementation

The [implementation plan](../plan/README.md) expands this scaffold boundary into deliverable phases. Its [extension assessment](../plan/details/specs/module-extension-points.md) records the existing boundaries used by the module and deferred generic capabilities; it does not authorize AI-specific host APIs.
