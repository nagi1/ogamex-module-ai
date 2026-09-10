# AI module architecture

This document records the boundary for the module scaffold. It is not a
feature plan.

## Ownership

OGameX core owns authoritative game state, legality, validation, resource
accounting, queues, fleet missions, combat execution, and domain events.

The AI module owns player behavior: routine and sessions, legal perception,
candidate intent, decision policy, memory, relationships, response scheduling,
and module-owned persistence.

The module must call normal OGameX domain actions for any game action. It must
not deduct resources, create queues, or reproduce game rules itself.

## Implementation conventions

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
  the host action gateway remains the only executor of game rules.

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
An optional language model may produce chat text or rare strategic advice from
a limited context, but it must never execute game actions or access the
database directly.

## Repository lifecycle

The AI module is maintained as an independent Git repository and installed as
a normal checkout at `OGameX/Modules/AI`. It is not a Git submodule. Module
commits, branches, releases, and tests remain separate from the OGameX parent
repository; integration tests run through the OGameX host application.

See [development.md](development.md) for the installation, local development,
testing, release, and maintenance workflow.

## Phased implementation

The [implementation plan](../plan/README.md) expands this scaffold boundary into deliverable phases. Its [host extension assessment](../plan/details/specs/module-extension-points.md) distinguishes existing support from required generic host changes.
