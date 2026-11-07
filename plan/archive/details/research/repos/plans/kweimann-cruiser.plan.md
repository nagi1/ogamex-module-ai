# Enhancement plan — kweimann/cruiser

## Verdict summary
The repo is a standalone scraping account-sitter; almost everything it does we already ship through the host's services, except **expedition debris harvest** (adopt the idea) and one real safety refinement to our fleetsave destination ranking.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Expedition debris harvest — `harvest_expedition_debris` sends pathfinders to slot 16 to collect debris an expedition left | A Discoverer's own expeditions produce debris at galaxy position 16; send pathfinders (position-16 requires pathfinder, recycler otherwise) to harvest it. Cruiser does it as an instant reflex — we would do it at the **next session wake** | New: `QueueableDebrisHarvestPlanner.php` + `QueueAiDebrisHarvestAction.php`, wired like `QueueableExpedition`. Reads `OGame\Models\DebrisField`, dispatches via `FleetMissionService` with `RecycleMission` (type 8) | The account already sends expeditions, and a Discoverer harvests the debris those leave. Host does 100% of the math and legality |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `rankedDestinations` ranks moons first, then by **descending** distance | Cruiser ranks a **same-position (distance-5) planet↔moon jump first** — the phalanx cannot observe a same-coordinate planet↔moon flight | Prefer a same-coordinate moon (distance 5) ahead of far moons, then keep the existing distance ordering. One added comparison in the existing `usort` comparator | `QueueableFleetSavePlanner.php` (`rankedDestinations`) |

## ALREADY-DONE (confirmed by grep)

- Reactive fleetsave — plan + dispatch + shadow-split: `QueueableFleetSavePlanner.php`, `QueueAiFleetSaveAction.php`.
- Deliberate save failure — `SaveFailurePolicy.php`.
- Recall when safe, never into an attack — `recallPlan`, `QueueAiRecallAction.php`.
- Hostile/inbound detection without a module mission-type list — `PlayerObservationService::inboundThreat`.
- Reaction wake inside 120–180 s before impact — `PlayerObservationService::reactionLeadSeconds` + `SessionDecisionService`.
- Human day/night cadence with per-day drift — `SessionPlanner.php`.
- Expeditions — slot-16, Astrophysics gate, free-slot gate, single disposable civil hull: `QueueableExpeditionPlanner.php`.
- All flight math is host-side — `FleetMissionService::calculateFleetMissionDistance/Duration/Consumption`.
- Moon-first + phalanx-avoidance ranking — `QueueableFleetSavePlanner::rankedDestinations`.
- Cargo lift on save (ours proportional) — `QueueAiFleetSaveAction::liftableStock`.
- Class awareness — host `CharacterClassService`.
- Retry/backoff — `app/Jobs/ProcessAiWork.php`.
- Server speed/bonus read live — host `SettingsService`.

## REFUSE

- Scraping, login/relogin, event/movement parsing — forbidden.
- Expedition loot / points math — hardcoded `SHIP_DATA`; a loot predictor would be a second engine.
- Enumerate every destination × speed for fuel/duration optimum — machine-optimal.
- Deuterium→crystal→metal value-first cargo priority — flagged non-human.
- Telegram/`.wav` notifications — out of scope.
- Request throttle / hardcoded Chrome User-Agent — scraper concern.
- Constant 10–15 min 24/7 wakeup — our drifting session planner is more human.
- "Auto-send pathfinders immediately" — the immediacy is bot-like; only harvest-at-next-wake is adopted.

## Priority recommendation
Adopt **expedition debris harvest** — the only genuinely missing ordinary-play loop, and the host provides everything (`DebrisField` + `RecycleMission` legality, cargo, dispatch). Fire on the next routine wake, never as an instant reflex. Do the one-line same-position-moon fix in `rankedDestinations` alongside it.

## Open questions / risks
1. Gate 1 on ship selection: the host's `RecycleMission` hardcodes `'pathfinder'`/`'recycler'`; if `ObjectService` exposes no harvest-capable classification, the module cannot name the ship without violating gate 1 — defer if not.
2. Probe-only inbound: confirm the host's `currentPlayerUnderAttack()` ignores lone probes.
3. Debris ownership: harvest only slot-16 debris at the account's own expedition coordinate.
4. Same-position moon fix must no-op when the account owns no moon at the origin's coordinates.
5. Doctrine: keep our "further = harder to phalanx-time" ordering; the same-position-moon rule is the uncontroversial part.
