-- OGameX AI task DB — seed. The plan docs are the SOURCE OF TRUTH; this DB is the index agents
-- claim work from. Rebuild any time:  sqlite3 plan/tasks/tasks.db < plan/tasks/seed.sql
-- Status flow: todo -> in_progress -> done | blocked | deferred | open. Only 'todo' tasks appear in
-- ready_tasks once every dependency is 'done'.

PRAGMA foreign_keys = ON;

-- schema changes: drop and rebuild (re-seeding resets statuses, which is the documented behaviour)
DROP VIEW IF EXISTS ready_tasks;
DROP VIEW IF EXISTS blocked_tasks;
DROP TABLE IF EXISTS dependencies;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS meta;

CREATE TABLE IF NOT EXISTS meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
  id             INTEGER PRIMARY KEY,
  code           TEXT UNIQUE NOT NULL,
  title          TEXT NOT NULL,
  kind           TEXT NOT NULL CHECK (kind IN ('review','doc','impl','deferred','discovery')),
  status         TEXT NOT NULL DEFAULT 'todo'
                 CHECK (status IN ('todo','in_progress','blocked','done','deferred','open')),
  priority       TEXT CHECK (priority IN ('P0','P1','P2','P3')),
  assignee       TEXT,
  gap_ref        TEXT,            -- GAP-REGISTER id(s)
  principle_refs TEXT,            -- strategy-principles.md id(s)
  algorithm_ref  TEXT,            -- gameplay-algorithms.md block(s)
  doc_refs       TEXT,            -- docs that describe the work
  file_ref       TEXT,            -- primary file(s) to edit
  notes          TEXT,
  updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS dependencies (
  task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  depends_on INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  reason     TEXT,
  PRIMARY KEY (task_id, depends_on)
);

-- ready = todo and every dependency done
CREATE VIEW IF NOT EXISTS ready_tasks AS
SELECT t.*
FROM tasks t
WHERE t.status = 'todo'
  AND NOT EXISTS (
    SELECT 1 FROM dependencies d
    JOIN tasks dep ON dep.id = d.depends_on
    WHERE d.task_id = t.id AND dep.status <> 'done'
  );

CREATE VIEW IF NOT EXISTS blocked_tasks AS
SELECT t.*
FROM tasks t
WHERE t.status = 'todo'
  AND EXISTS (
    SELECT 1 FROM dependencies d
    JOIN tasks dep ON dep.id = d.depends_on
    WHERE d.task_id = t.id AND dep.status <> 'done'
  );

INSERT OR REPLACE INTO meta (key, value) VALUES
 ('schema_version', '1'),
 ('source_of_truth', 'plan/details/GAP-REGISTER.md, plan/details/research/strategy/README.md, plan/details/research/architecture-mapping.md, plan/details/specs/gameplay-algorithms.md'),
 ('updated', '2026-09-16');

-- ── tasks ──────────────────────────────────────────────────────────────────────────────────────────
INSERT OR REPLACE INTO tasks
 (id, code, title, kind, status, priority, gap_ref, principle_refs, algorithm_ref, doc_refs, file_ref, notes, updated_at) VALUES
 (1,'REV-001','Catalog review — pass the 10 integration gates','review','done','P0','W6-1..6',NULL,NULL,'specs/strategy-mining.md (Integration gates L)',NULL,'Owner sign-off. No implementation starts until the cluster passes all 10 gates; the last gate is `bash scripts/ogamex gate`.','2026-09-15 15:23:25'),
 (2,'IMPL-013','SP7 — the activity and intel reader','impl','done','P1','W6-1,W6-2,W6-3','RAID-004,RAID-005,RAID-006,INT-004,INT-005,INT-006,INT-010','SP7','specs/gameplay-algorithms.md (SP7); research/architecture-mapping.md','app/Domain/Decision/PlayerObservationService.php (targetReports)','One read-only reader: activity_at, moon_only_activity, intel_confidence, activity_probability_at_eta. Unblocks T6/T8/N4/V6.','2026-09-15 15:23:14'),
 (3,'IMPL-014','Raid depth — T6 target score, T7 tiered gate + cargo, T8 launch re-check','impl','done','P1','W6-1','RAID-004..014','T6,T7,T8','specs/gameplay-algorithms.md (T6-T8)','app/Domain/Decision/RaidPlanner.php; app/Domain/Decision/QueueAiRaidAction.php','Replace confidence=1.0 and travel_cost=0.0 placeholders; add activity risk, per-type intel decay, tiered profit gate, cargo sizing, dispatch re-check.','2026-09-15 15:45:46'),
 (4,'IMPL-015','Intelligence depth — N4 spy target score, N5 own signature','impl','done','P1','W6-2','INT-003,INT-007,INT-009,INT-011,INT-012','N4,N5','specs/gameplay-algorithms.md (N4-N5)','app/Domain/Decision/QueueableSpyPlanner.php','Replace id-order first-fit with a distance/novelty/yield score; dispatch and disappear.','2026-09-15 15:55:44'),
 (5,'IMPL-016','Save depth — V6 proactive save, V7 variation + masking, V8 shadow waves','impl','done','P1','W6-3','FS-001,FS-002,FS-005,FS-006,FS-008,FS-009,FS-011','V6,V7,V8','specs/gameplay-algorithms.md (V6-V8)','app/Domain/Decision/QueueableFleetSavePlanner.php','Proactive offline-gap trigger, route × speed scoring, dispatch masking, shadow waves.','2026-09-15 16:04:42'),
 (6,'IMPL-017','Fleetcrash — F1-F6 phalanx/recall/moon/recycle/lanx/moon-destruction','impl','done','P1','W6-5','CRASH-001..014','F1,F2,F3,F4,F5,F6','specs/gameplay-algorithms.md (F1-F6); research/architecture-mapping.md','new planners over PhalanxService/cancelMission/RecycleMission','Host-supported, zero module callers today. After 13-16; Pass-4 niche.','2026-09-15 16:50:32'),
 (7,'IMPL-018','Fleet composition — U-series production + launch subset, counters, recyclers','impl','done','P1','W6-4','FLE-002,FLE-004,FLE-012,FLE-013,FLE-014,FLE-015','U-series (block to write)','specs/gameplay-algorithms.md (U1-U4); research/strategy/principles/fleet-composition.yaml','app/Domain/Decision/QueueableUnitPlanner.php','Next increment: payload-sized cargo, stage composition, production-vs-launch split. Parallel-safe once reviewed.','2026-09-15 16:17:36'),
 (8,'IMPL-019','Ninja executor','impl','done','P2','pass-6 (new)','NIN-001..005','(new block)','research/architecture-mapping.md (pass-6)','new defence/reaction executor','No executor exists. Depends on fleet timing (IMPL-017). | blocked: NN2 ninja trap is gated behind a reviewed cluster per gameplay-algorithms.md (''advanced tactic gated behind a reviewed cluster, never silent''); NN1 phalanx staging check needs a moon+phalanx no account yet builds (F3 leaves moon-building open) and the activity fallback is already shipped as T8.','2026-09-15 17:30:23'),
 (9,'IMPL-020','Expedition executor','impl','done','P2','pass-6 (new)','EXP-001,EXP-002','(new block)','research/architecture-mapping.md (pass-6)','new expedition executor','Host expedition surface NOT re-verified — blocked on DISC-004.','2026-09-15 17:24:40'),
 (10,'DEF-001','V2 reaction wake','deferred','done','P2','G8','FS-004','V2','specs/gameplay-algorithms.md (V2)',NULL,'Shipped 16 Sep: inboundThreat applies the reaction window to fleetsave_eligible (withhold when arrival > 180 s out and publish reaction_wake_at = arrival - draw(120,180); no doomed save below the host 10 s floor; save now between), and SessionDecisionService clamps the successor to the reaction wake. Hostility stays the host currentPlayerUnderAttack() answer - no mission-type list.','2026-09-16 00:00:00'),
 (11,'DEF-002','X1 transfer executor','deferred','done','P2','G17',NULL,'X1','specs/gameplay-algorithms.md (X1)',NULL,'Trigger decided; executor next slice.','2026-09-15 17:56:25'),
 (12,'DEF-003','Social / ACS / alliance life (Package 6)','deferred','deferred','P3','G12,G18,S1-S4','SOC-001,SOC-002,ACS-001..014','SOC1,SOC2','plan/WORK-PACKAGES.md (Package 6)',NULL,'Alliance-gated; deferred to Package 6.','2026-09-15 14:41:40'),
 (13,'DISC-001','Re-source ACS tutorials ORG-009/010','discovery','open','P3',NULL,NULL,NULL,'research/strategy/sources.yaml',NULL,'Wayback failed; GF-003 suffices. Non-blocking.','2026-09-15 14:41:40'),
 (14,'DISC-002','French board guide library','discovery','open','P3',NULL,NULL,NULL,'research/strategy/sources.yaml',NULL,'Confirmed to exist; thread URLs not retrievable. Non-blocking.','2026-09-15 14:41:40'),
 (15,'DISC-003','ogamewiki.de','discovery','open','P3',NULL,NULL,NULL,'research/strategy/sources.yaml',NULL,'Extraction failed. Non-blocking.','2026-09-15 14:41:40'),
 (16,'DISC-004','Verify expedition host surface','discovery','done','P2',NULL,'EXP-001,EXP-002',NULL,'research/host-capability-map.md',NULL,'Host expedition mission support unverified; blocks IMPL-020.','2026-09-15 16:55:28'),
 (17,'DOC-001','Write U-series fleet-composition algorithm blocks','doc','done','P1','W6-4','FLE-002,FLE-004,FLE-009,FLE-010,FLE-011,FLE-012,FLE-013,FLE-014,FLE-015','U-series (new)','specs/gameplay-algorithms.md (U1-U4); research/strategy/principles/fleet-composition.yaml','plan/details/specs/gameplay-algorithms.md','Production-vs-launch split, stage composition, counters, recycler sizing, payload-sized cargo. Documentation only; unblocks IMPL-018.','2026-09-15 14:53:17'),
 (18,'DOC-002','Write ninja algorithm block','doc','done','P2','pass-6 (new)','NIN-001..005','N-series (new)','research/strategy/principles/ninja-baiting.yaml; research/architecture-mapping.md (pass-6)','plan/details/specs/gameplay-algorithms.md','Defender counter-crash: staging, combat-second landing, bait, anti-ninja checks. Documentation only; unblocks IMPL-019.','2026-09-15 14:54:12'),
 (19,'DOC-003','Write expedition algorithm block','doc','done','P2','pass-6 (new)','EXP-001,EXP-002','E-series (new)','research/strategy/principles/expeditions.yaml','plan/details/specs/gameplay-algorithms.md','Slot-16 outcomes, never-fleetsave rule. Documentation only; host surface unverified; unblocks IMPL-020.','2026-09-15 14:55:09'),
 (20,'IMPL-021','SP5 — reservation before spending','impl','done','P2','W6','SP5','SP5',NULL,'app/Domain/Decision/QueueableBuildingPlanner.php','Per-resource floor that survives a build/research purchase, reduced by production over the saving horizon. keep_resources_buffer 0.10, max_saving_hours_economy 4.0, max_saving_hours_research 6.0. Unblocks DEF-002 X1.','2026-09-15 17:44:38'),
 (21,'DOC-004','Close T6/V7 residuals — mark clustering + route×speed shipped, defer relationship/contest','doc','done','P2','W6-1,W6-3',NULL,'T6,V7',NULL,'plan/details/specs/gameplay-algorithms.md','Doc-only accuracy pass: proximity clustering is already shipped via travel_cost (pinned by ''owned state prices a distant target higher than a near one''); V7 route x speed is resolved for deployment saves (slowest speed is fuel-minimal, destination ranking shipped). Record relationship (RAID-007) and contest (RAID-013) as deferred with precise reasons.','2026-09-15 19:23:30'),
 (22,'IMPL-022','Capability research — queue a leaf research that unlocks a non-object capability (astrophysics)','impl','done','P1','G2,G7,G3','R2','R2',NULL,'app/Domain/Decision/FacilityChain.php','Live grand run (15 Sep): all 10 accounts have astro=0 after 8h at 1000x, and 7 of them meet every astrophysics prerequisite (research_lab>=3, espionage>=4, impulse>=3). Consequence: colonize and expedition never fire. Root cause: FacilityChain is the only research source; ambitions() = research+unit objects sorted cheapest-first, and pending() returns only the UNMET PREREQUISITES of the cheapest non-producible ambition. nextAmbition() skips an ambition whose prerequisites all stand, so a leaf research that no unit requires (astrophysics) is never queued. Its prerequisites get climbed, then it is skipped forever. R2 says such a research must score through the capability it unlocks.','2026-09-15 20:41:18'),
 (23,'IMPL-023','Close the module PCOV coverage gaps (98.79% -> 100%)','impl','done','P1','W6-1,W6-3',NULL,NULL,NULL,NULL,'coverage gate red: 5780/5851 = 98.79%, exit 1. Gaps: ExecuteAiIntentAction 207-238 (the transfer() arm), QueueAiTransferAction 40,43,50,53,77,78,92,98,103, ScheduleAiIntentAction 260-267 (scheduleTransfer), CandidateActionFactory 139-145 (eligibleTransferCandidates) + 196-202 (raid candidate creation), QueueableFleetSavePlanner 63,119, QueueableTransferPlanner 73,96,123,153,173, RaidPlanner 82 (storageReady capacity<=0), UtilityScorer 36 (policy-denied continue), PlayerObservationService 148, AiCandidateReason 21,23 (reportSource), ProcessAiWork 281 (no schedule row), AIServiceProvider 139 (10s session interval). app/Rules is excluded from the gate. NOTE: parallel workers isolate test files, so shared helpers must be require_once''d from tests/Pest.php as a Support file/class (the FixturePlayerPerceptionBuilder pattern); helpers defined in a test file are NOT visible to another test file, and cross-file use passes serial (coverage) but fails parallel (test).','2026-09-15 21:45:43'),
 (24,'DOC-005','Flip the strategy source to YAML and extract the atomic-claims layer','doc','done','P2',NULL,NULL,'strategy',NULL,'plan/details/research/strategy/README.md','Follow-up to the Strategy Knowledge Storage Format. (a) Flip the source: make the YAML canonical and regenerate/repoint the Markdown consumers (gameplay-algorithms.md, GAP-REGISTER.md, task DB doc_refs) so there is one authority. (b) Extract the atomic claims layer (claims/*.yaml) from the sources — the repo currently goes sources -> principles with only a classification (now claim_type on each principle).','2026-09-16 16:12:12'),
 (25,'IMPL-024','Spend a windfall before warehousing it (W7-1/W7-2)','impl','done','P2','W7-1,W7-2',NULL,'E6',NULL,'plan/details/specs/gameplay-algorithms.md','planned; smallest slice undecided — two hypotheses in E6, needs a frozen-clock before/after','2026-09-16 11:24:16'),
 (26,'IMPL-025','Scarcity-weighted resource need: the scarce resource ranks above the persona habit','impl','done','P2','W7-1',NULL,'E6',NULL,'app/Domain/Decision/CandidateActionFactory.php','The other half of W7-1: a fleeter ranks ships 15 points above mines in every session. Give Build a scarcity-weighted resource_need so it wins exactly when a resource is the binding constraint; frozen-clock before/after.','2026-09-16 12:37:20'),
 (27,'IMPL-026','Objective resolution from a committed coalition victory','impl','done','P2',NULL,NULL,NULL,'specs/pve-empire.md','app/Actions/ResolveAiCampaignObjectiveFromBattleReportAction.php; app/Observers/ObserveCommittedBattleReport.php; app/Models/AiCampaignObjective.php','A committed battle report whose attacker wins against a declared objective planet marks that objective complete once. A draw, retreat or defender win does not count; winning does not capture the planet.','2026-09-16 13:16:10'),
 (28,'IMPL-027','Campaign director lifecycle','impl','done','P2',NULL,NULL,NULL,'specs/pve-empire.md','app/Actions/AdvanceAiCampaignStateAction.php; app/Models/AiCampaign.php','Preparing->Active at starts_at; Active->Resolved when every declared objective completes on time; Failed when the deadline passes with an incomplete set. Idempotent terminal states.','2026-09-16 13:16:10'),
 (29,'IMPL-028','Reward allocator','impl','done','P2',NULL,NULL,NULL,'specs/pve-empire.md',NULL,'Ordinary loot/debris plus campaign recognition; cap repeated contribution credit and exclude AI participation from human credit.','2026-09-16 13:16:10'),
 (30,'DEF-004','Cooperative hostility extension point (E6/E7) + module policy registration','impl','done','P3',NULL,NULL,NULL,'specs/module-extension-points.md (E6/E7); specs/pve-empire.md','app/Contracts/HostilityPolicy.php; app/Services/HostilityGuard.php; Modules/AI/app/Support/CooperativeHostilityPolicy.php','Host pull request for the HostilityPolicy + registry must land first; the module side is registration only.','2026-09-16 13:00:35'),
 (31,'IMPL-029','6C — operator control and fail-closed LLM admission','impl','done','P2',NULL,NULL,NULL,'WORK-PACKAGES.md (6C); specs/pve-empire.md',NULL,'Off/observe/advice modes, enablement, kill switch, provider ladder, trigger cooldowns, advice age, evidence-inclusion policy and hard token/attempt/cost/concurrency caps. Off resolves no Laravel AI config and contacts no provider; receipts never retain raw prompts. | blocked: LLM/provider phase — awaits owner green light after the deterministic baseline is verified','2026-09-16 13:54:18'),
 (32,'IMPL-030','6A — consultation transport + typed recommendation core','impl','done','P2',NULL,NULL,NULL,'WORK-PACKAGES.md (6A); specs/pve-empire.md',NULL,'Shipped: the structured-output agent, the Null/Laravel AI gateways, the request/recommendation domain, the fail-closed admission wiring and the candidate-membership validator. Remaining 6A (brief builder, receipt+budget+caps, ranking adjustment) are IMPL-032/033/034.','2026-09-16 13:58:23'),
 (33,'IMPL-031','6B — driver-signal utilisation for cooperative player divergence','impl','blocked','P3',NULL,NULL,NULL,'WORK-PACKAGES.md (6B); specs/pve-empire.md',NULL,'One bounded driver-evidence contribution at existing campaign decision points; profile traits decide reaction weight. Requires the 2/5/10 comparison showing a player-visible gain before any default. | blocked: measurement-gated — needs the 2/5/10 capacity-run comparison before it ships','2026-09-16 13:20:25'),
 (34,'IMPL-032','6A — bounded redacted advice brief builder','impl','done','P2',NULL,NULL,NULL,NULL,NULL,'Build the serialized redacted brief from current permitted campaign facts, legal executable candidates with native scores, and typed attributed driver evidence. Missing/invalid/stale/unauthorised driver evidence is absent.','2026-09-16 15:35:37'),
 (35,'IMPL-033','6A — consultation receipt, budget reservation and caps','impl','done','P2',NULL,NULL,NULL,NULL,NULL,'Persist a receipt per consultation (trigger, config revision, evidence IDs, provider/model, usage, validation result, changed ranking; never raw prompts or private facts). Reserve/settle usage through the existing budget; enforce daily ceilings, per-trigger cooldown and concurrency; uncertain usage settles once.','2026-09-16 15:35:37'),
 (36,'IMPL-034','6A — profile-bounded ranking adjustment','impl','done','P2',NULL,NULL,NULL,NULL,NULL,'Apply a validated recommendation as a profile-bounded adjustment to a later ranking; native policy still chooses and dispatches. No driver can promote an unavailable action or override a native refusal.','2026-09-16 15:35:37'),
 (37,'REV-002','Verify and pair-commit the Package 6 batch (module wip + host hostility half)','review','todo','P0','W8-V1',NULL,NULL,'plan/WORK-PACKAGES.md (Package 6); plan/details/GAP-REGISTER.md (Wave 8)','app/ (the whole batch, commit bf064ac); host app/Services/HostilityGuard.php, app/Enums/UniverseMode.php, app/GameMissions/Abstracts/GameMission.php','The Package 6 slices landed as one commit named wip (bf064ac, 100 files) and the host half of the same pair is uncommitted, so the module registers CooperativeHostilityPolicy against host classes a fresh host checkout does not have. Run the module gates on bf064ac (Gate 2 review, Pint, PHPStan, Rector dry-run, Pest, PCOV), commit the host pair, then name each shipped slice in the record. Nothing measured on the running containers is evidence about a commit until this holds.','2026-09-16 16:47:55'),
 (38,'DOC-006','Wave-8 algorithm blocks: dispatch ceiling, full-storage spend, colonise eligibility, probe budget, quiet-decision reason','doc','todo','P1','W8-L1,W8-L4,W8-L5,W7-3,W8-L7',NULL,'(new blocks)','plan/details/specs/gameplay-algorithms.md (gap index)','plan/details/specs/gameplay-algorithms.md','Name each algorithm with its host inputs, constants and their source, failure behaviour and acceptance before the code lands: the dispatch ceiling (host fleet-slot answer + host obligation R11), the full-storage spend precedence (E3/E6), colonise eligibility, the probe budget (INT-*), the quiet-decision stop reason. Documentation only; unblocks IMPL-035, IMPL-036, IMPL-037, IMPL-038 and IMPL-040.','2026-09-16 16:47:55'),
 (39,'IMPL-035','Host dispatch ceiling before publishing a dispatch (W8-L1)','impl','todo','P1','W8-L1',NULL,'DS1 (new)','plan/details/GAP-REGISTER.md (Wave 8); plan/details/specs/gameplay-algorithms.md (DS1)','app/Domain/Decision/CandidateActionFactory.php; app/Domain/Decision/QueueableFleetSavePlanner.php','Only the save planner reads getFleetSlotsMax(), so colony, spy, raid, expedition and transfer dispatches are published and refused: 1027 receipts in 24 h, 917 CreateColony, all "Maximum number of fleets reached"; 8 of 10 accounts hold one slot because the object that raises the ceiling is never researched. Read the host ceiling (getFleetSlotsMax minus getFleetSlotsInUse) before publishing a dispatch, and let the build order reach the object that raises it. The host answer names computer_technology internally and ObjectService exposes no lookup by calculation type, so the reachability half needs the host obligation R11 (publish the object carrying MAX_FLEET_SLOTS) rather than a module-side object list.','2026-09-16 16:47:55'),
 (40,'IMPL-036','A planet at 100% storage must still spend (W8-L4)','impl','todo','P1','W8-L4',NULL,'E6 (extend)','plan/details/GAP-REGISTER.md (Wave 8); plan/details/specs/gameplay-algorithms.md (E6)','app/Domain/Decision/EconomyUpgrades.php; app/Domain/Decision/QueueableBuildingPlanner.php; app/Domain/Decision/CandidateActionFactory.php','Four homeworlds sit at exactly 100% storage and are refused a warehouse (storage() skips hours <= 0.0 by design) while production() is bounded by the payback horizon, so 8.9M metal/h is discarded and QueueableBuildingPlanner::plan(20) returns research. Decide the E3/E6 precedence - an already-full warehouse is a spend signal - and measure the before/after on a frozen clock. W7-2 is recorded closed by IMPL-024 while the mid-day read re-measured the same collision, so re-check the closure first.','2026-09-16 16:47:55'),
 (41,'IMPL-037','Colonise must not outrank developing what the account owns (W8-L5)','impl','todo','P1','W8-L5',NULL,'G7 (extend)','plan/details/GAP-REGISTER.md (Wave 8); plan/details/specs/gameplay-algorithms.md (G7)','app/Domain/Decision/CandidateActionFactory.php; app/Domain/Decision/QueueableColonyPlanner.php','p20 froze for 14 h: 174 sessions, 175 colonise candidates, 168 colonise work items, zero other decisions, 112 refused at the fleet cap, score +0 (rank 6 -> 10), and both of its colonies have production 0. Colonise scores ~49.6 against research 39.6 / units 40.1 / expedition 38.7, so a new body outranks the one that pays for it. Eligibility reads the host ceiling (IMPL-035) and whether the account can develop the body it would found. The un-materialised colony stats are DISC-005.','2026-09-16 16:47:55'),
 (42,'DISC-005','Two settled colonies have never materialised their stats (W8-L5)','discovery','done','P1','W8-L5',NULL,NULL,'plan/details/research/host-capability-map.md',NULL,'Resolved 16 Sep: the host materialises a settled colony. ColonisationMission::processArrival -> PlanetServiceFactory::createAdditionalPlanetForPlayer -> createPlanet writes metal=500, crystal=500, field_max, temperature, 100% mine percents and time_last_update=now, then fires PlanetCreated; QueueAiColonyAction goes through the host FleetMissionService path and skips no step. The observed zeros are the host lazy stat columns: metalStorage() reads planet.metal_max, which only PlanetService::update() (updateResourceStorageStats / updateResourceProductionStats) computes, and PlayerGameStateService::advance() updates only the current planet. RunAiSessionAction advances only the current planet, so a colony the account never visits keeps metal_max=0, production 0, no mines and its creation-time stamp; the stored 500/500 metal/crystal are present. No host defect and no skipped step - the account simply never develops its own new colony, the root IMPL-037 now guards at decision time. Non-blocking follow-up: the session loop never updates a non-current planet.','2026-09-16 16:47:55'),
 (43,'IMPL-038','The probe budget counts in-flight spy intents (W7-3)','impl','todo','P2','W7-3',NULL,'INT-*','plan/details/specs/gameplay-algorithms.md (INT-*)','app/Domain/Decision/QueueableSpyPlanner.php','inFlightCoordinates() de-duplicates by target coordinates, so two scheduled probes for different targets both plan from one probe; 32 receipts were refused with "Not enough units on the planet to send the fleet. Units required: espionage_probe". The budget is idle probes minus probes already committed to a pending spy item.','2026-09-16 16:47:55'),
 (44,'DISC-006','Why the espionage-report flow and the raid pipeline starved (W8-L2)','discovery','done','P2','W8-L2',NULL,NULL,'plan/details/reviews/2026-09-16-grand-live-play-read.md',NULL,'Resolved 16 Sep: W8-L1 confirmed as the cause by code-read. An espionage mission is a fleet mission consuming one slot, and only its arrival creates EspionageReport (processArrival -> createEspionageReport); with 8/10 accounts holding one slot every probe dispatch was refused ("Maximum number of fleets reached") -> no missions flew -> no reports -> RaidPlanner had no fresh report -> Raid stopped being offered. IMPL-035 closes it two ways (candidate factory withholds spy/raid/colony/expedition/transfer when fleet_slots_free<1; FacilityChain reaches the ceiling object via the host R11 getObjectByCalculationType(MAX_FLEET_SLOTS)); IMPL-038 closes the secondary defect (probe budget counts pending spy intents). A live recovery re-measure belongs to the next capacity/pilot run, not code.','2026-09-16 16:47:55'),
 (45,'IMPL-040','The quiet decision writes a stop reason (W8-L7)','impl','todo','P2','W8-L7',NULL,NULL,'plan/details/GAP-REGISTER.md (Wave 8); plan/details/specs/improvement-loop.md','app/Enums/AiStopReason.php; app/Actions/RecordAiStopReasonAction.php; the session path that ends in DoNothing','ai_stop_counters is empty after 31 h while 470 sessions chose DoNothing and every trace carries exactly one candidate whose reason is always_available, so no artifact says why nothing else was available - the same gap that leaves "why was FleetSave offered twice and Recall never" unanswerable. Record the reason where the quiet decision is already taken, only for mechanisms that already exist; no new table.','2026-09-16 16:47:55'),
 (46,'IMPL-041','The hourly score sample keeps a readable trail (W8-L6)','impl','todo','P3','W8-L6',NULL,NULL,'plan/details/GAP-REGISTER.md (Wave 8); plan/details/specs/improvement-loop.md','app/Providers/AIServiceProvider.php (hourly entry); docker/entrypoint.sh (scheduler role)','ai_score_samples has no row between 15 Sep 22:00 and 16 Sep 12:00. The run log survives and is the evidence: the grand scheduler container (created 15 Sep 06:50) logs ai:record-score-samples five times, 15 Sep 07:00-11:00, and never again, while ai:run-due-work fires continuously, with no skipped or mutex line. Find why the entry stopped firing (entry guard, mutex, or a deploy) before adding any mechanism, then keep one bounded line per run.','2026-09-16 16:47:55'),
 (47,'REV-003','Re-measure the wave-8 rows on the committed build','review','todo','P1','W8-V2,W8-L1,W8-L3,W8-L4,W8-L5',NULL,NULL,'plan/details/reviews/2026-09-16-grand-live-play-read.md; plan/details/GAP-REGISTER.md (Wave 8)',NULL,'The read ran the containers, which mount the working tree, and four rows are decision-core claims that IMPL-024 and IMPL-025 were written to change. Re-read each row against the committed build, with a frozen clock where the row asks for a before/after (L3 fleeter play, L4 storage precedence, L5 colonise), and correct or close it; a row measured while a live pilot writes is not evidence about the code.','2026-09-16 16:47:55'),
 (48,'REV-004','Run the 2/5/10 capacity comparison and record the figures (completion-gate item 1)','review','todo','P1',NULL,NULL,NULL,'plan/WORK-PACKAGES.md (completion gate item 1); local-dev-docker capacity-run.sh','local-docker-dev/capacity-run.sh; plan/details/specs/budgets.md','Owner-rescaled from 100/500/1,000 to 2/5/10 accounts. Record per-profile campaign action, social and recovery divergence, latency, RAM, provider attempts and fallback rate against the native-only baseline. This is the gate IMPL-031 (6B) and DEF-001 (V2 reaction wake) wait on.','2026-09-16 16:47:55'),
 (49,'IMPL-042','SP3 next-material-event wake (W6-6)','impl','done','P2','W6-6','AUTH-004','SP3','plan/details/GAP-REGISTER.md (W6-6); plan/details/specs/gameplay-algorithms.md (SP3)','app/Domain/Scheduling/SessionDecisionService.php; app/Domain/Routine/SessionPlanner.php','Shipped 16 Sep: nextMaterialEventWake clamps the successor to the earliest build/research/fleet finish inside the waking window (SessionPlanner::isAwake) plus a right-skewed arrival delay; the routine session stays the upper bound. resource_eta/storage_threshold/non-fleet slot terms remain deferred refinements.','2026-09-16 00:00:00');

-- ── dependencies ────────────────────────────────────────────────────────────────────────────────────
INSERT OR REPLACE INTO dependencies (task_id, depends_on, reason) VALUES
 (2, 1, 'all impl gated on review'),
 (3, 2, 'raid target score consumes the activity/intel reader'),
 (4, 2, 'spy target score consumes the activity/intel reader'),
 (5, 2, 'proactive save consumes the activity/intel reader'),
 (6, 3, 'fleetcrash after raid depth'),
 (6, 4, 'fleetcrash after intelligence depth'),
 (6, 5, 'fleetcrash after save depth'),
 (7, 1, 'review only; parallel-safe once reviewed'),
 (7, 17, 'needs the U-series block written first'),
 (8, 6, 'ninja timing rides the fleetcrash executors'),
 (8, 18, 'needs the ninja block written first'),
 (9, 1, 'review'),
 (9, 16, 'needs a verified expedition host surface'),
 (9, 19, 'needs the expedition block written first'),
 (11, 20, NULL),
 (20, 1, NULL),
 (28, 27, NULL),
 (32, 31, NULL),
 (33, 48, '6B ships only if the 2/5/10 comparison shows a player-visible gain'),
 (34, 32, NULL),
 (35, 32, NULL),
 (36, 34, NULL),
 (36, 35, NULL),
 (39, 38, 'the algorithm block comes before the code (register rule)'),
 (40, 38, 'the algorithm block comes before the code (register rule)'),
 (41, 38, 'the algorithm block comes before the code (register rule)'),
 (43, 38, 'the algorithm block comes before the code (register rule)'),
 (44, 39, 'the fleet ceiling is the leading hypothesis for the starved reports'),
 (44, 43, 'in-flight probe budgeting feeds the same report flow'),
 (45, 38, 'the algorithm block comes before the code (register rule)'),
 (47, 37, 'the re-read has to be against the committed build');
