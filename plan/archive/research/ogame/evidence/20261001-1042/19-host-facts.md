# Host facts the reviewer cannot see (read-only, 2026-10-01)

Host root: /home/nagi/code/ogamex-next (contains artisan). Module: Modules/AI.

## 1. AllianceApplication model
- File: `app/Models/AllianceApplication.php`
- Table: `alliance_applications` (Laravel convention; no explicit `$table`; migration
  `database/migrations/2025_12_24_003543_create_alliance_applications_table.php`).
- Columns (migration): id, alliance_id (FK alliances cascade), user_id (FK users cascade),
  application_message (text nullable), status (tinyint default 0), viewed (bool default false),
  timestamps. Unique index (alliance_id, user_id); indexes (alliance_id, status), (user_id, status).
- `$fillable` (PHP attribute, lines 38-44): alliance_id, user_id, application_message, status, viewed.
- Status constants (lines 52-54): `STATUS_PENDING = 0`, `STATUS_ACCEPTED = 1`, `STATUS_REJECTED = 2`.

## 2. How the host decides a player is inactive (i/I markers)
- File: `app/Services/PlayerService.php`
  - `isInactive()` (lines 211-224): last activity = `Date::createFromTimestamp((int)$this->user->time)`;
    inactive when `diffInDays(now()) >= 7`.
  - `isLongInactive()` (lines 227-240): same `users.time` column; long inactive when `diffInDays(now()) >= 28`.
- Column: `users.time` (integer timestamp).
- Galaxy rendering: `app/Http/Controllers/GalaxyController.php` lines 590-591 expose
  `'isInactive' => $player->isInactive()` and `'isLongInactive' => $player->isLongInactive()`.
- Thresholds: 7 days -> (i); 28 days -> (I).
- grep evidence: `grep -rn "inactive" app/ --include=*.php` shows the same two methods and
  `app/Services/CoordinateDistanceCalculator.php:157` "A user is considered inactive if time is
  older than 7 days (matching PlayerService::isInactive())".

## 3. BuddyRequest model
- File: `app/Models/BuddyRequest.php`
- Table: `buddy_requests` (Laravel convention; migration
  `database/migrations/2025_12_07_163806_create_buddy_requests_table.php`).
- Columns (migration): id, sender_user_id (FK users cascade), receiver_user_id (FK users cascade),
  status (tinyint default 0), message (text nullable), viewed (bool default false), timestamps.
  Unique (sender_user_id, receiver_user_id); indexes (receiver_user_id, status), (sender_user_id, status).
- `$fillable` (lines 38-44): sender_user_id, receiver_user_id, status, message, viewed.
- Status constants (lines 52-54): `STATUS_PENDING = 0`, `STATUS_ACCEPTED = 1`, `STATUS_REJECTED = 2`.

## 4. Mission type ids (app/GameMissions/*Mission.php)
Each mission class declares `protected static int $typeId = N;` (not a getTypeId() method):
- AttackMission.php:35          -> 1
- TransportMission.php:23       -> 3
- DeploymentMission.php:22      -> 4
- AcsDefendMission.php:21       -> 5
- EspionageMission.php:34       -> 6
- ColonisationMission.php:25    -> 7
- RecycleMission.php:26         -> 8
- MoonDestructionMission.php:36 -> 9
- MissileMission.php:42         -> 10
- ExpeditionMission.php:66      -> 15

## 5. SHOW CREATE TABLE (grand database, ogamex-grand)
See the raw schema in the same evidence run (this summary keeps the columns the module reads):
- `fleet_missions`: id, parent_id, user_id, type_from/type_to, planet_id_from/to, galaxy/system/position
  from/to, mission_type, union_id, union_slot, time_departure, time_arrival, time_arrival_ms, time_holding,
  metal/crystal/deuterium/deuterium_consumption, per-unit count columns (light_fighter, cruiser,
  small_cargo, recycler, ...), target_priority, processed, processed_hold, canceled,
  retreat_after_defender_retreat, arrival_job_id, hold_job_id, wreck_field_data, created_at, updated_at.
  Index `fleet_missions_arrival_processing_index` (processed, canceled, time_arrival, time_arrival_ms).
- `building_queues`: id, planet_id, object_id, object_level_target, is_downgrade, time_duration,
  time_start, time_end, metal, crystal, deuterium, building, processed, dm_halved, dm_completed,
  canceled, created_at, updated_at. (No `canceled`-less shape: has `building` + `canceled`.)
- `research_queues`: id, planet_id, object_id, object_level_target, time_duration, time_start,
  time_end, metal, crystal, deuterium, building, processed, canceled, created_at, updated_at.
- `unit_queues`: id, planet_id, object_id, object_amount, time_duration, time_start, time_end,
  time_progress, object_amount_progress, metal, crystal, deuterium, processed, dm_halved, dm_completed,
  created_at, updated_at. (No `building`/`canceled` columns — different shape from building/research.)
- `chat_messages`: id, sender_id, recipient_id (nullable), alliance_id (nullable), message (text),
  reply_to_id, read_at, created_at, updated_at, deleted_at (SOFT DELETE present).

## 6. Highscore
- Model: `app/Models/Highscore.php` — table `highscores` (migration
  `database/migrations/2024_10_28_232803_create_highscore_table.php`).
- Columns: player_id, general, economy, research, military_built, military_destroyed, military_lost,
  general_rank, economy_rank, research_rank, military_built_rank, military_destroyed_rank, military_lost_rank.
- Recalculation (scheduler, `routes/console.php` lines 26-30), all every 5 minutes:
  - `GenerateHighscores::class`  -> everyFiveMinutes()
  - `GenerateAllianceHighscores::class` -> everyFiveMinutes()
  - `GenerateHighscoreRanks::class` -> everyFiveMinutes()

## 7. local-docker-dev/docker-compose.grand.yml
Services (compose name `ogamex-grand`):
- `ogamex-app`          — PHP-FPM (CONTAINER_ROLE=app), port 9001:9000.
- `ogamex-scheduler`    — scheduler (CONTAINER_ROLE=scheduler).
- `ogamex-queue-worker` — Horizon queue worker (CONTAINER_ROLE=queue), profile "queue".

Queue workers: ONE queue-worker container; Horizon runs the AI lanes with
`AI_HORIZON_WORK_PROCESSES=10` and `AI_HORIZON_LANGUAGE_PROCESSES=1` (lines 32-35).
`QUEUE_CONNECTION=redis` (line 24), `CACHE_STORE=redis`, `HORIZON_PREFIX=ogamex_grand_horizon:`.
Cognition env (lines 39-42): `AI_COGNITION_MODE=external`, `AI_COGNITION_DRIVER=fatima`,
`AI_EXPERIENCE_DRIVER=cbrkit`, `AI_MEMORY_DRIVER=agentos`.
Generative lanes (lines 49-54): `AI_LANGUAGE_ENABLED=true`, `AI_LANGUAGE_MODEL=deepseek-flash`,
`AI_LANGUAGE_AI_TO_AI=true`, `AI_CONVERSATION_ENABLED=true`, `AI_CAMPAIGN_CONSULTATION_MODE=advice`,
`AI_CAMPAIGN_CONSULTATION_MODEL=deepseek-flash`.
