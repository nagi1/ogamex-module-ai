## Overview

`hammermaps/OGameX-AI-Players` is a fork of `lanedirt/OGameX` (a from-scratch OGame clone on **Laravel 13**, PHP 78% / Blade 21.6%), **99 commits ahead / 177 behind** upstream, **no releases**, 0 stars/forks. Its headline addition is an **AI Players** subsystem: automated bot accounts driven by a long-running daemon, with **6 strategy profiles**, an admin CRUD + live daemon monitor, Docker integration, and — in a separate admin feature — **multi-account + bot-suspect detection** with ban/unban.

Key orientation point: AI accounts are **explicitly labelled**, not disguised — username suffix `' [ AI ]'` (`AiPlayerNameGenerator::AI_SUFFIX`) and `users.is_ai_player = true`. The anti-bot machinery is aimed at detecting *other* (human-cheater) bots, not at hiding the AI. This is a demo-server population feature, not a stealth system.

## Architecture & entry points

```
app/Enums/AiPlayerProfile.php                      profile enum + default weights + character class
app/Models/AiPlayer.php, AiPlayerLog.php           per-player record + action log
app/Models/AiDaemonStatus.php, AiDaemonMetric.php  singleton daemon state + ring-buffer metrics
app/Models/AiGlobalSettings.php                    singleton global config
app/Services/AiPlayer/AiPlayerService.php          create/delete/toggle/list/due-query
app/Services/AiPlayer/AiPlayerActionService.php    core turn engine (per player, per planet)
app/Services/AiPlayer/AiPlayerTargetService.php    strategy resolution + colonization targets
app/Services/AiPlayer/AiPlayerNameGenerator.php    sci-fi name generation
app/Services/AiPlayer/Strategies/                  AbstractStrategy + 6 profiles
app/Console/Commands/AiPlayer/*                    create/daemon/list/status (+delete per agent.md)
app/Http/Controllers/Admin/AiPlayerAdminController.php   admin CRUD/daemon/impersonate
app/Http/Controllers/Admin/ServerAdministrationController.php  bot detection + bans
routes/web.php                                     /admin/ai-players*, /admin/server-administration*
docker-compose.yml                                 ogamex-ai-daemon service
```

- **Entry points**: CLI `ogamex:ai:create {profile} [--count=1] [--difficulty=3] [--activate]`, `ogamex:ai:daemon [--interval=30] [--max-cycles=0] [--memory-limit=256] [--debug]`, `ogamex:ai:list`, `ogamex:ai:status`, `ogamex:ai:delete {id}` (documented in `agent.md`); HTTP admin routes under `Route::middleware(['auth','globalgame','locale','admin'])`.
- **Strategy resolution**: `AiPlayerTargetService::resolveStrategy()` is a `match` over `AiPlayerProfile` returning `new AggressiveStrategy()` … `new TurtleStrategy()` (instantiated with `new`, not the container).
- AI creation (`AiPlayerService::createAiPlayer`) builds a real `User` (`is_ai_player=true`, random password), assigns the profile's preferred character class, creates `UserTech`, N initial planets (`registrationPlanetAmount()`), sends a welcome message, then creates the `ai_players` row.

## Scheduling & loop model

`AiPlayerDaemonCommand::handle()` runs an **infinite `while(!$shouldStop)` loop**:

1. Registers `SIGTERM`/`SIGINT` handlers (pcntl) → set `shouldStop`.
2. Writes `ai_daemon_status`: `pid`, `status='running'`, `started_at`, heartbeat.
3. Each cycle: if `ai_global_settings.daemon_enabled` is false → idle (processed=0); else `processCycle()`.
4. Updates heartbeat + `memory_get_usage(true)` + `players_processed`; inserts `AiDaemonMetric`; prunes metrics to `MAX_ROWS = 1440`.
5. `break` if memory > `--memory-limit` (default 256 MB) or cycle count ≥ `--max-cycles`.
6. Sleeps `--interval` (default 30 s) in 1-second steps.

`processCycle()` → `AiPlayerService::getActiveDuePlayers()`:

```php
AiPlayer::where('is_active', true)
  ->where(fn($q) => $q->whereNull('next_action_at')->orWhere('next_action_at','<=',now()))
  ->with('user');
// ->limit($settings->max_concurrent_players) when > 0   (default 50)
```

Per player it calls `AiPlayerActionService::processPlayer()`, catches each exception individually (logs + writes an `AiPlayerLog` `action_type='error'`), and accumulates `total_actions_executed`.

**Next-action scheduling** (`AiPlayer::scheduleNextAction`): `next_action_at = now() + rand(action_interval_min, action_interval_max)` (defaults **60–300 s**); sets `last_action_at`. **Sleep** (`isSleeping`): now between `sleep_start`/`sleep_end` (default `01:00`–`07:00`), with overnight-wrap handling (`start > end`). **Liveness** (`AiDaemonStatus::isRunning`): `status==='running'` and heartbeat **< 120 s** old.

## Decision engine & algorithms

`AiPlayerActionService::processPlayer()` turn pipeline:

1. `isSleeping()` → log `sleep_skip`, reschedule, return.
2. `difficulty_level < 4 && rand(1,20)===1` → log `idle_skip` (human variance), reschedule, return.
3. Resolve strategy; set `user->time = now()` (keeps AI "online" in galaxy view).
4. `tryColonize()` once; then per planet: `tryBuildBuilding` → `tryStartResearch` → `tryBuildUnits` → `tryFleetAction`.
5. `scheduleNextAction()`.

Every domain action is gated by a **priority-weight dice roll**: `if (rand(1,10) > $aiPlayer->priority_X) return 0;` (building/research/fleet/colonize). Weights are per-profile defaults (see Discrete mechanisms).

`AbstractStrategy` helpers:
- `decideBuildingPriority`: negative energy → try `solar_plant`/`fusion_plant` first; pick resource-colony vs normal list; skip already-queued machine names; `canBuildObject` (requirements + valid planet type); `getStorageBottleneck` (if a build's cost exceeds storage, return the storage-building id instead); `canAffordSoon`; on nothing affordable → `pickResourceProducerFallback`; else record `resource_wait`.
- `canAffordSoon($cost)`: for each resource `available + production_per_second * maxWaitSeconds >= needed`, where `DEFAULT_MAX_AFFORD_WAIT_SECONDS = 14400` (4 h); production ≤ 0 with a shortfall → false.
- `decideResearchPriority`: same affordability scan over the research list.
- `getStorageBottleneck`: `cost > 0 && capacity > 0 && cost > capacity` → return `metal_store`/`crystal_store`/`deuterium_store` id.
- `maxAffordableUnits` (in action service): `min(floor(available/cost))` across non-zero resource costs, clamping requested build amounts.

Fleet dispatch (`tryFleetAction`): gate by fleet weight → `strategy->decideFleetAction()` → `isAllyTarget()` skip → `buildUnitCollection()` verifies ships actually present → for mission type 3 (transport) carry all current resources → `FleetMissionService::createNewFromPlanet(..., speed 10.0)`. Colonize sends mission type 7 with 1 `colony_ship`.

## Data model & persistence

Migrations: `2026_04_09_000002_create_ai_players_table.php` plus tables `ai_player_logs`, `ai_daemon_status`, `ai_daemon_metrics`, `ai_global_settings`, and `2026_04_02_000000_add_bot_detection_indexes_to_fleet_missions_table.php` (compound indexes for the three detection queries).

- **`ai_players`**: `user_id` FK cascade, `profile` varchar(20) default `neutral`, `is_active` bool default false, `difficulty_level` tinyint default 3, `priority_building/research/fleet` tinyint default 5, `last_action_at`, `next_action_at`, `action_interval_min` default 60, `action_interval_max` default 300, `sleep_start` default `01:00`, `sleep_end` default `07:00`, timestamps.
- **`ai_player_logs`**: `ai_player_id`, `action_type`, `action_data` (JSON array), `status` (`success|skipped|failed`), `error_message`, `created_at`.
- **`ai_daemon_status`** (singleton row): `pid`, `status`, `started_at`, `last_heartbeat_at`, `players_processed`, `total_actions_executed`, `memory_usage`, `error_log`, `updated_at`.
- **`ai_daemon_metrics`**: `memory_usage_bytes`, `players_processed`, `recorded_at`; `MAX_ROWS = 1440` pruned after insert.
- **`ai_global_settings`** (singleton): `daemon_enabled`, `max_concurrent_players`, `default_action_interval_min/max`, `default_sleep_start/end`, `log_retention_days`, `autoupdate_daemon_interval_seconds`, `autoupdate_logs_interval_seconds`.

## Config surface

No dedicated AI config file — configuration lives in **DB rows** (`AiGlobalSettings::singleton()` defaults: `daemon_enabled=true`, `max_concurrent_players=50`, interval 60–300, sleep `01:00`–`07:00`, `log_retention_days=30`, autoupdate 5 s / 10 s) and **`SettingsService` key/values** for bot detection. Per-player knobs are the `ai_players` columns above. Daemon flags: `--interval/--max-cycles/--memory-limit/--debug`; Docker env `AI_DAEMON_INTERVAL` (default 30) on the `ogamex-ai-daemon` service (`CONTAINER_ROLE=ai-daemon`). Bot-detection thresholds are admin-editable via `saveDetectionSettings()`.

## Edge cases & failure handling

- Daemon-level: whole cycle wrapped in try/catch → `Log::channel('ai')` + `daemonStatus->error_log`; per-player wrapped separately so one bad player never stops the loop.
- `QueueFullException` → break (safety net behind the slot-count check).
- Unmet build requirements → log `failed`, mark machine name as seen, continue scanning; unresolvable object id → break to avoid infinite loop.
- Unit cost > storage capacity → skip (log); unit amount capped to what current resources afford → else `resource_wait`.
- `buildUnitCollection` returns `null` if any required ship is missing → no dispatch.
- Storage bottleneck → auto-upgrade storage first; affordability timeout (4 h) → fallback resource producer; else `resource_wait`.
- `isAllyTarget` blocks attacks/espionage against same-alliance members.
- Colonization returns 0 if no `colony_ship`, no free target, or `shouldExpand` false.
- Admin `ServerAdministrationController` recovers **stuck missions** (`processed=0`, overdue) via normal pipeline or "recover to homeworld" credit.
- `logAction` itself catches DB write failures.

## Anti-detection & authenticity

- **AI is labelled, not hidden**: `' [ AI ]'` suffix, `is_ai_player=true`, impersonation available to admins. No covertness.
- **Human-like jitter built in**: randomized 60–300 s action spacing, per-player sleep window (default 01:00–07:00), 5 % idle-skip at `difficulty_level < 4`, activity-timestamp refresh, Neutral's 1-in-3 expedition randomization, Turtle's slow expansion.
- **Bot detection (for non-AI cheaters)** — three SQL signals plus IP sharing, all in `ServerAdministrationController`:
  - *Signal 1 — round-the-clock*: `getRoundTheClockSuspects()`.
  - *Signal 2 — instant expedition re-dispatch*: `getInstantExpeditionRedispatch()`.
  - *Signal 3 — instant fleet-save after attack*: `getInstantFleetSaveAfterAttack()`.
  - Shared-IP multi-account: same `last_ip`/`register_ip` across 2–10 users, plus cross-account missions (types 1/3/6).
- Results cached 1800 s (`bot_detection_suspects`, `bot_detection_ip_groups`), dismissible via settings JSON.
- **Nothing ties AI behavior to these thresholds** — an AI with sleep disabled could trip Signal 1 itself; the AI has no notion of "don't look like a bot".

## Discrete mechanisms

- **M01** — Profile universe — 6 cases: `aggressive|neutral|defensive|miner|raider|turtle` (`app/Enums/AiPlayerProfile.php`).
- **M02** — Default priority weights — aggressive `{b3,r5,f9}`, neutral `{5,5,5}`, defensive `{6,5,3}`, miner `{9,4,2}`, raider `{3,4,8}`, turtle `{7,4,1}` (`getDefaultPriorities()`).
- **M03** — Character-class mapping — miner/turtle→Collector, aggressive/raider/defensive→General, neutral→Discoverer (`getPreferredCharacterClass()`).
- **M04** — Priority gate — `rand(1,10) > priority_X` → skip action (`AiPlayerActionService`).
- **M05** — Sleep window — now ∈ [sleep_start, sleep_end]; overnight wrap `start>end` (`AiPlayer::isSleeping`).
- **M06** — Action jitter — `delay = rand(action_interval_min, action_interval_max)`; defaults 60/300 (`scheduleNextAction`).
- **M07** — Human-variance idle — `difficulty_level<4 && rand(1,20)===1` → `idle_skip` (5 %).
- **M08** — Due-player query — active AND (`next_action_at` null OR ≤ now), `LIMIT max_concurrent_players` (default 50).
- **M09** — Daemon liveness — `status==='running'` AND heartbeat < 120 s (`AiDaemonStatus::isRunning`).
- **M10** — Memory auto-restart — `memory_get_usage(true) > memory-limit MB` (default 256) → break.
- **M11** — Metric ring buffer — `AiDaemonMetric::MAX_ROWS = 1440`, prune `< maxId`.
- **M12** — Energy-deficit override — `energy()<0` → build `solar_plant` then `fusion_plant` first (`ENERGY_PRODUCERS`).
- **M13** — Storage bottleneck — `cost>0 && capacity>0 && cost>capacity` → return storage-building id (`STORAGE_BUILDINGS`).
- **M14** — Affordability window — `available + perSec*14400 >= needed`; `DEFAULT_MAX_AFFORD_WAIT_SECONDS=14400`.
- **M15** — Resource-producer fallback — first affordable of `metal_mine, crystal_mine, deuterium_synthesizer, solar_plant`.
- **M16** — Resource-colony test — non-homeworld AND fields `< 140` (`RESOURCE_COLONY_FIELD_THRESHOLD`); miner overrides to *all* non-homeworld.
- **M17** — Colony build list — mines + stores + `robot_factory` only (`getResourceColonyBuildingPriorityList`).
- **M18** — Queue slot cap — `QueueListViewModel::BUILDING_QUEUE_SLOT_LIMIT`.
- **M19** — Unit cap — `min(floor(available/cost))` over positive-cost resources (`maxAffordableUnits`).
- **M20** — Aggressive fleet — attack if `cruiser≥3 && small_cargo≥1`: cruiser `min(5)`, LF `min(5)` if ≥3, cargo `min(3)`; else espionage if `probe≥2` (`AggressiveStrategy`).
- **M21** — Aggressive expand — `planetCount() < 7`.
- **M22** — Raider fleet — attack if `light_fighter≥5 && small_cargo≥2`: LF `min(8)`, cargo `min(3)`; else espionage `probe≥3` (×3).
- **M23** — Raider expand — `< 6`.
- **M24** — Neutral fleet — expedition (mission 15, position 16) if `small_cargo≥1 && rand(1,3)===1`; else espionage `probe≥2` (×2).
- **M25** — Neutral expand — `< 5`.
- **M26** — Defensive units — rocket_launcher 10, light_laser 8, heavy_laser 4, gauss_cannon 2, ion_cannon 3, small_shield_dome 1, large_shield_dome 1, espionage_probe 3.
- **M27** — Defensive expand — `< 4`.
- **M28** — Turtle units — rocket_launcher 20, light_laser 15, heavy_laser 5, gauss_cannon 3, ion_cannon 5, plasma_turret 1, small/large_shield_dome 1.
- **M29** — Turtle fleet — `decideFleetAction` returns `null`; expand `< 3`.
- **M30** — Miner units — large_cargo 5, small_cargo 3, rocket_launcher 5, light_laser 3, espionage_probe 3.
- **M31** — Miner transport — `large_cargo≥2` AND any resource ≥ 50 % of its storage → transport to largest-field planet, `min(large_cargo,5)` (mission 3).
- **M32** — Miner expand — `< 8`; every non-homeworld is a resource colony.
- **M33** — Colonization — fleet gate + `shouldExpand` + first planet with `colony_ship>0`; miner→position 6–10, else 4–12; system ±20; mission 7.
- **M34** — Random nearby target — position `rand(1,15)`, system offset `±range`, re-roll if equals source (`AbstractStrategy::getRandomNearbyTarget`).
- **M35** — Ally protection — skip attack/espionage if target owner shares alliance (`isAllyTarget`).
- **M36** — Activity timestamp — `user->time = now()->timestamp` every turn.
- **M37** — Name suffix — `' [ AI ]'` (`AiPlayerNameGenerator::AI_SUFFIX`).
- **M38** — Detection Signal 1 — `COUNT(DISTINCT hour)≥18` AND `COUNT(*)≥50` AND `COUNT(*)/(lookback*(computer_tech+1))≥18`; excludes mission types 6,9.
- **M39** — Detection Signal 2 — expedition return (type 15, `parent_id` set) → next expedition (type 15, `parent_id` null) same planet, `0 < gap ≤ 10 s`, occurrences ≥ 5.
- **M40** — Detection Signal 3 — attack (type 1) → defender dispatch within 10 s, occurrences ≥ 1; excludes 6,9.
- **M41** — Shared-IP — `last_ip`/`register_ip` shared by 2–10 users.
- **M42** — Cross-account missions — types 1,3,6 between users in the same IP group.
- **M43** — Detection cache — `Cache::remember(...,1800)` for suspects + IP groups.
- **M44** — Dismissals — JSON in `dismissed_shared_ip_groups` / `dismissed_bot_suspect_ids` settings.
- **M45** — Ban durations — 86400 / 259200 / 604800 / 2592000 / permanent (temporary ⇒ vacation mode).
- **M46** — Global-settings defaults — `daemon_enabled=true`, 50 concurrent, 60–300 s, `01:00–07:00`, retention 30 d.

## Notable concerns

- **Gate-1 (hardcoded AI) — violated broadly.** Every strategy hardcodes object **machine names** as PHP arrays: `getBuildingPriorityList()`/`getResearchPriorityList()` (`metal_mine`, `solar_plant`, `battle_ship`, …), plus `AbstractStrategy` constants `ENERGY_PRODUCERS`, `RESOURCE_PRODUCERS`, `STORAGE_BUILDINGS`, and `decideUnitBuild` unit maps. Adding a host object does **not** make it reachable — it requires editing these arrays. This is precisely the "static, hardcoded AI" gate-1 forbids.
- **Gate-3 (non-human) — random, unprofitable targeting.** `getRandomNearbyTarget` picks an arbitrary coordinate 1–15 within ±5–10 systems; aggressive/raider attack whatever is there (or nothing), with **no reconnaissance, profitability, or strength check** despite being described as "attacks weak targets". A human raids specific fat/weak targets.
- **Gate-3 — no fleet save at all.** Turtle/Defensive never send fleets; no profile implements a fleetsave (not even one "that can also fail"). AI fleets sit on planets while the account sleeps.
- **Gate-3 — no reaction to attacks/probes.** No espionage-report reading, no counter-espionage, no response to incoming attacks — only the *detector* looks at those signals, never the AI itself.
- **Gate-2 — dead/duplicated code.** `AiPlayerTargetService::findEspionageTargets()` is unused (strategies call `AbstractStrategy::getRandomNearbyTarget` directly); `AiPlayer::isDueForAction()` is unused (daemon queries SQL); `ai_global_settings.log_retention_days` (default 30) has **no consumer found** — dead config.
- **Race condition** — two daemon processes would both read the same due players (no lock/claim before `processPlayer`); safe only under the assumption of a single daemon container.
- **Difficulty is nearly inert** — `difficulty_level` only toggles the 5 % idle-skip below level 4; behavior is otherwise identical across levels 1–5.
- **Regularity** — the 30 s daemon cycle + jittered 60–300 s actions produce a highly regular cadence; combined with the constant `speed 10.0` dispatch, observable patterns remain (the fork doesn't care, since AI is labelled).
- **Label paradox for the AI module's goal** — if the goal is human-indistinguishable accounts, the `' [ AI ]'` suffix and `is_ai_player` flag are the exact opposite; useful only as a demo/population tool, not as an authenticity reference.

## Confidence

**High.** All six strategy files, `AbstractStrategy`, `AiPlayerActionService`, `AiPlayerService`, `AiPlayerTargetService`, `AiPlayerDaemonCommand`, the `AiPlayer`/`AiPlayerLog`/`AiDaemonStatus`/`AiDaemonMetric`/`AiGlobalSettings` models, the profile enum, `ServerAdministrationController` (all three detection queries + settings/defaults), `routes/web.php`, and `docker-compose.yml` were read directly from source. Two facts are inferred rather than read verbatim: the exact start command of the `ogamex-ai-daemon` container role (only the `AI_DAEMON_INTERVAL` env + role name were visible), and the absence of a `log_retention_days` consumer (lexical search on this fork returned empty results, so this is "not found" rather than proven absent). No section is speculative where the code was read.