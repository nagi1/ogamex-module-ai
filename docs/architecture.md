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

