# Aspect coverage — what an account can actually do

Snapshot, 30 September 2026. Produced from four read-only audits of the module plus direct code reads,
and from the task DB (`plan/tasks/tasks.db`) and the plan corpus. **Caveat:** the tree was mid-slice by a
concurrent writer while this was taken (new actions, new `resources/behavior/*` files, `app/Ai/**` being
deleted), so file-level rows are a snapshot, not a claim about the current working tree.

This document answers one question: **for each aspect of the game, can an AI account actually do it end
to end today — or does something exist that only looks like coverage?**

It is not a wish list. Coverage here means the whole chain: policy → candidate or published capability →
executor → host action, with a test on the end-to-end path. Everything else is a hole, and each hole is
labelled so nobody has to guess which kind it is.

**A `Wired` verdict means the chain exists, not that the behaviour has been observed.** Whether an aspect
has ever actually happened in live play is the other half of coverage, measured on both cohorts in
[play coverage](../specs/play-coverage.md) — see §12.

| Verdict | Means |
| --- | --- |
| **Wired** | The whole chain exists and is exercised end to end |
| **Partial** | The chain exists but a named link is missing — the link is always named |
| **Dead** | The code exists and nothing calls it |
| **Deferred** | A recorded decision to build later, with the id or the review that records it |
| **Excluded** | The plan says no, quoted in §6 |

---

## 1. The two surfaces a decision can reach

Worth stating first, because it stops two false "gaps":

- **Published capabilities are six**: `build`, `research`, `queue_units`, `spy`, `colonize`,
  `throttle_mine` (`PlayerObservationService::ownedState()` → `available_actions`). **Every one of them
  has a real executor behind it** (`QueueAiBuilding/Research/Units/Spy/Colony/MinePercentAction`).
- **Everything else is a candidate**, built by `CandidateActionFactory` from separate signals
  (`target_reports`, `fleetsave_eligible`, `recall_eligible`, `fleet_slots_free`, `colonize_eligible`,
  `reaction_wake_at`): raid, fleet save, recall, expedition, transfer, recycle, phalanx. Each also has a
  real executor. So "not published" is not the same as "cannot be done", and this table does not treat it
  as one.

---

## 2. Defence

| Aspect | Verdict | Policy | Executor + host call | Covering tasks | Missing link |
| --- | --- | --- | --- | --- | --- |
| Wall composition (which unit next) | **Wired** | `DefenseCompositionPlanner` over `defence-doctrines.yaml` (anchors + ratios, `DEFAULT_DOCTRINE = 'balanced'`), largest shortfall first | `QueueAiUnitsAction` → `UnitQueueService::add` | PERS-005, DEF-36 | — |
| Wall **sizing** / when to build | **Partial** | `DefenseNeedEvaluator` — demand from absence hours × inbound pile | same | **DEF-38**, QUAL-003, PERS-004 | `minimum_deterrent: 4000` in the YAML is `NOT READ BY CODE YET`; a fresh planet below the wall's own value gets none, and standing defence can leave naked planets beside a walled one |
| Reactive + standing defence roles | **Wired** | `QueueableUnitPlanner` role order (power → cargo → reactive defence when under attack → … → standing defence) | `QueueAiUnitsAction` | WP-008 | — |
| Repair / rebuild after battle | **Partial (host-owned)** | none in the module | host battle engine restores 70 %; `Support/SpaceDockRepair` is dead | WIK-093, GF-007 | no module policy at all — acceptable while the host owns it, but nothing verifies the account re-buys what was destroyed |
| Shield domes | **Partial** | doctrine ratios list one of each | `QueueAiUnitsAction` | WIK-051 | dome *behaviour* class is dead; the one-per-planet fact is not expressed |
| ABM / defence against incoming missiles | **Dead** | `Domain/Defense/AntiBallisticMissile`, `RecommendAntiBallisticMissiles` (`MISSILES_PER_TURRET = 2`, `MAX_MISSILES = 70`) | none — every one referenced only by its own test | WIK-020, WIK-025, WIK-026 | nothing sizes ABMs against a silo level, and nothing reacts to an inbound `MissileMission` |
| Retreat / recall defence | n/a | — | — | — | not an OGame mechanic; only fleets recall (`QueueAiRecallAction`) |

---

## 3. Attack, raids and waves

| Aspect | Verdict | Policy | Executor + host call | Covering tasks | Missing link |
| --- | --- | --- | --- | --- | --- |
| Target selection + phase gate | **Wired** | `RaidPlanner::targetEligible()`; `GamePhase` from astrophysics 23 / second planet | `QueueAiRaidAction` → `FleetMissionService::createNewFromPlanet(… AttackMission …)` | RV-011, IMPL-014 | no relationship/archetype tie-break (RAID-007) |
| Bashing limit, cooldown, blacklist | **Wired** | `BASHING_LIMIT = 6`, `RAID_COOLDOWN_HOURS = 6`, `BLACKLIST_LOOT_FLOOR = 10_000.0` | gates the candidate | **DEF-33** | two further copies of the cap exist unwired, with a third value |
| Profit / survival gate | **Wired** | `SURVIVAL_FLOOR = 0.8`, `LOOT_TIER_FARM = 3.0`, `LOOT_TIER_DEFENDED = 2.0` | gates the candidate | WP-002/003, RV-003 | — |
| Fleet sizing, cargo, counter-selection | **Wired** | `RaidPlanner::launchUnits()` uses the **host rapid-fire graph**, screen-then-confirm (`CONFIRM_SAMPLES = 50`) | inside `QueueAiRaidAction` | RV-002, RV-007, WP-002 | — |
| **Wave farming / multi-hit** | **Dead** | `WaveFarmPlanner`, `RaidWavePlan`, `DailyAttackBudget` | none — own-file references only | QUAL-5, **DEF-33**, WIK-091/122/146 | the live path flies exactly one mission per target, so the account never behaves like a farmer |
| Offensive missiles (IPM) | **Missing** | none | host `MissileMission` — no module caller | — | see DISC-14 (§5) |
| Moon destruction | **Missing** | none | host `MoonDestructionMission` — no module caller | RV-013 (deferred), CRASH-005 | see DISC-14 (§5) |
| ACS attack / union | **Missing** | none; `acs-coordination.yaml` marks ACS-001…014 `supported but unwired` | host `FleetUnionService` — no caller | WIK-126, WIK-223, DEF-032 (deferred) | the account never joins or forms a union |

---

## 4. Fleet, logistics and debris

| Aspect | Verdict | Policy | Executor + host call | Covering tasks | Missing link |
| --- | --- | --- | --- | --- | --- |
| Fleet slots | **Wired** | `freeFleetSlots()` gates every dispatch candidate | — | IMPL-035 | — |
| Fleet save (incl. shadow split, jump gate) | **Wired** | `QueueableFleetSavePlanner` — `PROACTIVE_SAVE_MIN_ABSENCE_MINUTES = 120`, exposure bands 5k/25k/50k, jump-gate path first, shadow split at 2 × band | `QueueAiFleetSaveAction` → `DeploymentMission`; `JumpGateService::transferShips()` | IMPL-016, WP-004/005/006, RV-009 | post-save window unmodelled (FS-008) |
| Save that can fail | **Wired** | `SaveFailurePolicy` — `DENOMINATOR = 30`, `'overnight_gamble'`, publishes the skip reason | suppresses the candidate | G9, Package 4 | the rate is a documented placeholder |
| Recall | **Wired** | `recallPlan()` — own in-flight deployment, `arrival + half + jitter` | `QueueAiRecallAction` → `FleetMissionService::cancelMission()` (module adds the ownership check the host lacks) | IMPL-017, WP-011 | — |
| Transport / transfer | **Wired (own planets only)** | `QueueableTransferPlanner` — `MINIMUM_SHIPMENT = 50_000`, `SURPLUS_RATIO = 0.8` | `QueueAiTransferAction` → `TransportMission` | WP-012, W11-1 | rejects any non-owned target (`PlanetNotOwned`), which is what blocks helping an ally (§5) |
| Expedition | **Wired (open-loop)** | `QueueableExpeditionPlanner` — position 16, rotation window 24 h, smallest civil hull | `QueueAiExpeditionAction` → `ExpeditionMission` | WP-009, IMPL-020 | **nothing reads an expedition result** — no loot, no black-hole loss, no adaptation; `ExpeditionDurationPolicy` is dead and the action duplicates its constants |
| Recycle / debris collection | **Wired** | `QueueableRecyclePlanner` — `MIN_FIELD_MASS = 10_000`, 20 largest fields, skips covered coords | `QueueAiRecycleAction` → `RecycleMission` | WP-001 | no arrival-timing model (CRASH-008); debris is public in this host (no owner column), so "who owns it" is not a gap |
| Phalanx scan | **Wired** | `QueueablePhalanxPlanner` — needs a moon with `sensor_phalanx > 0`, scan TTL 2 h | `QueueAiPhalanxAction` → `PhalanxService::scanPlanetFleets()` | RV-008, WIK-096 | the scan result only feeds `RaidPlanner::phalanxRefuses()` |
| Fleet composition / counters | **Partial** | roles in `QueueableUnitPlanner`; per-target counters in `RaidPlanner::launchUnits()` | `QueueAiUnitsAction` | IMPL-018, RV-002, FLE-004…011 | no recycler or fodder role in the *production* planner, no stage model |

---

## 5. Economy, growth and their holes

| Aspect | Verdict | Policy | Executor + host call | Covering tasks | Missing link |
| --- | --- | --- | --- | --- | --- |
| Mine choice / payback | **Wired** | `EconomyUpgrades::production()` — payback key, `PAYBACK_CAP_HOURS = 168.0`, persona variation, remembered bias | `QueueAiBuildingAction` → `BuildingQueueService::add` | IMPL-063, WP-007 | — |
| Facility / prerequisite chain | **Wired** | `FacilityChain::pending()` — cheapest unproducible ambition, ordered by unmet dependencies | build / research executors | IMPL-022, IMPL-045 | — |
| Energy and mine throttling | **Wired** | `EnergyCapacity::shortfall()`; `QueueableMinePercentPlanner` (`FULL_PERCENT = 10`) | `QueueAiBuildingAction`, `QueueAiMinePercentAction` → `setBuildingPercent()` | RV-001, RV-004 | — |
| Reserve floor, storage, surplus | **Wired** | `ReserveFloor` (`BUFFER = 0.10`, 4 h economy / 6 h research); `storage()` / `spendSurplus()` / `researchDump()` | build / research executors | SP5, RV-010 | — |
| **Mine mix doctrine** | **Dead data** | `resources/behavior/mine-upgrade-ratio.yaml` | **nothing in `app/` reads it** — only its own test | **IMPL-68**, WIK-061, WIK-183 | the mine mix is whatever payback ranks; no doctrine keeps metal/crystal/deuterium in step |
| **Research doctrine** | **Partial** | research is chosen only to *unlock*: the cheapest unproducible ambition, a mission's required level, or a fleet-slot ceiling | `QueueAiResearchAction` → `ResearchQueueService::add()` | **IMPL-66**, IMPL-045 | nothing raises **Astrophysics** past the mission minimum, so `getMaxPlanetAmount()` and `getExpeditionSlotsMax()` never grow — the colony and expedition planners hard-stop (`QueueableColonyPlanner:61`, `QueueableExpeditionPlanner:57`) |
| Colonisation | **Wired** | `QueueableColonyPlanner` — `MAX_SCANS = 600`, reach-checked walk, largest fields | `QueueAiColonyAction` → `ColonisationMission` | IMPL-037, WP-010 | no post-founding seeding of a new colony (WIK-139, WIK-183) |
| Resource hiding | **Missing** | class does not exist | — | WIK-028 | only a proposal and a failure log; note build-then-cancel hiding is a bannable exploit, guarded in the verification notes |
| **Growth feedback** | **Open loop** | `RecordAiScoreSamplesAction` collects one row per account-hour; read by `AiScoreReport` for the operator page only | — | **IMPL-69**, DISC-13 | no planner, scorer or scheduler consumes a sample or a score, and there is no detector for "this planet has not grown in N hours" |

---

## 6. Diplomacy, alliance and social

| Aspect | Verdict | Policy | Executor + host call | Covering tasks | Missing link |
| --- | --- | --- | --- | --- | --- |
| Join / create / review / buddy | **Wired** | `AllianceChoice` (rank tiers `0.15`/`0.5`, skips an exploitative founder); `AdvanceAiAllianceLifeAction` (`FIRST_ALLIANCE_TAG = 'ORBITAL'`); `ReviewAiAllianceApplicationsAction` (`MAXIMUM_ACCEPTS_PER_PASS = 1`); `ReviewAiBuddyRequestsAction` | `AllianceService::{applyToAlliance, createAlliance, acceptApplication, rejectApplication}`, `BuddyService::acceptRequest()` | DEF-017, DEF-006/007/009, IMPL-059 | — |
| **Leave / kick / disband** | **Missing** | none | host `AllianceService::{leaveAlliance, kickMember, disbandAlliance}` — **no module caller** | **IMPL-70** | membership is one-way: an account can join an alliance and never leave one |
| Invitations | **Closed by decision** | `ReviewAiInvitationsAction` does not exist and is not needed | — | DEF-019 (closed: nothing to build) | — |
| Relationships, trust, hostility from battles and chat | **Wired** | `RecordAiRelationshipInteractionAction`; battle loser `trust −0.20, threat +0.30, affinity −0.10`; coercive warning `trust −0.10, threat +0.15` | module-owned records | DEF-011/013/026 | probe hostility is not observed (WIK-161) |
| Native social protocol | **Wired** | `NativeSocialCognition` — `MAX_OUTSTANDING_COMMITMENTS = 3`, `OUTSTANDING_DEBT_PENALTY = 0.5`, `FORGIVENESS_TRUST_FLOOR = 0.5`, `standingWeight()` | `QueueAiSocialExchangeReplyAction` → sealed delivery | DEF-012/014, 24 tests | trade, ceasefire and cooperation return capability-safe refusals, because the module cannot execute them |
| Conversation and authored dialogue | **Wired** | `RunAiConversationCycleAction` (`MAXIMUM_RESPONSE_TURNS = 2`); `ClassifyInboundSocialExchangeAction` (bounded matcher, 11 types); `BuildAuthoredSocialReplyAction` (`REPETITION_COOLDOWN_DELIVERIES = 2`) | `DeliverAiDirectReplyAction` → `ChatService::sendDirectMessage()`; alliance chat via `sendAllianceMessage()` | WP-016, DEF-008/018/030 | free-text understanding is deliberately bounded; hosted classification is gated (JEV-*, REV-8/9) |
| Language escalation | **Wired, off for AI-to-AI** | `ConversationRoutePolicy`; `languageEnabled()` && route `Realization`; `AI_LANGUAGE_AI_TO_AI=false` | `GenerateAiReply` job → `LanguageGateway` (deepseek) | LLM-003/005, DEF-027 | one language: "English is the only supported language" |
| Social initiation | **Partial** | `InitiateAiSocialContactAction` — `MAXIMUM_INITIATIONS_PER_SESSION = 5`, `WELCOME_SOCIABILITY_FLOOR = 0.5` | direct delivery | DEF-018/029/030 | only thank-you and welcome ship; report sharing and "greeting the player who probed me" wait on host surfaces |
| **Acting on an enemy** | **Deferred** | no retaliation executor; the only hostile act is a chat line (`AttackerNotice`) | — | **DEF-032 (deferred)**, DEF-031 | the AI fleetsaves and defends but never counter-attacks; no ceasefire is ever enforced |
| **Helping an ally** | **Missing** | none | `QueueAiTransferAction` refuses a non-owned target | **IMPL-71**, DEF-031, DEF-010 | an ally can be observed under attack (`AllyUnderAttack`) and still never helped; that observation has no production reader |
| ACS defence | **Missing** | none | host `AcsDefendMission` — no caller | WIK-126/223, DEF-032 | — |
| Trade, NAP, war | **Excluded / talk-only** | ceasefire answers `Clarify / CeasefireEnforcementUnavailable`; trade `Reject / TransportCapabilityUnavailable` | — | WIK-199/205/088 | no marketplace exists in the host (verified), so trade cannot be executed; NAP and war are conversation, not state |

---

## 7. Intelligence about people and planets

| Aspect | Verdict | Notes | Covering tasks |
| --- | --- | --- | --- |
| Target intel (planets) | **Wired** | `target_reports` from the account's own espionage reports, `INTEL_TTL_HOURS = 24`, `VIABILITY_SCORE_DIVISOR = 5`; feeds raid eligibility | IMPL-013/014/015 |
| Who is scouting me | **Missing** | no inbound-probe observation; counterespionage is spec-only and unconfirmed | WIK-161 |
| Who attacks whom | **Wired** | `RecordObservedBattleReportAction` (both named sides, `REPEATED_SETBACK_LOSSES = 2`) | DEF-011, DEF-026 |
| Ally under attack | **Partial** | observed (`AiObservationKind::AllyUnderAttack`) but nothing reads it | DEF-010, IMPL-71 |
| What is remembered about a rival | **Write-only** | `AttackReceived` and `AllianceMembership` facts are written and never queried; `FindCurrentAiMemoryFactsAction` has no production caller; the only live recall inspects `ResourceDebt` on a help request | QUAL-5 |

---

## 8. Authenticity, routine, operability and the campaign

| Aspect | Verdict | Notes |
| --- | --- | --- |
| Session scheduling, uptime shape, absences | **Wired** | `SessionPlanner` (540-min core dark, ±30-min drift, Weibull shape 0.8, absence draws); G10/G11 closed and measured |
| Reaction latency to a probe or attack | **Wired** | `inboundThreat()` — 120–180 s before impact over the host's own 10 s floor; the authenticity panel measures it |
| Save-failure rate, growth curve, entropy | **Wired (collection)** | `saveOutcomes()`, `ai_score_samples`, `interactionEntropy` vs `ENTROPY_BASELINE = 0.84` — see the open loop in §5 |
| Operability | **Wired** | caps, kill switch, replay, pruning, budget wall, pilot report; nothing in this document is blocked by operability |
| PvE / cooperative campaign | **Wired, lane off by default** | campaign records, objectives, faction momentum, consultation lane, hostility policy (host pair committed); the in-game alliance/ACS half is DEF-003 |
| Improvement loop | **Partial** | `specs/improvement-loop.md`: "A tuning change replaces a `placeholder` constant with a measured one" — the table is still empty, so `SaveFailurePolicy` 1/30 and the absence rates remain uncalibrated |

---

## 9. Never to be built (excluded by the plan)

Quoted, because a coverage table that does not separate these from holes will keep re-raising them:

| Excluded | Where it is written |
| --- | --- |
| A second combat engine, in any language | `host-change-request.md`: "No combat implementation, in any language… adopting or mirroring it would create a second authority that drifts"; `WORK-PACKAGES.md`: "Do not create an AI-only combat engine" |
| Marketplace / trade | `host-change-request.md`: "No marketplace, trade request or resource exchange. None exists, the plan now says so plainly" (verified in the host) |
| Monetisation, premium officers | `product.md`: "Do not rebuild monetization, the combat engine, a market, quests or core progression" |
| Lifeforms | `wiki-corpus.md`: "excluded-ruleset … `Lifeform`, `Lifeform Research` — **hard drop**" |
| Entering vacation mode, honour points, fabricated footprint | `product.md` (normal inactivity behaviour); host honour is a TODO; GAP-REGISTER A4/I7 (no fabricated IP or page cadence) |
| Killing someone else's moon, launching missiles **in PvE** | `pve-empire.md`: blocked through the host's authoritative mode restrictions; `DestroyMechanicsUnconfirmedTest` pins it in normal play |
| Framework split, generic planner, mandatory sidecars | `WORK-PACKAGES.md`: "Do not build: a framework/package split, generic game planner, mandatory sidecars…" |

---

## 10. Coverage drift to fix (a hole in the plan, not the game)

A reader who trusts the plan's own paperwork over-claims today. Recorded as `DOC-8`:

1. **`GAP-REGISTER` G8 cites "DEF-001"** for the reaction wake, but in the task DB `DEF-001` is *"Ceiling
   the standing wall"* — the citation resolves to an unrelated row.
2. **Wave-6 prose is stale**: it still says W6-1…W6-5 are "planned, blocked on catalog review" and "No row
   is closed by this pass", although `REV-001` and `IMPL-013…IMPL-018` are done.
3. **F1/F4/F5/F6 have no gap row.** They are deferred only inside a review's JSON while `IMPL-017` reads as
   covering "F1–F6 … done" — so a task-title read over-claims four fleeter behaviours that are not built.
4. **No `AUTH-*`/`SP3`/`AG2` task codes exist**, so the authenticity work is traceable only through
   algorithm ids and `DEF-020…025`.

---

## 11. What this says, in one paragraph

The mechanical spine is in better shape than the register implies: **every published capability has a real
executor**, raids already counter-select from the host's rapid-fire graph, fleetsave handles shadow splits
and jump gates, and the social protocol has earned forgiveness and outstanding-debt penalties. The real
holes cluster in four places, and none of them is "the account cannot act": it is **open loops** (an
expedition whose result nothing reads, score samples nothing plays from), **hard-stops** (research that
never raises the ceiling, so colonies and expedition slots stop growing), **one-way life** (an alliance it
can join but never leave, an ally it can watch but never help, an enemy it can dislike but never answer),
and **dead branches** (waves, ABM/IPM, moon destruction, ACS) that exist as code or as a host API with no
caller. The first three are queueable work; the fourth needs a decision per branch, which is why `DISC-14`
asks for one.

---

## 12. Two layers, and why both are needed

- **This document** answers *can it happen*: the chain exists in code and a test exercises the end-to-end
  path. It is static, and it covers every aspect whether or not a universe has ever exercised it.
- **[Play coverage](../specs/play-coverage.md)** answers *has it happened*: P1 rows measured on both live
  cohorts — no attack mission in 24 h, no social exchange in 24 h, one fleetsave in a cohort's lifetime, no
  account owning a recycler.
- Neither substitutes for the other, and they fail in opposite directions. An aspect whose chain is
  complete but which never runs is `Wired` here and broken there: raids are wired in §3 and have not flown
  since 28 September (`ATK-001`). An aspect that does run but whose *result* is thrown away is invisible
  there and visible here: nothing reads an expedition outcome (`IMPL-67`) and the mine-ratio doctrine is
  read only by its own test (`IMPL-68`).
- **Read them together.** A `Wired` verdict means "nothing is missing in code", not "this works in the
  universe"; the live ledger is the authority on the second and this table is the authority on the first.
