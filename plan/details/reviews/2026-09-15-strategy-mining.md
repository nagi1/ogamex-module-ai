# Review record — strategy mining kickoff (15 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md). No
runtime cost: this pass read existing research and code only.

```json
{
  "window": "strategy-mining-kickoff",
  "date": "2026-09-15",
  "artifacts": {
    "plan": "specs/strategy-mining.md",
    "source_registry": "research/source-registry.md",
    "principles_catalog": "research/strategy-principles.md"
  },
  "counters": {
    "principles_total": 107,
    "principles_shipped": 21,
    "principles_partial": 7,
    "principles_researched": 76,
    "principles_deferred": 3,
    "principles_gap": 0,
    "counts_note": "final counts after the gap pass; the earlier 67/79/83 tallies undercounted",
    "host_mechanics_supported_but_unwired": ["phalanx", "moon", "jump_gate", "acs_attack", "acs_defend", "debris_recycle", "activity_timestamp", "recall"]
  },
  "findings": [
    "raid: activity risk, per-type intel decay, travel/slot cost, personality/relationship all absent; travel_cost placeholder 0.0",
    "spy: first-fit in id order, no target score",
    "fleetsave: reactive only (currentPlayerUnderAttack), first-other-planet, no proactive exposure",
    "units: fixed role order, cargo fixed at 1, no composition/counters",
    "economy: payback constants archetype-blind (skill_band + routine only)",
    "host supports phalanx/moon/jumpgate/ACS/debris/activity/recall but no module caller reaches them"
  ],
  "next": "implementation slices blocked on catalog review; U-series fleet composition is the next increment",
  "research_pass_2": {
    "date": "2026-09-15",
    "agents": 3,
    "new_principles": 25,
    "sources_recovered": ["ORG-008 Tactic 05a (Wayback)", "ORG-011 Tutorial 15 Moon (Wayback)", "WIK-004 Rapid_Fire (?action=raw)"],
    "corrections": ["same-planet relocation is NOT recallable; deploy between two own planets is (fixed CRASH-003)"],
    "contradictions_recorded": ["RAID-014 debris-in-profit vs module doctrine", "FS-007 buffer magnitude 10-20 vs 30-60 min"]
  },
  "research_pass_3": {
    "date": "2026-09-15",
    "agents": 3,
    "acs_principles": 12,
    "acs_anchor": "GF-003 (Gameforge alliance guide); ORG-009/010 Wayback captures failed, stay open",
    "architecture_mapping": "research/architecture-mapping.md — 43 researched principles mapped to code",
    "key_finding": "host supports phalanx/moon/jumpgate/debris/recycle/ACS/recall but zero module callers"
  },
  "research_pass_4": {
    "date": "2026-09-15",
    "classical_patterns": 23,
    "catalog": "research/classical-ai-patterns.md",
    "algorithm_blocks": ["SP7", "T6-T8", "N4-N5", "V6-V8", "F1-F6"],
    "blocks_status": "planned, blocked on catalog review",
    "hypotheses_hold": ["H1", "H2", "H3", "H5", "H6", "H7", "H9", "H10"],
    "hypotheses_plausible": ["H8"],
    "hypotheses_open": ["H4"]
  },
  "research_pass_5": {
    "date": "2026-09-15",
    "claims_catalog": "research/strategy-claims.md",
    "claim_types": 8,
    "contested_claims": 7,
    "principles_total_final": 83,
    "canonical_fold_back": ["specs/decision-policies.md", "WORK-PACKAGES.md"],
    "integration_gates": 10,
    "m6_m7_scope": "pattern-level; vanilla-vs-mod file diff deferred"
  },
  "research_pass_6": {
    "date": "2026-09-15",
    "agents": 3,
    "gap_domains_closed": ["ninja_baiting", "non_english_de_pl", "fleet_composition", "moon_economics", "colony_positioning", "expeditions"],
    "new_sources": 26,
    "new_principles": 24,
    "new_domains": ["ninja", "expeditions"],
    "principles_total_final": 107,
    "still_open": ["ORG-009/010 ACS tutorials", "board.fr guide library URLs", "ogamewiki.de"]
  },
  "doc_pass_7": {
    "date": "2026-09-15",
    "blocks_written": ["U5/U6 (W6-4 fleet composition)", "NN1/NN2 (ninja)", "EX1 (expeditions)"],
    "gap_index_rows_added": 3,
    "gap_register_updated": "W6-4 + pass-6 ninja/expedition now have algorithm blocks; W6-6 still rides SP3 (partial)",
    "task_db": "DOC-001/002/003 marked done; ready set empty until REV-001 clears"
  },
  "impl_013": {
    "date": "2026-09-15",
    "slice": "SP7 — the activity and intel reader",
    "code": ["app/Domain/Perception/ActivityIntelReader.php", "app/Domain/Perception/PlayerObservationService.php", "app/Domain/Perception/PlayerPerceptionBuilder.php"],
    "reader_signals": ["activity_at", "moon_only_activity", "intel_confidence", "activity_probability_at_eta"],
    "wiring": "targetReports publishes per-report confidence (fast-type freshness) and activity (target activity star); perception builder passes activity through",
    "tests": ["tests/Feature/ActivityIntelReaderTest.php"],
    "verification": "quality green (641 tests), changed files 100% PCOV",
    "pre_existing_coverage_gaps": ["CandidateActionFactory.php (65/72)", "UtilityScorer.php (30/31)", "AiCandidateReason.php (1/2)", "ProcessAiWork.php (139/140)", "AIServiceProvider.php (54/55)"],
    "notes": "fixture fixes for the warehouse-first pass applied to AiActivityMarkerTest and ExecuteIntentTest research test (funded accounts now hold their balance)"
  },
  "impl_014": {
    "date": "2026-09-15",
    "slice": "Raid depth — T6/T7/T8",
    "code": ["app/Domain/Decision/RaidPlanner.php", "app/Actions/QueueAiRaidAction.php", "app/Domain/Perception/PlayerObservationService.php", "app/Enums/AiQueueActionReason.php", "app/Domain/Decision/CandidateActionFactory.php", "app/Enums/AiCandidateRejectionReason.php"],
    "shipped": ["round-trip fuel in the profit gate (RAID-006)", "tiered loot-to-fuel gate 3:1 farm / 2:1 defended (RAID-011)", "cargo-capped expected loot (RAID-012)", "launch-time activity re-check with new TargetActiveAtDispatch reason (RAID-010)", "normalized distance travel_cost in the perception (RAID-006)", "RAID-008 score-ratio pre-filter: targetReports publishes score_viable from the host public highscore (general), and the factory rejects score_below_viability before the estimator runs; an unknown own score filters nothing", "RAID-009 storage-fill schedule: RaidPlanner::storageReady gates the raid on the fleet planet's warehouse near-full (0.8 of capacity), and the factory rejects storage_not_full until it is"],
    "deferred_p2": ["target-choice score refinements RAID-007/009-clustering/013 (relationship, proximity clustering, contest)"],
    "tests": ["tests/Feature/RaidDepthTest.php"],
    "fixture_fixes": ["RaidExecutorTest launch test and ExecuteIntentTest raid test now age the target activity star before dispatch"],
    "verification": "RaidDepthTest 9/9 green (4 prior + 3 pre-filter + 2 storage schedule); DeterministicSessionLoopTest, PersonaPolicyMechanicsTest, SpyDepthTest, ColonySpyExecutorTest, AiCapabilityPublicationTest, ProcessAiSessionTest, ProcessAiWorkTest, RunDueAiWorkTest, ExecuteIntentTest, RaidExecutorTest, CoverageCompletionTest, ArchitectureTest all green; gate 2 review exit 0"
  },
  "impl_015": {
    "date": "2026-09-15",
    "slice": "Intelligence depth — N4/N5",
    "code": ["app/Domain/Decision/QueueableSpyPlanner.php"],
    "shipped": ["spy target scored by known yield minus distance instead of id-order first-fit (INT-003)", "just-touched active targets skipped (INT-009)", "known-yield from prior reports of any age"],
    "n5_note": "dispatch-and-disappear is already satisfied by QueueAiSpyAction (no post-dispatch work); no change needed",
    "tests": ["tests/Feature/SpyDepthTest.php"],
    "fixture_fixes": ["ColonySpyExecutorTest and ExecuteIntentTest spy fixtures now age the target activity star"],
    "verification": "quality green (647 tests), changed files 100% PCOV"
  },
  "impl_016": {
    "date": "2026-09-15",
    "slice": "Save depth — V7 route scoring + FS-006 cargo lift + V6 proactive save + V8 shadow waves",
    "code": ["app/Domain/Decision/QueueableFleetSavePlanner.php", "app/Actions/QueueAiFleetSaveAction.php", "app/Domain/Scheduling/SessionDecisionService.php", "app/Domain/Perception/PerceptionSnapshot.php", "app/Domain/Perception/PlayerPerceptionBuilder.php", "app/Domain/Decision/CandidateActionFactory.php", "app/Enums/AiCandidateReason.php", "app/Domain/Decision/QueueableFleetSave.php", "app/Contracts/QueueAiFleetSave.php", "app/Actions/ScheduleAiIntentAction.php", "app/Actions/ExecuteAiIntentAction.php"],
    "shipped": ["save destination scored to the farthest own planet instead of first-fit (FS-005)", "save lifts the planet stock up to cargo capacity (FS-006)", "V6 proactive save: the session plan is computed before the decision and the decision sees the upcoming absence; a save is offered only past a 120-minute gap and only when the fleet left behind clears the persona's exposure band (fleeter 5k, trader 25k, miner/turtle/casual 50k raw-price units, defence excluded)", "V8 shadow waves: a large fleet is split across two own bodies — combat hulls to the safer body, civil hulls (with the lifted stock) to the next — when a second body, a free second slot, both hull roles and a fleet twice the persona's band are all present"],
    "deferred": ["FS-008 dispatch masking is satisfied by the routine cadence, no change", "V1/V7 full top-k route × speed enumeration and staggered landing times — the shadow split is two bodies, not the whole enumeration"],
    "tests": ["tests/Feature/SaveDepthTest.php", "tests/Feature/ProactiveSaveTest.php", "tests/Feature/ShadowWaveTest.php"],
    "verification": "ShadowWaveTest 3/3, ProactiveSaveTest 5/5 green; 25 affected Feature/Unit suites green (SaveDepth, DeterministicSessionLoop, PersonaPolicyMechanics, ProcessAiSession, ProcessAiWork, RunDueAiWork, ExecuteIntent, RecallDepth, ExpeditionDepth, CoverageCompletion, Architecture, AiCapabilityPublication, UnitComposition, UnitPlannerRoles, SpyDepth, RaidDepth, TransferDepth, QueueActionGuards, AiActivityMarker, ColonySpyExecutor, RaidExecutor, EconomyUpgrades, BuildingChainReachability, EnergyCapacity, ReserveFloor, DecisionEngine); gate 2 review exit 0"
  },
  "impl_018": {
    "date": "2026-09-15",
    "slice": "Fleet composition — payload-sized cargo (FLE-010)",
    "code": ["app/Domain/Decision/QueueableUnitPlanner.php"],
    "shipped": ["cargo batch sized to the expected raid payload (host loot fraction + 20% buffer) instead of a fixed one (FLE-002/FLE-010)"],
    "deferred": ["stage workhorse (FLE-014) — proactive combat core", "recycler sizing (FLE-009) — depends on the fleetcrash debris path", "counters/launch subset (FLE-007/FLE-013) — the raid dispatch concern"],
    "tests": ["tests/Feature/UnitCompositionTest.php"],
    "fixture_fixes": ["UnitPlannerRolesTest no-escort fixture now carries a cargo ship so the payload sizing does not fire"],
    "verification": "quality green (651 tests), changed files 100% PCOV"
  },
  "impl_017": {
    "date": "2026-09-15",
    "slice": "Fleetcrash F2/F3 — recall executor + moon geography",
    "code": ["app/Contracts/QueueAiRecall.php", "app/Actions/QueueAiRecallAction.php", "app/Domain/Decision/QueueableRecall.php", "app/Domain/Decision/QueueableFleetSavePlanner.php", "app/Domain/Perception/PlayerObservationService.php", "app/Domain/Perception/PlayerPerceptionBuilder.php", "app/Domain/Perception/PerceptionSnapshot.php", "app/Domain/Decision/CandidateActionFactory.php", "app/Domain/Decision/Policies/FleeterPolicy.php", "app/Actions/ScheduleAiIntentAction.php", "app/Actions/ExecuteAiIntentAction.php", "app/Providers/AIServiceProvider.php", "app/Enums/AiCandidateActionType.php", "app/Enums/AiWorkKind.php", "app/Enums/AiQueueActionReason.php", "app/Enums/AiCandidateReason.php", "scripts/gate-2-review.php"],
    "shipped": ["F2 recall executor over cancelMission with the ownership check the host lacks (host R5) — a parked save comes home once the hostile is gone", "recall eligibility in the perception (own in-flight deployment, no inbound hostile)", "recall flows through the existing candidate → schedule → dispatch pipeline", "F3 save planner parks on a moon when one exists (phalanx-invisible, CRASH-006)"],
    "host_verified": ["PhalanxService calculatePhalanxRange/getScanCost/canScanTarget/scanPlanetFleets", "JumpGateService::calculateCooldown", "DebrisFieldService::calculateRequiredRecyclers", "FleetMissionService::cancelMission (no ownership check; same-planet relocation + arrival guards)", "RecycleMission type 8", "MoonDestructionMission type 9 (redirectFleetsFromMoon exists)"],
    "deferred": ["F1 phalanx coverage — no standalone code (a range table violates gate 1, a forward over canScanTarget violates gate 2); its only consumer is the crash-timing executor", "F4 recycle trip — needs debris-field awareness plus the attack→debris→recycle chain of the crash executor", "F5 blind lanx — P2 awareness-first per plan", "F6 moon destruction — last in priority; redirect consequence now code-verified but gated on a deathstar and the strategic judgement"],
    "tests": ["tests/Feature/RecallDepthTest.php", "tests/Feature/SaveDepthTest.php", "tests/Feature/QueueActionGuardsTest.php"],
    "verification": "quality green (659 tests), changed files 100% PCOV",
    "pre_existing_coverage_gaps": ["CandidateActionFactory.php (75/82)", "UtilityScorer.php (30/31)", "AiCandidateReason.php (1/2)", "ProcessAiWork.php (139/140)", "AIServiceProvider.php (55/56)"]
  },
  "impl_020": {
    "date": "2026-09-15",
    "slice": "Expedition executor — slot-16, host-returned outcomes, never a save",
    "code": ["app/Contracts/QueueAiExpedition.php", "app/Actions/QueueAiExpeditionAction.php", "app/Domain/Decision/QueueableExpedition.php", "app/Domain/Decision/QueueableExpeditionPlanner.php", "app/Domain/Decision/CandidateActionFactory.php", "app/Actions/ScheduleAiIntentAction.php", "app/Actions/ExecuteAiIntentAction.php", "app/Providers/AIServiceProvider.php", "app/Enums/AiCandidateActionType.php", "app/Enums/AiWorkKind.php", "app/Enums/AiQueueActionReason.php", "app/Enums/AiCandidateReason.php", "scripts/gate-2-review.php"],
    "host_verified": ["ExpeditionMission type 15, hasReturnMission, peaceful speed", "slot-16 position requirement in isMissionPossible", "astrophysics >= 1 requirement", "slot budget via getExpeditionSlotsInUse/getExpeditionSlotsMax", "holding hours bounded 1..astrophysics", "configurable outcome weights (dark_matter/ships/resources/delay/speedup/nothing/black_hole/pirates/aliens/merchant) with Discoverer combat reduction", "civil vs military ship classification via getCivilShipObjects"],
    "shipped": ["expedition executor over ExpeditionMission type 15 at slot 16 of the origin system", "never-fleetsave refusal: one small disposable civil cargo ship (host-classified), never the combat fleet (EXP-001)", "feasibility gate: astrophysics + free slot + disposable ship", "expedition flows through the candidate -> schedule -> dispatch pipeline"],
    "deferred": [],
    "tests": ["tests/Feature/ExpeditionDepthTest.php", "tests/Feature/QueueActionGuardsTest.php"],
    "verification": "quality green (664 tests), changed files 100% PCOV"
  },
  "impl_019": {
    "date": "2026-09-15",
    "slice": "Ninja NN1 — anti-ninja staging check on the raid path",
    "code": ["app/Actions/QueueAiRaidAction.php", "app/Enums/AiQueueActionReason.php"],
    "shipped": ["a raid is dropped at dispatch when the target's moon is active while its planet is quiet — the defender staging a trap fleet on the moon (NIN-005); reuses ActivityIntelReader::moonOnlyActivity and the host's moon-coordinate lookup"],
    "deferred": ["NN2 the ninja trap (defender timed counter-landing) — gated behind a reviewed cluster per the plan ('advanced tactic gated behind a reviewed cluster, never silent'), timing-critical"],
    "tests": ["tests/Feature/RaidDepthTest.php"],
    "verification": "raid depth tests 4/4 green"
  },
  "impl_021": {
    "date": "2026-09-15",
    "slice": "SP5 — reservation before spending",
    "code": ["app/Domain/Decision/ReserveFloor.php", "app/Domain/Decision/QueueableBuildingPlanner.php"],
    "shipped": ["a per-resource floor (10% of storage reduced by production over the saving horizon) guards both build and research affordability", "a resource the price does not spend keeps no floor, so a deuterium reserve never freezes surplus metal and crystal", "economy horizon 4h, research horizon 6h — the published defaults"],
    "deferred": [],
    "tests": ["tests/Feature/ReserveFloorTest.php"],
    "verification": "ReserveFloorTest 3/3 green; EnergyCapacityTest 4/4, BuildingChainReachabilityTest 8/8, EconomyUpgradesTest 7/7, DeterministicSessionLoopTest 4/4, AiCapabilityPublicationTest 16/16, ProcessAiSessionTest 1/1, ProcessAiWorkTest 26/26, RunDueAiWorkTest 2/2, ExecuteIntentTest 10/10, CoverageCompletionTest 11/11",
    "notes": "unblocks DEF-002 (X1 transfer) — the transport source keeps the same reserve floor"
  },
  "def_002": {
    "date": "2026-09-15",
    "slice": "X1 — transfers between own planets",
    "code": ["app/Domain/Decision/QueueableTransfer.php", "app/Domain/Decision/QueueableTransferPlanner.php", "app/Contracts/QueueAiTransfer.php", "app/Actions/QueueAiTransferAction.php", "app/Domain/Decision/CandidateActionFactory.php", "app/Actions/ScheduleAiIntentAction.php", "app/Actions/ExecuteAiIntentAction.php", "app/Providers/AIServiceProvider.php", "app/Enums/AiCandidateActionType.php", "app/Enums/AiWorkKind.php", "app/Enums/AiCandidateReason.php", "app/Enums/AiQueueActionReason.php", "scripts/gate-2-review.php"],
    "shipped": ["a colony short of its next level's cost is funded from the body that can spare it, over the host TransportMission (type 3)", "shortfall = price − on-planet − in-flight transports (E4 netting: one hole is never funded twice)", "source keeps its SP5 reserve on the resources it actually ships", "shipments below 50k combined metal+crystal are skipped (r4fek)", "the ferry carries just enough owned cargo hulls, never the combat fleet", "flows through the candidate → schedule → dispatch pipeline like recall/expedition"],
    "deferred": [],
    "tests": ["tests/Feature/TransferDepthTest.php"],
    "verification": "TransferDepthTest 4/4 green; AiCapabilityPublicationTest 16/16, DeterministicSessionLoopTest 4/4, ProcessAiSessionTest 1/1, ProcessAiWorkTest 26/26, ExecuteIntentTest 10/10, CoverageCompletionTest 11/11, ArchitectureTest 3/3"
  },
  "doc_004": {
    "date": "2026-09-15",
    "slice": "T6/V7 residual close-out (doc accuracy pass)",
    "files": ["specs/gameplay-algorithms.md", "DECISIONS.md"],
    "closed_shipped": ["proximity clustering (RAID-009) — already delivered by the normalized host-distance travel_cost; the host distance quote prices a cross-galaxy hop at diffGalaxy x 20000 against deltaSystem x 95 + 2700 inside a galaxy (~5x deuterium), so the scorer already clusters raids near the fleet; pinned by the existing test 'owned state prices a distant target higher than a near one'", "V7 route x speed — resolved for the deployment save: the host slowest speed (10%) is also the minimum-fuel speed and a parked deployment has no arrival schedule to fit, so the axes collapse to the shipped destination ranking at the fixed slowest speed"],
    "recorded_not_built": ["discard unaffordable fuel — the host already refuses an unfuelable save at dispatch and the receipt records it; a planner-side filter is observability polish, not correctness", "never the same landing time — departure rides the routine's session spread (H2), never a fixed tick"],
    "deferred": ["relationship (RAID-007) — AiRelationship rows are only written by the social observation path (Package 6); a raid policy over empty state would be dead code", "contest (RAID-013) — the module observes no other player's raid schedule, so a contest model would be an unmeasured guess; proximity is the shipped edge"],
    "verification": "no code changed; doc consistency re-checked (gap index W6-1/W6-3, T6 and V7 blocks); no gate run (doc-only)"
  }
}
```

The register additions from this pass are recorded in
[`GAP-REGISTER.md`](../GAP-REGISTER.md#wave-6--strategy-mining-gap-scan-15-september-2026).
