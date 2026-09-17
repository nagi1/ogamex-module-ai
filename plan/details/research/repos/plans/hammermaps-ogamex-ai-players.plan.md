# Enhancement plan — hammermaps/OGameX-AI-Players

## Verdict summary (one line)

Net worth is small: a demo-population fork (AI explicitly labelled, host-side daemon + admin UI) whose only transferable ideas are a rare no-op idle and the discipline of never tripping the host's anti-bot thresholds — its hardcoded strategy lists are gate-1 refuse, its daemon/metrics/admin are module-boundary refuse, and its sleep/jitter/scoring are already beaten by our `SessionPlanner` + `UtilityScorer`.

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Anti-bot threshold self-check (M38–M40) | Host flags round-the-clock departures (≥18 distinct hours), instant expedition re-dispatch (return → re-dispatch ≤10 s), instant fleet-save after attack. The AI must be *incapable* of tripping them. | A regression self-check test asserting the cadence + dark period keeps departures under the host's threshold, and that an expedition re-dispatch can never land ≤10 s after a return (host slot check + minute-scale Weibull waits already make it impossible). No runtime code. | Authenticity is the goal; tripping a cheater-detector is the fastest way to be flagged. Our `CORE_DARK_MINUTES = 540` already covers Signal 1; Signals 2–3 deserve the same *named* guarantee so a later scheduler edit can't reintroduce them. |
| Archetype → character-class affinity (M03) | miner/turtle → Collector, fleeter/raider → General, neutral/trader → Discoverer. | Host-read mapping in profile provisioning, applied only where the module (not the host) creates accounts. | A real player's class matches how they play; a fleeter on Collector is observable. Conditional — see open questions. |

## ENHANCE — table

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| Scored `DoNothing` candidate (`CandidateActionFactory::doNothing()`, safety 0.1, no archetype preference except `CasualPolicy` 0.5) — it only wins when nothing else is available. | Repo skips 5% of turns unconditionally (`difficulty_level < 4 && rand(1,20) === 1`) — the "opened the game, did nothing, closed it" moment. | After `DecisionEngine::decide()` scores, add a rare seeded no-op override: with a small skill-band-aware probability the session selects `DoNothing` even when real actions exist. Never fires when `fleetsaveEligible` or `reactionWakeAt` is set (V2/V6 safety over variance). | `app/Domain/Decision/DecisionEngine.php` (+ one Pest test). |
| 5 archetypes with no written mapping to the repo's 6 profiles. | Repo names `raider` / `defensive` / `neutral` separately. | No new archetype (gate 2). Document the fold — raider→`Fleeter` (Raid 0.9), defensive→`Turtle` (QueueUnits 1.0), neutral→`Casual` — in the enum docblock. | `app/Enums/AiArchetype.php` (doc only). |

## ALREADY-DONE (confirmed by grep)

- 5-archetype taxonomy with per-archetype preference weights — `app/Enums/AiArchetype.php`, `app/Domain/Decision/Policies/*Policy.php` (subsumes repo's 6 profiles M01/M02).
- Host-read object universe — `app/Domain/Decision/FacilityChain.php`, `QueueableBuildingPlanner.php`, `EnergyCapacity.php` derive every step from `ObjectService::getResearchObjects()`/`getUnitObjects()`; no machine-name lists (beats repo's hardcoded M12/M15 arrays).
- Sleep window richer than repo M05 — `app/Domain/Routine/SessionPlanner.php` (`CORE_DARK_MINUTES = 540`, wake/bed drift, absences) vs repo's fixed `01:00–07:00`.
- Jitter richer than repo M06 — `SessionPlanner::wait()` Weibull tail + `SessionDecisionService::materialArrivalDelay()` right-skewed SP3 arrival, vs repo's flat `rand(60,300)`.
- Priority-weight gating — `app/Domain/Decision/UtilityScorer.php` (`ARCHETYPE_WEIGHT = 25`, seeded variation) replaces repo's dice gate M04 and is host-read.
- Energy-deficit override M12 — `EnergyCapacity::pending()` picks the cheapest host-reported producer first.
- Unit/affordability cap M19 — `QueueableUnitPlanner.php`.
- Miner transport M31 — `QueueableTransferPlanner.php` + `Transfer` candidate.
- Colonization M33 — `QueueableColonyPlanner.php` + `colonizeEligible` gate (CL3) in `CandidateActionFactory.php`.
- Ally/no-attack protection M35 — `attack_permitted` in the `targetReports` projection.
- Fleet-save-on-threat (repo lacks it entirely) — `QueueableFleetSavePlanner.php` + `eligibleFleetSaveCandidates()`.
- Expedition M24 — `QueueableExpeditionPlanner.php` host slot + Astrophysics checks (EXP-001).

## REFUSE — list with one-line reason

- Hardcoded strategy lists (M20–M30 machine names, `ENERGY_PRODUCERS`/`RESOURCE_PRODUCERS`/`STORAGE_BUILDINGS`) — gate 1: object universe must be host-read, never a module list.
- Host-side daemon + `AiDaemonStatus`/`AiDaemonMetric` ring buffer + memory auto-restart (M09/M10/M11) — module boundary; our work runs on Laravel queue workers.
- Admin CRUD / impersonate + `ServerAdministrationController` bot detection (M38–M45) — host-side admin UI, module boundary.
- Random nearby target M34 — gate 3: no reconnaissance, profitability or strength check; a human raids a specific fat/weak target.
- `' [ AI ]'` suffix + `is_ai_player` label (M37) — the exact opposite of the indistinguishability goal.
- Race-prone due-player query (M08) — no claim lock before processing; our `AiWorkItem` idempotency keys already supersede it.
- Difficulty level only toggling the 5% idle skip — inert knob, dead config (gate 2).

## Priority recommendation — single highest-value change

Add the rare no-op override in `DecisionEngine`. It is the one authentic behaviour the repo actually has that we lack: a player who opens the game and does nothing. It is ~10 lines — one seeded, skill-band-aware draw that selects `DoNothing` after scoring — host-read, nameable as ordinary play, and closes the only gap where our accounts look *too* diligent. Follow it with the anti-bot self-check test so no future scheduler change can reintroduce a detectable cadence.

## Open questions / risks

- Is account provisioning (character-class assignment) module-owned? If the host owns registration, ADOPT-IDEA #2 is host work, not a module change.
- Anti-bot thresholds live in the host's admin controller as editable settings; our self-check should read them rather than hardcode "18 hours / 10 s" where the host exposes them (the gate allows naming today's catalogue in tests, but the thresholds are policy, not catalogue).
- The no-op override must never suppress a real reaction: guard it behind "no `fleetsaveEligible` and no `reactionWakeAt`" so a save or reaction wake always wins.
- Repo dispatches fleets at a constant `speed 10.0`; we keep host-computed speeds — no change implied, but it is the one regularity signal our executor must not import.
