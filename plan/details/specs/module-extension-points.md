# Extension-point assessment

Owner: `Modules/AI` for AI behavior; OGameX Next only for a future generic capability that is independently useful without AI. Evidence: [inspection](../research/repository-inspection.md). Phase 1 and Phase 2 do not require an AI-specific host API.

## Reuse first

Keep nWidart discovery, module status, resource loading, admin.nav and player/planet metadata. Keep core's existing gameplay services and Rust/PHP engines. Do not create another loader, generic hook framework, object registry, new resource type or combat system for AI.

Phase 1 and Phase 2 use the existing module provider/route/migration discovery, Laravel container bindings, `PlayerGameStateService`, `PlanetServiceFactory`, `BuildingQueueService`, models and normal validation. Module code owns the adapter, perception reduction, scheduling, decisions, receipts and traces. Do not treat a future proposed contract as available until a separately justified host change lands.

| ID / needed by | Gap and smallest extension | Acceptance |
|---|---|---|
| E1 / completed Phases 1–2 | No extension required. The module's `QueueAiBuildingAction` refreshes via generic `PlayerGameStateService` and delegates to the normal `BuildingQueueService`; it owns its typed result and receipt. Perception is module-local. | Equivalent queue validation is used; two sequential accounts cannot inherit module actor state. |
| E2 / future Phase 3+ | If an existing event cannot safely express a durable game fact, consider a generic committed lifecycle delivery with stable correlation/event identity. | Rollback emits no committed fact; retry deduplicates; actual battle outcome correlates with its report/mission. |
| E3 / deferred | If a future executable combat feature needs an estimate unavailable from the normal engine, consider an isolated generic estimation path accepting supplied visible/assumed values. Current Phase 2 records safe intents and does not need it. | Repeated estimates neither mutate state nor emit committed events; changing unseen defender state cannot alter identical input estimates. |
| E4 / future Phase 3+ | Consume existing notifications first. Add a generic notification only after proving it is unavailable and has non-AI consumers. | One legal observation per recipient; no instant offline awareness; normal play unchanged without listeners. |
| E5 / Phase 2 and 5 | Reuse admin.nav for controls; add a curated player-profile information slot and a structured in-game navigation entry only for disclosure/campaign access. Module owns pages and translations. | Unauthorized users cannot see admin controls; empty registration changes no UI; no template replacement or script injection. |
| E6 / Phase 5 | Add a narrow optional restriction contract at authoritative hostile-action validation for declared modes, including all launch paths, missiles and moon attacks. It may reject additional actions but cannot grant an action core rules forbid. | Human-on-human hostility fails through UI and direct requests in PvE; normal-universe outcomes are unchanged. |
| E7 / Phase 5 | Declare required mode providers and safe disable behavior. An active PvE world must not silently become PvP when its module is absent. Use a host-level maintenance/hostile-dispatch block until campaign shutdown is resolved. | Disabling/crashing the provider blocks new hostile launches; existing missions still resolve, and ordinary universes are unaffected. |

E1 also needs durable action correlation: reuse an existing core operation ID where available, otherwise add the smallest receipt seam needed to reconcile uncertain dispatch. This is required before autonomous fleets.

## Phase 3 source and chat findings

The [current-state assessment](../research/phase-3-current-state.md) updates the older inspection: owner-delivered report records, module-registered after-commit listeners/observers and bounded legal-state reconciliation are the first integration choices. Broadcast/calculation events do not prove durability, and observer coverage must be checked for bulk-update paths. No automatic host chat/alliance event rewrite is part of Phase 3.

The existing `ChatService` send methods do not enforce every rule from `ChatController`. `DeliverAiReplyAction` must reuse/recheck host-equivalent recipient, self-message, ignore, membership and text checks, including changes while generation is in flight. Also add a module reply-to scope/visibility guard: the inspected controller does not provide it. Test actual persistence, controller-equivalent behavior and the extra module ACL guard. A normal service call without those checks is not a permission boundary.

Cognition, case features, memory schemas, language budgets and provider drivers all belong to `Modules/AI`. Reuse `AIServiceProvider` bindings and module configuration; none requires a new host cognitive abstraction or framework.

## Contract caveats

The existing extension draft permits observation and additive UI only. E6 is a **new restricted category**, not something those passive events already support. Document its precedence, failure behavior and opt-in scope before implementation; do not hide enforcement in a controller or rely on a social truce.

Small identity flags can use existing metadata. High-volume schedules, memory and faction/coalition state use module tables. No alliance metadata helper is required just to store an AI campaign's membership.

## Delivery and DX

Phase 1 and Phase 2 complete their module-only slices through E1. E2 and E4 are evaluated only when Phase 3 needs durable facts; E3 is evaluated only when a future executable combat feature needs an estimate. E5–E7 remain future UI/PvE considerations.

Update host docs/modules.md as the supported contract catalogue and mark the old draft's completed sections accurately. Add event/slot examples to HelloWorld only where useful. Record the minimum supported host revision in the module release notes. Every host change proves module-disabled behavior and relevant race cases; avoid speculative extension points.
