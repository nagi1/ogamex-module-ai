# Game simulation audit: time, persistence, queues, randomness

How OGameX advances the world, what can already jump through time, and how tightly each mechanic is bound to
Laravel, Eloquent and MySQL. Host paths are relative to `nagi1/ogamex-next`; module paths to this repository.

## 1. Time: the host is already lazy and timestamp-driven

| Mechanic | How it advances | Reads "now" from | Time-travel today? |
| --- | --- | --- | --- |
| Resource production | `PlanetService::updateResourcesUntil($until)` integrates `metal_production × hours` from `planets.time_last_update`, clamped to storage (`app/Services/PlanetService.php:1421-1480`). | `Date::now()` via `updateResources()` | **Yes.** Any jump length; integration is closed-form per segment. |
| Building queue | `PlanetService::updateBuildingQueue` loops over finished items, **integrating resources up to each `time_end`** before applying the level, then starts the next item at that `time_end` (`:1694-1760`). | `BuildingQueueService::retrieveFinished` (now) | **Yes**, segmented correctly. |
| Research queue | `PlayerService::updateResearchQueue` applies finished items and starts the next at `time_end` (`app/Services/PlayerService.php:679-710`). | now | Yes, but production is **not** re-segmented at research completion (plasma takes effect at the next planet update). |
| Shipyard / defence | `PlanetService::updateUnitQueue` awards `floor(elapsed / time_per_unit)` units (`:1804-1890`). | now | Yes. Solar satellites and crawlers built inside a jump do not back-fill energy for that jump. |
| Production rates | `updateResourceProductionStats` recomputes and stores integer `*_production` (`ceil`) and energy (`:2126-2350`). | n/a | Recomputed only when a planet is touched. Rates are piecewise-constant between touches. |
| Fleet missions | Delayed queue job `ProcessFleetArrival` per mission plus the minute-scheduler fallback `FleetMissionService::processMissedMissionEvents` (`app/Services/FleetMissionService.php:804-950`), under a per-destination `Cache::lock`. | `Date::now()` | **Yes**, through the fallback path: it processes everything overdue in arrival order. `ai:sim` calls it via `ogamex:scheduler:process-fleet-arrivals`. |
| Combat | `BattleEngine` (abstract) → `RustBattleEngine::fightBattleRounds` (FFI, JSON in/out). | n/a | Synchronous inside mission processing. |
| Highscore | `ogamex:scheduler:generate-highscores` every 5 min walks every player (`routes/console.php:25-29`). | n/a | Batch job; `ai:sim` runs it hourly. |
| AI sessions | `ai_work_items.due_at`, `ProcessAiWork`, successor scheduling in `SessionDecisionService`. | `AiClock` (module) and Carbon | Yes (`ai:sim`). |

**A virtual clock already exists.** `Modules\AI\Support\SimulatedTime::freezeAt()` sets the test instant of
`Carbon`, `CarbonImmutable`, `Illuminate\Support\Carbon` and the `Date` facade together, which moves every
host and module "now". `ai:sim` (`app/Console/Commands/SimulateAiTime.php`) is a discrete-event loop:

```
freeze clock at t
  → process overdue fleet arrivals (host fallback command)
  → drain due ai_work_items (sessions, then orders) through the real ProcessAiWork
  → maintenance every --maintenance seconds (alliance life, score samples, highscores hourly)
  → t = min(next due work item, next lease expiry, next fleet arrival, next maintenance, t + max-step)
```

Building/research completions are not events in this loop. They do not need to be, because they are applied
lazily when the owner is next touched, which every session does (`RunAiSessionAction` updates every planet).

### Leaks of the wall clock (things the virtual clock does not move)

| Where | Effect | Fix |
| --- | --- | --- |
| `app/Services/CoordinateDistanceCalculator.php:159`: `UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))` in SQL | Counts "active" players relative to the **database server's** real clock, not the simulated one. | Bind `Date::now()->subDays(7)->timestamp` as a parameter (host change, generic, also fixes tests). |
| Cache TTLs (`Cache::lock`, `Cache::add('ai:alliance-life:minute', ..., 60)`) | With the array store they follow Carbon; with Redis/file they follow real time. | Use the array cache store in training. |
| `PlayerService::update()` writes `users.time = now` and `last_ip = request()->ip()` | Harmless with frozen Carbon. | none |

### Update-cadence sensitivity (matters for any second implementation)

Results are **almost** independent of how often a planet is touched, but not exactly:

- production stats are recomputed at touch time, so a research or a satellite completed mid-jump changes the
  rate only from the next touch;
- the fusion plant switches off only if `deuterium == 0` **at recompute time**;
- each touch rounds production with `ceil` into an integer column.

A simulator that touches planets on a different schedule than the PHP code will drift. This is the strongest
argument for driving the real code, or for a replica that reproduces the touch schedule exactly.

## 2. Persistence and coupling map

Coupling is how much of the mechanic is Eloquent/DB-bound versus pure arithmetic. "Pure core" names the part
that could be extracted without moving rules.

| Component | Where | DB coupling | Pure core | Simulation difficulty outside PHP |
| --- | --- | --- | --- | --- |
| Resource production | `PlanetService::updateResourcesUntil`, `updateResourceProductionStatsInner`, `GameObjectProduction` closures in `app/GameObjects/BuildingObjects.php` | Medium: reads/writes `planets` columns via the model; formulas are PHP **closures** | Formula evaluation and integration | Easy to compute, **hard to keep identical** (closures are code, not data; see the float drift in [rust-performance-research.md](rust-performance-research.md#3-what-the-port-taught)) |
| Building completion | `updateBuildingQueue`, `BuildingQueueService` | High: `building_queues` rows, `retrieveFinished` query, events | Segmenting arithmetic | Medium |
| Research | `PlayerService::updateResearchQueue`, `ResearchQueueService` | High: `research_queues`, `users_tech` | Time formula | Medium |
| Shipyard | `updateUnitQueue`, `UnitQueueService` | High: `unit_queues` | Unit-count arithmetic | Medium |
| Costs, requirements, techtree | `ObjectService` (static, cached per locale) | **Low** | Almost all of it | Easy (export as tables) |
| Fleet timing and fuel | `FleetMissionService::calculateFleetMissionDuration`, `calculateConsumption`, `CoordinateDistanceCalculator` | Low (needs planet/player services) | Yes | Easy |
| Fleet missions (dispatch, arrival, return) | `GameMissions/*Mission.php`, `FleetMissionService` | **Very high**: `fleet_missions` rows, delayed jobs, destination locks, messages, debris and wreck fields, events | Little | Hard |
| Combat | `BattleEngine` + Rust `fight_battle_rounds` | Low for rounds (JSON in/out); high for setup (loot, debris, moon chance, repair, reports) | Rounds already in Rust | Rounds easy (exists); the surrounding mission logic is hard |
| Espionage | `EspionageMission`, `CounterEspionageService` | High: reports in `espionage_reports` + `messages` | Detection formula | Medium |
| Expeditions | `ExpeditionMission` (1,100+ lines) | High; **unseedable RNG** | Outcome tables | Hard |
| Colonisation | `ColonisationMission`, `PlanetServiceFactory` (planet creation uses `rand()`) | High | Little | Medium |
| Highscore | `HighscoreService`, `PlanetService::getPlanetScore*` | Medium: walks every player, writes `highscores` | Score = cumulative cost / 1000 | Easy |
| Player/planet state | `PlayerService`, `PlanetService`, `PlanetServiceFactory` (instance cache) | Very high: everything is an Eloquent model | none | n/a |
| AI module state | `ai_work_items`, `ai_schedules`, `ai_decision_traces`, `ai_goals`, `ai_intel`, `ai_relationships`, ... | Very high | none | n/a |

## 3. Queues, Redis, Horizon

- Host fleet arrivals are delayed jobs (`ProcessFleetArrival`), with a scheduler fallback that processes
  anything overdue. Training does not need the queue: the fallback is exactly a "process everything due at t"
  call. `ai:sim` sets `queue.default = sync` and uses it.
- Module AI work is `ProcessAiWork` on the `ai` Horizon lane; `ai:sim` calls `handle()` directly.
- Redis is only the queue/cache backend. With `CACHE_STORE=array` and `QUEUE_CONNECTION=sync`, the sim ran
  here without Redis or Horizon [measured].

**Answer: queues and Horizon are already bypassed by `ai:sim`. Nothing to build.**

## 4. Database: can training avoid MySQL?

### Measured (20 AI accounts, economy speed 8, native cognition; details in [benchmark-plan.md](benchmark-plan.md))

| Measurement | Result |
| --- | --- |
| Statements per work item (day 1 / days 2–3) | ~146 / ~526 |
| DB share of wall time on MySQL (localhost) | ~50% |
| Mean statement time on MySQL | ~0.17 ms (a round trip, not disk) |
| MySQL on tmpfs, `innodb_flush_log_at_trx_commit=0`, no doublewrite | **no change** (43.4 s vs 43.1 s for the same 12 h) |
| Writes among statements | < 0.2% (no INSERT/UPDATE in the top 25 statement types of 680,518) |
| Top statement families | `select users` (197k in 48 h), `ai_profiles`, `ai_relationships`, `alliance_members`, `highscores`: N+1 reads |
| **In-memory SQLite (`:memory:`), same code** | DB share 50% → 15%; statement time 0.01–0.1 ms; **1.8–2.0× faster end to end** |
| **MySQL vs in-memory SQLite parity, same start state, 12 simulated hours** | **Identical end state**: every planet's buildings, ships, defence and resources, research, queue counts and the decision histogram |

### What it took to run on SQLite

- All 140 host + module migrations ran on SQLite unchanged.
- One real bug, in the module: `RecordAiStopReasonAction` looks up `observed_on` with `toDateString()`
  (`'2026-10-05'`) while Eloquent's `date` cast stores `'2026-10-05 00:00:00'`. MySQL's `DATE` column
  normalises; SQLite stores text, so the lookup never matched and the insert hit the unique key. 4,881 failed
  sessions until patched (bench copy only; the patch is to look up by `startOfDay()`).
- Host raw SQL that is MySQL-only: `CoordinateDistanceCalculator:159` (`IF`, `UNIX_TIMESTAMP`, `DATE_SUB`,
  `NOW()`), `WreckFieldService:129, :1012` (`ORDER BY FIELD(...)`). Wreck fields appear only after battles,
  so the 12 h parity window did not reach them. These need portable rewrites before battle-heavy training.
- `lockForUpdate()` (37 sites) is a no-op on SQLite. Harmless with one process per universe, which is the
  intended training layout.

### Answer to "can we avoid MySQL entirely?"

**Yes, for training.** One universe per PHP process, in-memory SQLite, the real host and module code:
`php artisan ai:sim --in-memory --seed=N` (the source database is only read; `--save-sqlite=path` writes a
snapshot). Parity with MySQL is proven on a battle-heavy state (gate G1). The MySQL-only SQL listed above was
made portable in Phase 0.

What it does **not** fix: after SQLite, 85% of the time is PHP (perception 57 ms, planners 25–30 ms each and
called up to three times, alliance life 90–210 ms). See section 5.

## 5. Where a session's time goes (MySQL, day 3, per login, native cognition)

| Phase | ms | Queries | Notes |
| --- | --- | --- | --- |
| Host advance (all planets `update()`) | 22 | 49 | Real rules; keep. |
| Alliance life | 211 | 820 | Social. In production once per minute; disable in training. |
| Decision (`SessionDecisionService::run`) | 102 | 180 | of which perception 57 ms / 82 q, candidate factory 14 ms / 55 q (planners for eligibility), affordability ETA re-runs the building planner |
| Intent scheduling (`ScheduleAiIntentAction`) | 73 | 120 | building planner 24 ms, unit planner 29 ms, managers |
| Executors (per order) | 1–14 | 2–38 | `BuildFirstBuilding` 14 ms (host `BuildingQueueService::add`) |

Raid planning adds 50 Rust battle simulations per candidate report (`NativeRaidEstimator::SCREEN_SAMPLES`),
0.05–1.4 ms each for raid-sized fleets [measured]. That is 3–70 ms per report before the PHP wrapping.

## 6. Determinism

| Source | Seeded today? | Notes |
| --- | --- | --- |
| Module decisions | **Yes** | `SeededRandomSource` = SHA-256 of `(profile seed, context)`. |
| Persona generation | Yes | `AiPersonaFactory` from `randomSeed($index)`. |
| Battle rounds (live attacks) | **No** | `BattleEngine::$seed` is null on the live path → Rust `thread_rng`. Estimator runs are seeded. |
| Battle moon chance, defence repair | Only when seeded | `random_int` otherwise. |
| Expeditions | **No** | `random_int`, `shuffle` (`ExpeditionMission.php:338-1101`). |
| Counter-espionage, moon destruction | **No** | `random_int`. |
| Universe/planet creation | **No** | `rand()` in `PlanetServiceFactory` (slots, fields, temperature). |
| Merchant, dark matter rewards, NPC fleets | No | `rand`, `random_int`. |
| Sim event ordering | Yes | `orderBy('due_at')`, arrivals by `time_arrival, time_arrival_ms, id`. |

Measured: two runs from the same snapshot produced identical statement counts (131,707), sessions and orders,
and MySQL vs SQLite produced identical states over 12 h without battles. **Before Phase 0, determinism held for
the economy and failed as soon as an unseeded mechanic fired.**

**Phase 0 (4 Oct 2026) added the seam**: the host binds `Random\Randomizer` in the container
(`AppServiceProvider`), every game draw listed above goes through it, and a live battle draws its seed from it.
`ai:sim --seed=N` binds a seeded `Xoshiro256StarStar` engine. Result from a battle-heavy day-10 state: two seeded
runs are identical on MySQL, identical in memory, and identical across the two (gate G1 passed). The host needs one seam: a seedable
`Random\Randomizer` (PHP 8.2+) resolved from the container and used instead of `random_int`/`rand` in game
code, plus a per-mission battle seed. That change is generic (replays, tests) and so passes the module's
"host change only when independently useful" rule.
