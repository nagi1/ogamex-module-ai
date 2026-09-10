# Host extension-point work

Owner: OGameX Next host for generic contracts; AI repository for consumers. Evidence: [inspection](../research/repository-inspection.md). No gameplay implementation is embedded in this plan.

## Reuse first

Keep nWidart discovery, module status, resource loading, admin.nav and player/planet metadata. Keep core's existing gameplay services and Rust/PHP engines. Do not create another loader, generic hook framework, object registry, new resource type or combat system for AI.

Each item below is a separate focused host concern with its own tests and docs update. Module behavior remains in Modules/AI. Do not treat a proposed contract as available until its host change lands.

| ID / needed by | Gap and smallest extension | Acceptance |
|---|---|---|
| E1 / Phases 1–2 | Publish actor-scoped observation, refresh and validated action boundaries over existing services. Move or share required controller checks instead of duplicating them inside AI. Verify ownership, bans, vacation/protection, action limits and fresh per-job actor context. | Equivalent human and module intents have the same acceptance/rejection; two sequential accounts cannot inherit each other's state. |
| E2 / Phase 1–2 | Document and guarantee committed lifecycle delivery for consumed events; add stable correlation/event identity. Preserve existing contracts or deprecate explicitly. Add a distinct committed battle/report notification after mission persistence; leave calculation events out of player memory. | Rollback emits no committed fact; retry deduplicates; actual battle outcome correlates with its report/mission. |
| E3 / Phase 2 | Expose an isolated estimation path accepting supplied visible/assumed battle values and reusing the existing engine. Avoid live opponent reads and real gameplay notifications. Verify loot/retreat assumptions separately from round simulation. | Repeated estimates neither mutate state nor emit committed events; changing unseen defender state cannot alter identical input estimates. |
| E4 / Phase 2–3 | Add only missing consumed notifications: dispatched fleet, completed units, delivered report/message and membership change. Define recipient scope and transaction timing. | One legal observation per recipient; no instant offline awareness; normal play unchanged without listeners. |
| E5 / Phase 2 and 5 | Reuse admin.nav for controls; add a curated player-profile information slot and a structured in-game navigation entry only for disclosure/campaign access. Module owns pages and translations. | Unauthorized users cannot see admin controls; empty registration changes no UI; no template replacement or script injection. |
| E6 / Phase 5 | Add a narrow optional restriction contract at authoritative hostile-action validation for declared modes, including all launch paths, missiles and moon attacks. It may reject additional actions but cannot grant an action core rules forbid. | Human-on-human hostility fails through UI and direct requests in PvE; normal-universe outcomes are unchanged. |
| E7 / Phase 5 | Declare required mode providers and safe disable behavior. An active PvE world must not silently become PvP when its module is absent. Use a host-level maintenance/hostile-dispatch block until campaign shutdown is resolved. | Disabling/crashing the provider blocks new hostile launches; existing missions still resolve, and ordinary universes are unaffected. |

E1 also needs durable action correlation: reuse an existing core operation ID where available, otherwise add the smallest receipt seam needed to reconcile uncertain dispatch. This is required before autonomous fleets.

## Contract caveats

The existing extension draft permits observation and additive UI only. E6 is a **new restricted category**, not something those passive events already support. Document its precedence, failure behavior and opt-in scope before implementation; do not hide enforcement in a controller or rely on a social truce.

Small identity flags can use existing metadata. High-volume schedules, memory and faction/coalition state use module tables. No alliance metadata helper is required just to store an AI campaign's membership.

## Delivery and DX

Phase 1 completes E1's first build/observation slice, plans remaining parity and establishes E2 delivery tests. E1 fleet support and E4 unit/dispatch needs land in Phase 2; E2 committed battle reporting and E3 gate Phase 2. Social E4 gates Phase 3. E5–E7 gate the relevant UI/PvE work.

Update host docs/modules.md as the supported contract catalogue and mark the old draft's completed sections accurately. Add event/slot examples to HelloWorld only where useful. Record the minimum supported host revision in the module release notes. Every host change proves module-disabled behavior and relevant race cases; avoid speculative extension points.
