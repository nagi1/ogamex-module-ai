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
 ('source_of_truth', 'plan/details/GAP-REGISTER.md, plan/details/research/strategy-principles.md, plan/details/research/architecture-mapping.md, plan/details/specs/gameplay-algorithms.md'),
 ('updated', '2026-09-15');

-- ── tasks ──────────────────────────────────────────────────────────────────────────────────────────
INSERT OR REPLACE INTO tasks
 (id, code, title, kind, status, priority, gap_ref, principle_refs, algorithm_ref, doc_refs, file_ref, notes) VALUES
 (1,'REV-001','Catalog review — pass the 10 integration gates','review','blocked','P0','W6-1..6',NULL,NULL,
  'specs/strategy-mining.md (Integration gates L)',NULL,
  'Owner sign-off. No implementation starts until the cluster passes all 10 gates; the last gate is `bash scripts/ogamex gate`.'),
 (2,'IMPL-013','SP7 — the activity and intel reader','impl','todo','P1','W6-1,W6-2,W6-3',
  'RAID-004,RAID-005,RAID-006,INT-004,INT-005,INT-006,INT-010','SP7',
  'specs/gameplay-algorithms.md (SP7); research/architecture-mapping.md',
  'app/Domain/Decision/PlayerObservationService.php (targetReports)',
  'One read-only reader: activity_at, moon_only_activity, intel_confidence, activity_probability_at_eta. Unblocks T6/T8/N4/V6.'),
 (3,'IMPL-014','Raid depth — T6 target score, T7 tiered gate + cargo, T8 launch re-check','impl','todo','P1','W6-1',
  'RAID-004..014','T6,T7,T8','specs/gameplay-algorithms.md (T6-T8)',
  'app/Domain/Decision/RaidPlanner.php; app/Domain/Decision/QueueAiRaidAction.php',
  'Replace confidence=1.0 and travel_cost=0.0 placeholders; add activity risk, per-type intel decay, tiered profit gate, cargo sizing, dispatch re-check.'),
 (4,'IMPL-015','Intelligence depth — N4 spy target score, N5 own signature','impl','todo','P1','W6-2',
  'INT-003,INT-007,INT-009,INT-011,INT-012','N4,N5','specs/gameplay-algorithms.md (N4-N5)',
  'app/Domain/Decision/QueueableSpyPlanner.php',
  'Replace id-order first-fit with a distance/novelty/yield score; dispatch and disappear.'),
 (5,'IMPL-016','Save depth — V6 proactive save, V7 variation + masking, V8 shadow waves','impl','todo','P1','W6-3',
  'FS-001,FS-002,FS-005,FS-006,FS-008,FS-009,FS-011','V6,V7,V8','specs/gameplay-algorithms.md (V6-V8)',
  'app/Domain/Decision/QueueableFleetSavePlanner.php',
  'Proactive offline-gap trigger, route × speed scoring, dispatch masking, shadow waves.'),
 (6,'IMPL-017','Fleetcrash — F1-F6 phalanx/recall/moon/recycle/lanx/moon-destruction','impl','todo','P1','W6-5',
  'CRASH-001..014','F1,F2,F3,F4,F5,F6','specs/gameplay-algorithms.md (F1-F6); research/architecture-mapping.md',
  'new planners over PhalanxService/cancelMission/RecycleMission',
  'Host-supported, zero module callers today. After 13-16; Pass-4 niche.'),
 (7,'IMPL-018','Fleet composition — U-series production + launch subset, counters, recyclers','impl','todo','P1','W6-4',
  'FLE-002,FLE-004,FLE-012,FLE-013,FLE-014,FLE-015','U-series (block to write)','specs/gameplay-algorithms.md (U1-U4); research/strategy-principles.md (FLE-012..015)',
  'app/Domain/Decision/QueueableUnitPlanner.php',
  'Next increment: payload-sized cargo, stage composition, production-vs-launch split. Parallel-safe once reviewed.'),
 (8,'IMPL-019','Ninja executor','impl','todo','P2','pass-6 (new)','NIN-001..005','(new block)',
  'research/architecture-mapping.md (pass-6)','new defence/reaction executor',
  'No executor exists. Depends on fleet timing (IMPL-017).'),
 (9,'IMPL-020','Expedition executor','impl','todo','P2','pass-6 (new)','EXP-001,EXP-002','(new block)',
  'research/architecture-mapping.md (pass-6)','new expedition executor',
  'Host expedition surface NOT re-verified — blocked on DISC-004.'),
 (10,'DEF-001','V2 reaction wake','deferred','deferred','P2','G8','FS-004','V2',
  'specs/gameplay-algorithms.md (V2)',NULL,'Deferred to the capacity runs.'),
 (11,'DEF-002','X1 transfer executor','deferred','todo','P2','G17',NULL,'X1',
  'specs/gameplay-algorithms.md (X1)',NULL,'Trigger decided; executor next slice. Depends on IMPL-021 (SP5 reserve).'),
 (12,'DEF-003','Social / ACS / alliance life (Package 6)','deferred','deferred','P3','G12,G18,S1-S4',
  'SOC-001,SOC-002,ACS-001..014','SOC1,SOC2','plan/WORK-PACKAGES.md (Package 6)',NULL,
  'Alliance-gated; deferred to Package 6.'),
 (13,'DISC-001','Re-source ACS tutorials ORG-009/010','discovery','open','P3',NULL,NULL,NULL,
  'research/source-registry.md',NULL,'Wayback failed; GF-003 suffices. Non-blocking.'),
 (14,'DISC-002','French board guide library','discovery','open','P3',NULL,NULL,NULL,
  'research/source-registry.md',NULL,'Confirmed to exist; thread URLs not retrievable. Non-blocking.'),
 (15,'DISC-003','ogamewiki.de','discovery','open','P3',NULL,NULL,NULL,
  'research/source-registry.md',NULL,'Extraction failed. Non-blocking.'),
 (16,'DISC-004','Verify expedition host surface','discovery','open','P2',NULL,'EXP-001,EXP-002',NULL,
  'research/host-capability-map.md',NULL,'Host expedition mission support unverified; blocks IMPL-020.'),
 (17,'DOC-001','Write U-series fleet-composition algorithm blocks','doc','todo','P1','W6-4',
  'FLE-002,FLE-004,FLE-009,FLE-010,FLE-011,FLE-012,FLE-013,FLE-014,FLE-015','U-series (new)',
  'specs/gameplay-algorithms.md (U1-U4); research/strategy-principles.md (FLE-012..015)',
  'plan/details/specs/gameplay-algorithms.md',
  'Production-vs-launch split, stage composition, counters, recycler sizing, payload-sized cargo. Documentation only; unblocks IMPL-018.'),
 (18,'DOC-002','Write ninja algorithm block','doc','todo','P2','pass-6 (new)','NIN-001..005','N-series (new)',
  'research/strategy-principles.md (Ninja); research/architecture-mapping.md (pass-6)',
  'plan/details/specs/gameplay-algorithms.md',
  'Defender counter-crash: staging, combat-second landing, bait, anti-ninja checks. Documentation only; unblocks IMPL-019.'),
 (19,'DOC-003','Write expedition algorithm block','doc','todo','P2','pass-6 (new)','EXP-001,EXP-002','E-series (new)',
  'research/strategy-principles.md (Expeditions)',
  'plan/details/specs/gameplay-algorithms.md',
  'Slot-16 outcomes, never-fleetsave rule. Documentation only; host surface unverified; unblocks IMPL-020.'),
 (21,'IMPL-021','SP5 — reservation before spending','impl','todo','P2','W6',
  'SP5','SP5','specs/gameplay-algorithms.md (SP5)',
  'app/Domain/Decision/QueueableBuildingPlanner.php',
  'Per-resource floor that survives a build/research purchase, reduced by production over the saving horizon. keep_resources_buffer 0.10, max_saving_hours_economy 4.0, max_saving_hours_research 6.0. Unblocks DEF-002 X1.'),
 (22,'DOC-004','Close T6/V7 residuals — mark clustering + route×speed shipped, defer relationship/contest','doc','todo','P2','W6-1,W6-3',NULL,'T6,V7',
  'plan/details/specs/gameplay-algorithms.md',
  'plan/details/specs/gameplay-algorithms.md',
  'Doc-only accuracy pass: proximity clustering is already shipped via travel_cost; V7 route x speed is resolved for deployment saves. Relationship (RAID-007) and contest (RAID-013) recorded as deferred.');

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
 (9, 16, 'needs a verified expedition host surface'),
 (9, 19, 'needs the expedition block written first'),
 (9, 1, 'review'),
 (21, 1, 'all impl gated on review'),
 (11, 21, 'X1 transport keeps the SP5 reserve on the source');
