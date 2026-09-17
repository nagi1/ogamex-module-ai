# AI enhancement work package — mined from 23 OGame bot/AI repos (17 September 2026)

## Scope & rules
Ideas first, but porting or referencing another repo's implementation is now permitted — the old
Gameforge "no porting" policy has been discarded (17 Sep 2026, see plan/details/DECISIONS.md).
Still: universe/prices/requirements stay host-read (gate 1), the smallest mechanism that closes the
gap (gate 2), and every mechanism nameable as ordinary experienced OGame play (gate 3). No new
dependencies, no second battle engine, no host-side daemon. Per-repo detail (every
ADOPT/ENHANCE/REFUSE row and the grep evidence) stays in `repos/` and `repos/plans/`; this file is
the deduplicated, ranked slice list.

## P1 — adopt now

### Debris-field recycling loop — sources: jaesivsm-pyogame, janeczkins-xbot, kweimann-cruiser, ogame-ninja-scripts, r4fek-ogame-bot, trilogi77-ogamebot
- What: one new planner that scans the account's neighbourhood for host `DebrisField` rows above a module loot threshold and dispatches recyclers via the host `RecycleMission`; also harvests the slot-16 debris an own expedition leaves (pathfinder/recycler read from host). Skips fields already covered by an in-flight recycle. Six repos independently name the same missing loop.
- Player name: "send recyclers to the debris field after a raid or expedition".
- Files: new `app/Domain/Decision/QueueableRecyclePlanner.php`, `app/Actions/QueueAiRecycleAction.php`, `app/Contracts/QueueAiRecycle.php`; `app/Enums/AiCandidateActionType.php`, `app/Enums/AiWorkKind.php`, `app/Domain/Decision/CandidateActionFactory.php`, `app/Providers/AIServiceProvider.php`; host `RecycleMission` + `DebrisFieldService` + `DebrisField`.

### Raid estimate — P20 loot quantile, delete `expectedLoot()` — sources: jstar88-opbe, klaasvp-trashsim
- What: carry the host engine's authoritative P20 loot (cargo-constrained, fill-ordered) out of the existing 50-run stream and feed `clearsLootTier()` from it; delete the module's hand-rolled `min(metalEq × fraction, cargo)` scalar so the host fill order is the single loot authority.
- Player name: "does what I actually bring back clear the fuel ratio".
- Files: `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php`.

### Raid estimate — survival floor (win/loss/draw + fleet-loss rate) — sources: maximalcode-combat-sim, klaasvp-trashsim, peterradzisz-fleet-optimizer
- What: read each sampled run's `attackerUnitsResult`/`defenderUnitsResult` and carry `pWin` (survived) and a fleet-loss rate next to `p20NetProfit`/`losingRuns`; `RaidPlanner` refuses above a persona threshold so a coin-flip that "profits" never flies.
- Player name: "won't fly a coin-flip raid that loses the fleet".
- Files: `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Decision/RaidPlanner.php`.

### Fleetsave destination safety — sources: halfguru-ogamebot, kweimann-cruiser
- What: exclude own bodies whose `planet_id` appears in the inbound picture before the moon/distance sort (never park the save into another incoming), and prefer a same-coordinate planet↔moon (distance-5) jump the phalanx cannot observe.
- Player name: "don't deploy the save into another incoming attack".
- Files: `app/Domain/Decision/QueueableFleetSavePlanner.php` (`rankedDestinations`, `saveFor`), `app/Domain/Perception/PlayerObservationService.php` (`inboundThreat`).

### Single-planet fleetsave fallback (harvest-save) — sources: ogame-tbot-tbot
- What: when no second own body / no moon exists, save to a debris field with recyclers on a harvest mission instead of doing nothing — a young account currently cannot save at all, which is itself a machine tell.
- Player name: "a one-planet account parks the fleet on a debris field".
- Files: `app/Domain/Decision/QueueableFleetSavePlanner.php` (`saveFor`), `app/Actions/QueueAiFleetSaveAction.php`.

### Weak-attack / nothing-to-save fleetsave gate — sources: ogame-tbot-tbot, janeczkins-xbot
- What: skip the reactive save when the local fleet is below the persona's exposure band or the inbound is a probe-only / trivial fraction of the parked fleet; reuse the band `proactivePlan()` already applies, no new constant.
- Player name: "don't move one small cargo for a lone probe".
- Files: `app/Domain/Decision/QueueableFleetSavePlanner.php` (`saveFor`), `app/Domain/Decision/SaveFailurePolicy.php`, `app/Domain/Perception/PlayerObservationService.php`.

### Storage-before-build preprocessor — sources: racinmat-phpogamebot
- What: when a build's price exceeds the planet's current storage, prepend the cheapest storage upgrade for the blocking resource before queueing the build — today the host rejects it and the planner stalls.
- Player name: "upgrade the warehouse before the mine that won't fit".
- Files: `app/Domain/Decision/EconomyUpgrades.php` (new `storageForPrice()` pass), `app/Domain/Decision/QueueableBuildingPlanner.php`.

## P2 — adopt next

### Standing defence pass — sources: ogame-ninja-scripts, piecepapercode-barakis, trilogi77-ogamebot
- What: when no attack is inbound, turtle/miner personas keep a baseline defence at a persona-scaled ratio of fleet value (host-read `getDefenseObjects()`), instead of building defence only reactively under `underAttack`.
- Player name: "a miner keeps a modest standing wall between attacks".
- Files: `app/Domain/Decision/QueueableUnitPlanner.php`, `app/Domain/Decision/Policies/TurtlePolicy.php`.

### Expedition fleet composition + system rotation — sources: ogame-infinity-web-extension, trilogi77-ogamebot
- What: dispatch a real expedition fleet — strongest combat hull (host-read `attack`), fastest civil hull as pathfinder (host-read `speed`), smallest cargo, a probe — and rotate the origin system after N outgoing expeditions; size cargo by capacity, never the repo's hardcoded points table.
- Player name: "send an escort and a pathfinder, and spread expeditions across systems".
- Files: `app/Domain/Decision/QueueableExpeditionPlanner.php`, `app/Domain/Decision/QueueableExpedition.php`, `app/Actions/QueueAiExpeditionAction.php`.

### Colony slot scoring (+ abandon) — sources: halfguru-ogamebot, trilogi77-ogamebot, janeczkins-xbot
- What: rank empty slots by host-reported fields and temperature (mid/large slots first, seeded walk), not the first empty slot; abandon-and-recolonise a below-band colony only once the host abandon seam is verified.
- Player name: "colonise the bigger, better slot".
- Files: `app/Domain/Decision/QueueableColonyPlanner.php` (`emptySlot`).

### Recall scheduling at half-duration / absence-aware save speed — sources: ogame-tbot-tbot, trilogi77-ogamebot
- What: schedule the recall at ~half the deployment duration (per-account jitter) instead of an arbitrary later session, and pick a save speed whose flight lands ≥ the upcoming absence.
- Player name: "set the recall timer for halfway, not 'sometime later'".
- Files: `app/Domain/Decision/QueueableFleetSavePlanner.php` (`recallPlan`), `app/Actions/ScheduleAiIntentAction.php`.

### Surplus consolidation transfer — sources: janeczkins-xbot, ogame-ninja-scripts
- What: when a planet nears its storage cap, sweep the above-floor surplus to the best-developed body (keep a deuterium floor on moons), the reverse direction of today's need-driven ferry.
- Player name: "ship the overflow home before the mines stall".
- Files: `app/Domain/Decision/QueueableTransferPlanner.php`, `app/Domain/Decision/ReserveFloor.php`.

### Defenceless-target intel signal + confidence downgrade — sources: alaingilbert-ogame
- What: flag a report whose fleet and defence sections are present-and-empty (`[]` vs `null`) as defenceless so the raid gate can short-circuit the estimator; downgrade published confidence when sections are missing, never raise on `null`.
- Player name: "the probe says it's empty — raid it".
- Files: `app/Domain/Perception/PlayerObservationService.php` (`targetReports`), `app/Domain/Perception/ActivityIntelReader.php`, `app/Domain/Decision/RaidPlanner.php`.

### Probe escalation + closest spy origin — sources: racinmat-phpogamebot, ogame-tbot-tbot
- What: escalate probe count (bounded 1–5) for defended/known-rich targets whose last report is partial, and pick the closest own planet with an idle probe, not the first in collection order.
- Player name: "send more probes to crack a redacted report, from the nearest base".
- Files: `app/Domain/Decision/QueueableSpyPlanner.php`, `app/Domain/Decision/QueueableSpy.php`, `app/Actions/QueueAiSpyAction.php`.

### Raid outcome feedback (per-target cooldown + blacklist + real-loot attribution) — sources: trilogi77-ogamebot
- What: skip a target hit within a cooldown window and blacklist one that underperformed across ≥3 real raids; record post-raid loot through the experience rules so the P20 screen is re-weighted by what actually landed.
- Player name: "stop farming the target that keeps disappointing".
- Files: `app/Domain/Decision/RaidPlanner.php`, `app/Actions/ExecuteAiIntentAction.php`, `app/Domain/Experience/`.

### Message a new attacker — sources: ogame-ninja-scripts
- What: on a hostile inbound, send one casual authored line to the attacker, bounded (once per distinct attacker), reusing the existing authored-dialogue protocol.
- Player name: "reply 'online :)' to the attacker, not to every probe".
- Files: `app/Actions/RunAiConversationCycleAction.php`, `app/Actions/BuildAuthoredSocialReplyAction.php`.

### Liveness floor under next-due time — sources: ogame-opensource-2-ai
- What: clamp `nextDueAt` to a configurable max idleness so a long persona-shaped absence can never trip host inactivity cleanup; keyed off explicit `AiProfile` identity, never derived "is active" rows. Confirm the host purge threshold first or this is YAGNI.
- Player name: "an account that sleeps still lives".
- Files: `app/Domain/Scheduling/SessionDecisionService.php`, `app/Domain/Routine/SessionPlanner.php`, `config/population.php`.

### Rare no-op idle override + anti-bot threshold self-check — sources: hammermaps-ogamex-ai-players
- What: after scoring, a small seeded, skill-band-aware draw selects `DoNothing` even when real actions exist (never when `fleetsaveEligible`/`reactionWakeAt` set); plus a regression test asserting departures stay under the host's anti-bot thresholds.
- Player name: "opened the game, did nothing, closed it".
- Files: `app/Domain/Decision/DecisionEngine.php` (+ one Pest test).

## P3 — later / investigate

- Phalanx fleet scan — alaingilbert-ogame: moon + phalanx + 5000 deut fleeter routine; needs owned moons, fall through cleanly. `QueueableSpyPlanner.php` pattern.
- Moonshot — trilogi77-ogamebot: sacrificial own-fleet battle to farm debris to a moon; gated on persona and a host self-battle seam.
- Jump-gate transfer — janeczkins-xbot: move fleet between own moons; low value, only once moons/gates exist in the cohort.
- Metal-dump research — trilogi77-ogamebot: spend overflow metal on a cheap host-catalogue tech while saving. `FacilityChain.php`.
- Archetype→character-class affinity — hammermaps-ogamex-ai-players: miner/turtle→Collector, fleeter→General; only if provisioning is module-owned.
- Game-phase classification — shinigallo-ogame-agi: host-read opening/mid/late bucket for consultation; YAGNI until a case wants it.
- Consultation confidence gate + horizon — shinigallo-ogame-agi: suppress a low-confidence nudge; horizon-tag deferred advice.
- Solar-satellite energy fallback — piecepapercode-barakis: consider unit-queue energy producers when building candidates are exhausted. `EnergyCapacity.php`.
- Defended-raid debris valuation — ogame-infinity-web-extension, rbardtke-ogamex-combat-sim: conflicts with TP-004/RAID-014 (debris stays out of the single-raid profit gate); resolve doctrine first, re-ground `LOOT_TIER_DEFENDED` in defence rebuild (jstar88-opbe).
- Pre-flight round-trip duration — rbardtke-ogamex-combat-sim: one host `calculateFleetMissionDuration()` call so a save isn't away past the next window; threshold is persona taste.
- CRN screen→confirm ladder — peterradzisz-fleet-optimizer: paired seeds across candidates; dead until U6 gives ≥2 launch subsets.

### P3 seam audit — 17 September 2026

Each item was checked against the host before any code. Verdict per row:

| Item | Host seam | Verdict |
| --- | --- | --- |
| Phalanx fleet scan | `PhalanxService` (range, cost, scan) | **deferred** — seam exists; blocked on an owned moon (none in cohort yet). |
| Moonshot | `AttackMission::checkOwnPlanet` refuses self-attacks | **cut** — no self-battle seam; hitting a neighbour to farm debris is machine-shaped (gate 3). |
| Jump-gate transfer | `JumpGateService` (`getEligibleTargets`, cooldown) | **deferred** — seam exists; blocked on owned moons + gates (none yet), low value. |
| Metal-dump research | `ObjectService::getResearchObjects()` + research queue | **deferred** — seam exists; no case yet, `FacilityChain` already spends surplus. |
| Archetype→character-class affinity | provisioning is host-owned (module never creates users) | **host work** — not a module change; a host-side mapping, not a seam. |
| Game-phase classification | no host phase/bucket concept | **cut** — no seam; YAGNI until consultation wants it. |
| Consultation confidence gate + horizon | no confidence signal in the consultation lane | **cut** — no seam; YAGNI until a case wants it. |
| Solar-satellite energy fallback | `solar_satellite` is a host unit; `EnergyCapacity` already picks producers | **deferred** — seam exists; only the exhausted-buildings branch is missing, low value. |
| Defended-raid debris valuation | doctrine conflict with TP-004/RAID-014 | **deferred** — resolve doctrine first (debris stays out of the single-raid gate). |
| Pre-flight round-trip duration | `FleetMissionService::calculateFleetMissionDuration()` | **confirmed** — seam exists; threshold is persona taste. |
| CRN screen→confirm ladder | gated on U6 launch subsets | **dead** — U6 (≥2 launch subsets) is not shipped. |

## Cross-cutting refusals
| Pattern | Repos | Reason |
|---|---|---|
| Hardcoded object universe (ids, costs, requirements, caps) | most (alaingilbert, eracle, halfguru, r4fek, racinmat, trilogi, barakis, shinigallo, ogame-infinity, peterradzisz, rbardtke, jstar88-*) | gate 1 — host `ObjectService`/`GameObjects` is the only source of truth |
| Second battle engine / combat-formula port | jstar88-opbe, klaasvp-trashsim, maximalcode, patrykstefanski, peterradzisz, rbardtke, jstar88-ogame-algorithms | forbidden duplication — host `BattleEngine`/`PhpBattleEngine` is the single authority `NativeRaidEstimator` samples |
| Scraping / DOM / browser login / fingerprint | alaingilbert, eracle, kweimann, r4fek, racinmat, shinigallo, trilogi | host-side module has no browser session; policy bans the corpus |
| Host-side daemon / poll loop / 24-7 wakeup | halfguru, hammermaps, janeczkins, ogame-ninja, ogame-tbot, barakis, shinigallo | module boundary — scheduled sessions + Horizon, not a daemon |
| Anti-detection / captcha / fake activity | alaingilbert, eracle, ogame-tbot, janeczkins, barakis, ogame-ninja | gate 3 — authenticity is behavioural, not evasion |
| Hardcoded formulas (cost/time/fuel/production/plunder) | jstar88-ogame-algorithms, racinmat, r4fek, ogame-infinity, ogame-tbot, trilogi | host computes them; a module copy is a second authority that drifts |
| Machine cadence (fixed poll, exact wake, optimal speed) | halfguru, ogame-opensource-2, ogame-tbot, trilogi, shinigallo | gate 3 — no human produces it |
| Telegram / WebUI / notification / product surface | janeczkins, kweimann, ogame-ninja, ogame-tbot, r4fek | out of module scope |
| `eval()` engine / GoJS editor / derived bot identity | ogame-opensource-2 | RCE-equivalent / gate 2 / loses purge protection |

## Proposed slice order
1. Debris-field recycling loop (headline; closes the raid/expedition loop and is the shared P1 of 6 repos)
2. Raid estimate — P20 loot quantile, delete `expectedLoot()` (single loot authority)
3. Raid estimate — survival floor (reuses the same estimator stream)
4. Fleetsave destination safety (exclude threatened body + same-coordinate moon)
5. Single-planet fleetsave fallback
6. Weak-attack / nothing-to-save fleetsave gate
7. Storage-before-build preprocessor
8. Standing defence pass
9. Expedition fleet composition + system rotation
10. Colony slot scoring (+ abandon once the host seam is verified)
11. Recall scheduling at half-duration
12. Surplus consolidation transfer
13. Defenceless-target signal + confidence downgrade
14. Probe escalation + closest spy origin
15. Raid outcome feedback (cooldown + blacklist + real-loot attribution)
16. Liveness floor, then no-op idle override + anti-bot self-check
17. P3 investigate list (phalanx, moonshot, jump gate, …) as seams are confirmed

## Per-repo residue audit — do the 23 per-repo plans still carry work? (17 September 2026)

`RP-001..RP-023` in the task DB tell an agent to *"execute this repo's plan … only repo-specific items
here"*. This audit tests that claim against the mapping above and against what has shipped.

**Method.** Extract every ADOPT-IDEA / ENHANCE mechanism row from the 23 `plans/*.plan.md` files (74
rows), match each mechanism to the `WP-*` slice whose source list names that repo, then check whether
that slice is shipped.

| Repo (RP row) | Mechanisms | Mapped to | RP status after audit |
| --- | --- | --- | --- |
| alaingilbert-ogame (RP-001) | defenceless-report signal; phalanx scan; section-based intel confidence | WP-013, WP-019 | deferred |
| eracle-ogamebot (RP-002) | none — "nothing to adopt, nothing to enhance" | — | done |
| halfguru-ogamebot (RP-003) | never save into a threatened body; colonise bigger slots | WP-004 (done), WP-010 | deferred |
| hammermaps-ogamex-ai-players (RP-004) | anti-bot self-check; archetype→class affinity; rare no-op | WP-018, WP-019 + **own residue** | todo |
| jaesivsm-pyogame (RP-005) | debris recycling | WP-001 (done) | done |
| janeczkins-xbot (RP-006) | harvest-save; jump gate; surplus transfer; colony abandon; weak-attack save | WP-005 (done), WP-006, WP-010, WP-012, WP-019 | deferred |
| jstar88-ogame-algorithms (RP-007) | none — the host already answers every formula it hardcodes | — | done |
| jstar88-opbe (RP-008) | lower-tail loot (ships as WP-002) | WP-019 | deferred |
| klaasvp-trashsim (RP-009) | outcome buckets; typical-case mean | WP-002 (done), WP-003 (done) | done |
| kweimann-cruiser (RP-010) | expedition debris harvest; save-destination ranking | WP-001 (done), WP-004 (done) | done |
| maximalcode-combat-sim (RP-011) | battle-outcome distribution | WP-003 (done) | done |
| ogame-infinity-web-extension (RP-012) | expedition composition + rotation; defended-raid debris valuation | WP-009, WP-019 | deferred |
| ogame-ninja-scripts (RP-013) | debris recycling; message the attacker; standing defence; surplus transfer | WP-001 (done), WP-008, WP-012, WP-016 | deferred |
| ogame-opensource-2-ai (RP-014) | purge / inactivity exemption | WP-017 | deferred |
| ogame-tbot-tbot (RP-015) | harvest-save; probe-only inbound; recall timing; spy origin | WP-005 (done), WP-006, WP-011, WP-014 | deferred |
| patrykstefanski-og-battle-engine (RP-016) | none — wholesale REFUSE, the host engine matches it | — | done |
| peterradzisz-fleet-optimizer (RP-017) | survival floor (ships as WP-003) | WP-019 | deferred |
| piecepapercode-barakis (RP-018) | solar-satellite energy fallback; standing defence | WP-019, WP-008 | deferred |
| r4fek-ogame-bot (RP-019) | debris harvest | WP-001 (done) | done |
| racinmat-phpogamebot (RP-020) | storage-before-build; probe escalation | WP-007, WP-014 | deferred |
| rbardtke-ogamex-combat-sim (RP-021) | pre-flight round-trip duration; defended loot tier | WP-019 ×2 | deferred |
| shinigallo-ogame-agi (RP-022) | game-phase bucket; consultation confidence gate + horizon | WP-019 ×2 | deferred |
| trilogi77-ogamebot (RP-023) | debris; moonshot; raid cooldown + blacklist; metal-dump; save/expedition/colony/defence/outcome enhancements | WP-001 (done), WP-004 (done), WP-008…WP-015, WP-019 ×3 | deferred |

**Status semantics used above.** `done` = nothing left for this repo (its mechanisms shipped, or it
had nothing adoptable). `deferred` = every remaining idea is already indexed elsewhere (`WP-006`…
`WP-019`), so the row is a pointer, not work. `todo` = the single row with residue of its own. Slice
codes are annotated `(done)` only where that matters to the verdict; for everything else the live status
is the task DB's.

**Finding.** The premise that each per-repo row holds work of its own does **not** hold: 22 of the 23
repos' mechanisms are already indexed as shared `WP-*` slices, and the remaining one adds a three-line
docblock. Five repos are complete because everything they contributed ships in `WP-001`–`WP-004`
(jaesivsm, klaasvp, kweimann, maximalcode, r4fek); three contributed nothing adoptable at all (eracle,
jstar88-ogame-algorithms, patrykstefanski). The other fourteen are **pointers**: their content lives in
`WP-006`–`WP-019`, which are the rows to start.

**The one genuine residue (hammermaps).** `app/Enums/AiArchetype.php`'s docblock should record the fold
onto the repo's named profiles — raider→Fleeter (Raid 0.9), defensive→Turtle (QueueUnits 1.0),
neutral→Casual — so a later reader stops re-deriving it. Doc-only; no behaviour, no test.

**Recommendation (coordinator call, recorded not decided here).** Treat `RP-*` as provenance rather than
a work queue: delete the family once this audit is accepted, or keep it read-only. A pickup agent should
always start from `WP-*`.
