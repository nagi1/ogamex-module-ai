# Host facts the reviewer cannot see (read-only, 2026-10-01 run 2 @ 9ce5a9c)

Host root: /home/nagi/code/ogamex-next (contains artisan). Module: Modules/AI.

## 1. AllianceApplication model
- File: `app/Models/AllianceApplication.php`
- Table: `alliance_applications` (Laravel convention; no explicit `$table`; migration
  `database/migrations/2025_12_24_003543_create_alliance_applications_table.php`).
- Columns (migration): id, alliance_id (FK alliances cascade), user_id (FK users cascade),
  application_message (text nullable), status (tinyint default 0), viewed (bool default false),
  timestamps. Unique (alliance_id, user_id); indexes (alliance_id, status), (user_id, status).
- `$fillable` (PHP attribute, lines 38-44): alliance_id, user_id, application_message, status, viewed.
- Status constants (lines 52-54): `STATUS_PENDING = 0`, `STATUS_ACCEPTED = 1`, `STATUS_REJECTED = 2`.

## 2. How the host decides a player is inactive (i/I markers)
- File: `app/Services/PlayerService.php`
  - `isInactive()` (lines ~211-224): last activity = `Date::createFromTimestamp((int)$this->user->time)`;
    inactive when `diffInDays(now()) >= 7`.
  - `isLongInactive()` (lines ~227-240): same `users.time` column; long inactive when `diffInDays(now()) >= 28`.
- Column: `users.time` (integer timestamp). Thresholds: 7 days -> (i); 28 days -> (I).
- Galaxy rendering: `app/Http/Controllers/GalaxyController.php` lines 590-591 expose
  `'isInactive' => $player->isInactive()` and `'isLongInactive' => $player->isLongInactive()`.

## 3. BuddyRequest model
- File: `app/Models/BuddyRequest.php`
- Table: `buddy_requests` (migration `database/migrations/2025_12_07_163806_create_buddy_requests_table.php`).
- Columns: id, sender_user_id (FK users cascade), receiver_user_id (FK users cascade), status
  (tinyint default 0), message (text nullable), viewed (bool default false), timestamps.
  Unique (sender_user_id, receiver_user_id); indexes (receiver_user_id, status), (sender_user_id, status).
- `$fillable` (lines 38-44): sender_user_id, receiver_user_id, status, message, viewed.
- Status constants (lines 52-54): `STATUS_PENDING = 0`, `STATUS_ACCEPTED = 1`, `STATUS_REJECTED = 2`.

## 4. Mission type ids (app/GameMissions/*Mission.php) — `protected static int $typeId = N;`
- AttackMission.php:35 -> 1
- TransportMission.php:23 -> 3
- DeploymentMission.php:22 -> 4
- AcsDefendMission.php:21 -> 5
- EspionageMission.php:34 -> 6
- ColonisationMission.php:25 -> 7
- RecycleMission.php:26 -> 8
- MoonDestructionMission.php:36 -> 9
- MissileMission.php:42 -> 10
- ExpeditionMission.php:66 -> 15

## 5. SHOW CREATE TABLE (grand database, ogamex-grand) — verbatim below
*************************** 1. row ***************************
       Table: fleet_missions
Create Table: CREATE TABLE `fleet_missions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `type_from` int NOT NULL DEFAULT '1',
  `planet_id_from` int unsigned DEFAULT NULL,
  `galaxy_from` int DEFAULT NULL,
  `system_from` int DEFAULT NULL,
  `position_from` int DEFAULT NULL,
  `type_to` int NOT NULL DEFAULT '1',
  `planet_id_to` int unsigned DEFAULT NULL,
  `galaxy_to` int DEFAULT NULL,
  `system_to` int DEFAULT NULL,
  `position_to` int DEFAULT NULL,
  `mission_type` int NOT NULL,
  `union_id` bigint unsigned DEFAULT NULL,
  `union_slot` tinyint DEFAULT NULL COMMENT 'Slot number in the union (1-16), 1 = initiator',
  `time_departure` bigint NOT NULL DEFAULT '0',
  `time_arrival` bigint NOT NULL DEFAULT '0',
  `time_arrival_ms` bigint NOT NULL DEFAULT '0',
  `time_holding` int DEFAULT NULL,
  `metal` double NOT NULL DEFAULT '0',
  `crystal` double NOT NULL DEFAULT '0',
  `deuterium` double NOT NULL DEFAULT '0',
  `deuterium_consumption` double NOT NULL DEFAULT '0',
  `light_fighter` int NOT NULL DEFAULT '0',
  `heavy_fighter` int NOT NULL DEFAULT '0',
  `cruiser` int NOT NULL DEFAULT '0',
  `battle_ship` int NOT NULL DEFAULT '0',
  `battlecruiser` int NOT NULL DEFAULT '0',
  `bomber` int NOT NULL DEFAULT '0',
  `destroyer` int NOT NULL DEFAULT '0',
  `deathstar` int NOT NULL DEFAULT '0',
  `small_cargo` int NOT NULL DEFAULT '0',
  `large_cargo` int NOT NULL DEFAULT '0',
  `colony_ship` int NOT NULL DEFAULT '0',
  `recycler` int NOT NULL DEFAULT '0',
  `espionage_probe` int NOT NULL DEFAULT '0',
  `interplanetary_missile` int NOT NULL DEFAULT '0',
  `target_priority` int DEFAULT NULL,
  `pathfinder` int NOT NULL DEFAULT '0',
  `reaper` int NOT NULL DEFAULT '0',
  `crawler` int NOT NULL DEFAULT '0',
  `processed` tinyint NOT NULL DEFAULT '0',
  `processed_hold` int NOT NULL DEFAULT '0',
  `canceled` tinyint NOT NULL DEFAULT '0',
  `retreat_after_defender_retreat` tinyint(1) NOT NULL DEFAULT '0',
  `arrival_job_id` bigint unsigned DEFAULT NULL,
  `hold_job_id` bigint unsigned DEFAULT NULL,
  `wreck_field_data` json DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fleet_missions_planet_id_from_foreign` (`planet_id_from`),
  KEY `fleet_missions_planet_id_to_foreign` (`planet_id_to`),
  KEY `fleet_missions_parent_id_foreign` (`parent_id`),
  KEY `fleet_missions_union_id_index` (`union_id`),
  KEY `idx_fm_type_canceled_departure` (`mission_type`,`canceled`,`time_departure`),
  KEY `idx_fm_type_canceled_arrival` (`mission_type`,`canceled`,`time_arrival`),
  KEY `idx_fm_user_type_canceled_departure` (`user_id`,`mission_type`,`canceled`,`time_departure`),
  KEY `fleet_missions_arrival_processing_index` (`processed`,`canceled`,`time_arrival`,`time_arrival_ms`),
  CONSTRAINT `fleet_missions_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `fleet_missions` (`id`),
  CONSTRAINT `fleet_missions_planet_id_from_foreign` FOREIGN KEY (`planet_id_from`) REFERENCES `planets` (`id`),
  CONSTRAINT `fleet_missions_planet_id_to_foreign` FOREIGN KEY (`planet_id_to`) REFERENCES `planets` (`id`),
  CONSTRAINT `fleet_missions_union_id_foreign` FOREIGN KEY (`union_id`) REFERENCES `fleet_unions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fleet_missions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=30146 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
*************************** 1. row ***************************
       Table: building_queues
Create Table: CREATE TABLE `building_queues` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `planet_id` int unsigned NOT NULL,
  `object_id` int NOT NULL,
  `object_level_target` int NOT NULL,
  `is_downgrade` tinyint(1) NOT NULL DEFAULT '0',
  `time_duration` bigint NOT NULL DEFAULT '0',
  `time_start` bigint NOT NULL DEFAULT '0',
  `time_end` bigint NOT NULL DEFAULT '0',
  `metal` double NOT NULL DEFAULT '0',
  `crystal` double NOT NULL DEFAULT '0',
  `deuterium` double NOT NULL DEFAULT '0',
  `building` tinyint NOT NULL DEFAULT '0',
  `processed` tinyint NOT NULL DEFAULT '0',
  `dm_halved` tinyint NOT NULL DEFAULT '0',
  `dm_completed` tinyint NOT NULL DEFAULT '0',
  `canceled` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `building_queues_planet_id_foreign` (`planet_id`),
  CONSTRAINT `building_queues_planet_id_foreign` FOREIGN KEY (`planet_id`) REFERENCES `planets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41445 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
*************************** 1. row ***************************
       Table: research_queues
Create Table: CREATE TABLE `research_queues` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `planet_id` int unsigned NOT NULL,
  `object_id` int NOT NULL,
  `object_level_target` int NOT NULL,
  `time_duration` bigint NOT NULL DEFAULT '0',
  `time_start` bigint NOT NULL DEFAULT '0',
  `time_end` bigint NOT NULL DEFAULT '0',
  `metal` double NOT NULL DEFAULT '0',
  `crystal` double NOT NULL DEFAULT '0',
  `deuterium` double NOT NULL DEFAULT '0',
  `building` tinyint NOT NULL DEFAULT '0',
  `processed` tinyint NOT NULL DEFAULT '0',
  `canceled` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `research_queues_planet_id_foreign` (`planet_id`),
  CONSTRAINT `research_queues_planet_id_foreign` FOREIGN KEY (`planet_id`) REFERENCES `planets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=151690 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
*************************** 1. row ***************************
       Table: unit_queues
Create Table: CREATE TABLE `unit_queues` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `planet_id` int unsigned NOT NULL,
  `object_id` int NOT NULL,
  `object_amount` int NOT NULL,
  `time_duration` bigint NOT NULL DEFAULT '0',
  `time_start` bigint NOT NULL DEFAULT '0',
  `time_end` bigint NOT NULL DEFAULT '0',
  `time_progress` bigint NOT NULL DEFAULT '0',
  `object_amount_progress` int NOT NULL DEFAULT '0',
  `metal` double NOT NULL DEFAULT '0',
  `crystal` double NOT NULL DEFAULT '0',
  `deuterium` double NOT NULL DEFAULT '0',
  `processed` tinyint NOT NULL DEFAULT '0',
  `dm_halved` tinyint NOT NULL DEFAULT '0',
  `dm_completed` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `unit_queues_planet_id_foreign` (`planet_id`),
  CONSTRAINT `unit_queues_planet_id_foreign` FOREIGN KEY (`planet_id`) REFERENCES `planets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=210681 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
*************************** 1. row ***************************
       Table: chat_messages
Create Table: CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int unsigned NOT NULL,
  `recipient_id` int unsigned DEFAULT NULL,
  `alliance_id` bigint unsigned DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reply_to_id` bigint unsigned DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_messages_reply_to_id_foreign` (`reply_to_id`),
  KEY `chat_messages_sender_id_recipient_id_index` (`sender_id`,`recipient_id`),
  KEY `chat_messages_recipient_id_sender_id_index` (`recipient_id`,`sender_id`),
  KEY `chat_messages_alliance_id_created_at_index` (`alliance_id`,`created_at`),
  KEY `chat_messages_created_at_index` (`created_at`),
  CONSTRAINT `chat_messages_alliance_id_foreign` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_recipient_id_foreign` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_reply_to_id_foreign` FOREIGN KEY (`reply_to_id`) REFERENCES `chat_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=390 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

## 6. Highscore
- Model: `app/Models/Highscore.php` — table `highscores` (migration
  `database/migrations/2024_10_28_232803_create_highscore_table.php`).
- Columns: player_id, general, economy, research, military_built, military_destroyed, military_lost,
  general_rank, economy_rank, research_rank, military_built_rank, military_destroyed_rank, military_lost_rank.
- Recalculation (scheduler, `routes/console.php` lines 26-30), all every 5 minutes:
  - `GenerateHighscores::class` -> everyFiveMinutes()
  - `GenerateAllianceHighscores::class` -> everyFiveMinutes()
  - `GenerateHighscoreRanks::class` -> everyFiveMinutes()

## 7. local-docker-dev/docker-compose.grand.yml
Services (compose name `ogamex-grand`):
- `ogamex-app` (PHP-FPM, CONTAINER_ROLE=app, port 9001:9000)
- `ogamex-scheduler` (CONTAINER_ROLE=scheduler)
- `ogamex-queue-worker` (CONTAINER_ROLE=queue, profile "queue")
Queue workers: ONE queue-worker container running Horizon; `AI_HORIZON_WORK_PROCESSES=10` and
`AI_HORIZON_LANGUAGE_PROCESSES=1`. `QUEUE_CONNECTION=redis`, `HORIZON_PREFIX=ogamex_grand_horizon:`.
Cognition env: `AI_COGNITION_MODE=external`, `AI_COGNITION_DRIVER=fatima`, `AI_EXPERIENCE_DRIVER=cbrkit`,
`AI_MEMORY_DRIVER=agentos`. Generative lanes: `AI_LANGUAGE_ENABLED=true`, `AI_LANGUAGE_MODEL=deepseek-flash`,
`AI_LANGUAGE_AI_TO_AI=true`, `AI_CONVERSATION_ENABLED=true`, `AI_CAMPAIGN_CONSULTATION_MODE=advice`,
`AI_CAMPAIGN_CONSULTATION_MODEL=deepseek-flash`.
