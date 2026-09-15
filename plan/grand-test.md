# Grand test — one hour, the whole AI module, end to end

**Status: proposed — awaiting owner approval. Nothing below has been started.**

One real, end-to-end run of Packages 1–5 in a single hour: a dedicated universe, 10 AI accounts
staged at different points of the capability chain, game speed cranked to max, and a bounded
dispatch window that makes the whole cohort decide and act within the hour. The review loop then
reads the accounts' own artifacts to judge the play and turn each shortcoming into an algorithm
change. The universe lives in its own database that is **never cleared, never re-seeded and never
hand-edited** — the same way a production database is treated.

This is not a unit test and not the disclosed human pilot. It is the fast machine-observable check
that "we are on the right foot": every executable capability runs through the real host path, the
decisions are sane, the observability reads work, and the cost is zero provider calls.

---

## 1. What this test answers (in one hour)

1. **Does every executable capability actually execute?** build, research, queue units, colonize,
   fleetsave, spy, raid — each accepted by the host at least once, not merely decided.
2. **Are the decisions sane?** The right persona does the right thing: miners build and never
   attack; turtles build defence; fleeters fleetsave/spy/raid; traders colonize; casuals do nothing
   when nothing safe exists.
3. **Does the observability work end to end?** `ai:pilot-report --json`, `ai:explain-decision`,
   `ai:record-score-samples`, stop counters and receipts all answer with real figures.
4. **Is anything stuck?** Work items that sit Pending/Retry, receipts stuck Processing, worker
   failures, refusal reasons — each with the reason the host or the module gave.
5. **Zero generative/LLM calls** — the decision path makes no provider request. The three local
   cognition sidecars (FAtiMA, CBRKit, AgentOS) are in scope and expected: their HTTP calls are
   counted as driver calls, never as tokens.

What it deliberately does **not** answer in an hour: cadence and authenticity (uptime shape,
reaction latency, save-fail rate, growth-curve self-similarity). Those need days of wall-clock and
stay a separate, later soak (§8).

---

## 2. How one hour is enough — three accelerators

A fresh account cannot colonize or raid in an hour even at max speed, and a single session per
account is not enough to see the chain. Three levers together make the hour cover the whole module:

**1. Max game speed.** The host `SettingsService` reads universe speed from the `settings` table.
Raising these compresses the *game* timeline (builds, research, flight times):

| Setting key | Default | Grand-test value | Why |
| --- | --- | --- | --- |
| `economy_speed` | 1 | **1000** | Mine production and build times are 1000× |
| `research_speed` | 1 | **1000** | Research time divides by `economy × research` = 1,000,000× → instant |
| `fleet_speed` | 1 | **1000** | Raids, colonizations, fleetsaves fly in ~1 s |
| `fleet_speed_war` / `_holding` / `_peaceful` | 1 | **1000** | Same, for each flight type |
| `basic_income_*` | 30/15/0/0 | **left at default** | Irrelevant next to 1000× mines; opening stays authentic |

**Verified against the host formulas:** build time, unit time and fleet duration each divide by the
speed value and floor at 1 second, with only a `speed == 0 → 1` guard — so 1000× is safe: no
division-by-zero and no overflow, every queue item just completes in one second. Research compounds
`economy × research`, which at 1000/1000 is 1,000,000× and fully instant. Past ~100× there is no
further benefit for build/research/flight — everything is already at the 1-second floor — so 1000× is
chosen because it is free, not because it is what makes the hour work. The hour's real ceiling is the
session cadence, not these numbers.

**2. Fresh accounts, authentic cadence.** Every AI account starts exactly where a normal player
starts — zero buildings, zero research, default resources, one Homeworld — and plays on its own
schedule (2–11 sessions a day, per persona). Nothing is gifted: every building is one the account
queued through its own planner. No staging, no cadence compression.

**3. Long as it takes.** One build per session at human cadence means the opening (solar plant →
mines → robot factory → shipyard → research lab) unfolds over real days, and that is the point: the
test measures whether the account plays the opening correctly from zero, not whether it can be raced
to the late game. The run continues through research, units and beyond; each window is read daily.

**Not compressed:** the host's own bot-detector ceilings (never react faster than 10 s, fewer than
18 active hours/7 days) are left untouched and are not what the hour is measuring.

---

## 3. The holy universe (separate database, treated as production)

### 3.1 Database

- New MySQL database **`ogamex-grand`** on the existing host MySQL (`host.docker.internal:3306`,
  root, empty password). The name carries no `test` token, so `scripts/ogamex reap` (which refuses
  non-test databases) can never reap its sessions, and no parallel-test worker will clone it.
- **Treat it as production data** from the moment it is created:
  - Never `migrate:fresh`, `migrate:rollback`, or `db:wipe`.
  - Never re-run a seeder. `ai:seed-test-universe` is idempotent but is run **once**; the
    world-creation seeder below is run **once**.
  - Never run the Pest suite or PCOV coverage against it — those use `ogamex-test` only.
  - Never hand-edit a row. All writes come from the module's own path (scheduler + queue worker).
  - Observation is read-only: `ai:pilot-report`, `ai:explain-decision`, `ai:record-score-samples`
    and plain SQL `SELECT`s.
- **Backup before start and a daily dump** while the run is live (`mysqldump ogamex-grand`), stored
  under `storage/grand-backups/` (git-ignored). The dump is the rollback point if anything is ever
  needed back.

### 3.2 Stack isolation (so the holy DB is never the test DB)

The default `local-docker-dev` stack points every container at `ogamex-test`, which the test suite
and coverage run against. The grand universe runs as a **separate Compose project** with its own
containers, all pointed at `ogamex-grand`:

- New file `local-docker-dev/docker-compose.grand.yml`, project name `ogamex-grand`, with three
  services only — `ogamex-app`, `ogamex-scheduler`, `ogamex-queue-worker` (queue profile) — reusing
  the same image and the same `..:/var/www` bind mount as the dev stack.

  ```yaml
  # local-docker-dev/docker-compose.grand.yml — sketch (all §3.5 knobs in one place)
  name: ogamex-grand
  x-grand-env: &grand-env
    DB_HOST: host.docker.internal
    DB_DATABASE: ogamex-grand
    DB_USERNAME: root
    DB_PASSWORD: ""
    REDIS_HOST: host.docker.internal
    REDIS_PORT: 6379
    QUEUE_CONNECTION: redis          # Horizon controls the workers
    CACHE_DRIVER: redis
    CACHE_PREFIX: ogamex_grand
    HORIZON_PREFIX: ogamex_grand_horizon:
    AI_HORIZON_WORK_PROCESSES: "8"
    AI_COGNITION_MODE: hybrid
    AI_COGNITION_DRIVER: fatima
    AI_EXPERIENCE_DRIVER: cbrkit
    AI_MEMORY_DRIVER: agentos
    GRAND_PLAYERS: "10"
    GRAND_SESSIONS_PER_ACCOUNT: "6"
    GRAND_STAGGER_SECONDS: "30"
    GRAND_DISPATCH_INTERVAL_SECONDS: "30"
  services:
    ogamex-app:
      image: ogamex-local-docker-dev:latest
      volumes: ["..:/var/www", "../php/local.ini:/usr/local/etc/php/conf.d/local.ini"]
      extra_hosts: ["host.docker.internal:host-gateway"]
      environment: { <<: *grand-env, CONTAINER_ROLE: app }
      ports: ["9001:9000"]            # distinct PHP-FPM port, no reverb
    ogamex-scheduler:
      image: ogamex-local-docker-dev:latest
      volumes: ["..:/var/www", "../php/local.ini:/usr/local/etc/php/conf.d/local.ini"]
      extra_hosts: ["host.docker.internal:host-gateway"]
      environment: { <<: *grand-env, CONTAINER_ROLE: scheduler }
    ogamex-queue-worker:
      image: ogamex-local-docker-dev:latest
      volumes: ["..:/var/www", "../php/local.ini:/usr/local/etc/php/conf.d/local.ini"]
      extra_hosts: ["host.docker.internal:host-gateway"]
      environment: { <<: *grand-env, CONTAINER_ROLE: queue }
      profiles: ["queue"]
  ```

  Same image, same bind-mounted code (so deploys are shared — restart the grand worker after a
  deploy), but its own project, DB, queues, cache prefix and ports. Every knob the hour varies is
  one env line in this file, never a code edit.
- Overrides on the grand services: `DB_DATABASE=ogamex-grand`, a distinct PHP-FPM port
  (e.g. `9001`) so it does not collide with the dev app, and **no reverb** (no humans need the web
  socket).
- Cache + queue isolation: the dev and grand stacks share the same bind-mounted `storage/`, so the
  file cache would be shared across two universes. The grand stack sets `CACHE_DRIVER=redis` with a
  distinct `CACHE_PREFIX=ogamex_grand`, and its queue runs on `redis` (Horizon) with a distinct
  `HORIZON_PREFIX` / redis queue prefix, so neither the module's `Cache::lock` nor the `ai` lane ever
  shares keys with the dev stack (which keeps `database` queue + file cache).
- The three cognition sidecars (cbrkit `8091`, fatima `8092`, agentos `8093`) are host-level
  services reached over `host.docker.internal`, shared with the dev stack. The grand accounts are
  a separate player-id space, and the drivers are stateless per request (cbrkit/agentos) or reset
  per appraisal (fatima re-sends its authored scenario), so concurrent dev and grand use cannot
  cross-contaminate. All three stay up for the hour (see `docker/cognition/docker-compose.yml`).
- The module enable flag (`modules_statuses.json`, `"AI": true`) is host-global and already set; it
  needs no change. The dev scheduler keeps running `ai:run-due-work` against `ogamex-test` where no
  profiles exist, so it is a no-op and the two universes never touch.

### 3.3 World creation (one time, in order)

Run inside the grand `ogamex-app` container, all against `ogamex-grand`:

1. `php artisan migrate --force`, then the module's own migrations via
   `php artisan migrate --path=/var/www/Modules/AI/database/migrations --realpath --force` — the
   module does not register its migration path with the host, so a plain `migrate` would leave the
   AI tables absent.
2. `php artisan ai:seed-grand-test --confirm` — the **one new module dev command** the hour needs
   (§3.4). It creates, in one idempotent pass:
   - a human first account (admin, so seeding is allowed to proceed),
   - the varied neighbours the AI needs: a defence-heavy turtle, a probe/cargo explorer, a fleeter,
     **an inactive account shown as `(i)` in the galaxy** (the raid/spy target), and a vacation-mode
     account,
   - the 10 AI accounts through the real registration path, staged per §4,
   - the seeded inbound messages that trigger the social scenarios (§7),
   - several session work items per account, all `due_at = now`, staggered a few seconds apart so
     the per-player lock serializes them instead of colliding.
   Like `ai:seed-test-universe` it refuses production and refuses to be the first account; it is run
   once at world creation and never again.
3. Set the §2 settings rows (a small one-shot write through `SettingsService`) — a server-admin
   action, not an AI action.
4. `docker compose restart ogamex-queue-worker` on the grand project so the worker holds the code we
   are about to measure (and restart it again on every code deploy, per the standing rule).
5. `php artisan ai:cognition-conformance --confirm --mode=hybrid` — proves all three sidecars answer
   and the hybrid combiners resolve, so a later figure can be attributed to a real driver
   contribution rather than a silent fallback to native.

From then on the grand scheduler dispatches due work every minute and the queue worker runs the
sessions; the accounts advance on their own schedules and nothing further is driven by hand.

### 3.4 The one code change the hour needs — `ai:seed-grand-test`

A module dev command that seeds the 10 accounts through the real registration path and adds a
couple of inbound greetings from the human account. It stages nothing — the accounts start at zero
like a human registration — and changes no decision, planner or executor. Gated behind `--confirm`
+ production refusal like its sibling; everything it writes is world creation, never a later
hand-edit of the holy DB.

**Worker count is not a code change.** The grand stack runs under Horizon (`QUEUE_CONNECTION=redis`),
and the module's Horizon plan already exposes the AI lane's process ceiling as
`AI_HORIZON_WORK_PROCESSES`: `config/horizon.php` → `processes` → `HorizonConfiguration` writes each
environment's `supervisor-ai.maxProcesses` from it. Set it to 8; no module or host code changes. This
is exactly why the module owns the `ai` lane through Horizon rather than through the database-driver
supervisor fragment.

Nothing else changes: no decision, planner, executor, scheduler or host-game-logic edit.

### 3.5 The knobs (env vars)

Everything the hour varies is an env var. The only thing left alone is the scheduler's one-minute
tick, which we bypass with the manual dispatch loop rather than re-tune.

| Env | Default | Grand-test value | What it controls |
| --- | --- | --- | --- |
| `economy/research/fleet_speed` (settings table) | 1 | **1000** | set once via `SettingsService` at world creation (§5) |
| `QUEUE_CONNECTION` | database | **redis** | Horizon controls the workers; the database-driver fragment is bypassed |
| `AI_HORIZON_WORK_PROCESSES` | 1 | **8** | `supervisor-ai.maxProcesses` — the AI lane's worker ceiling (10 vCPUs; ~10 accounts is the natural ceiling) |
| `AI_HORIZON_LANGUAGE_PROCESSES` | 1 | 1 | language lane stays at one worker (off anyway) |
| `HORIZON_FLEET_LIGHT_MAX_PROCESSES` / `HORIZON_FLEET_HEAVY_MAX_PROCESSES` | 2 / 3 | unchanged | fleet-arrival workers (1-second flights land here) |
| `AI_POPULATION_DISPATCH_BATCH_SIZE` | 100 | unchanged | work items admitted per dispatch pass |
| `AI_POPULATION_SESSION_ACTION_CAP` | 1 | 1 | actions per session (kept at 1: one decision per session is the unit under test) |
| `AI_COGNITION_MODE` | external | **hybrid** | native always + each driver alongside — every capability used to full extent |
| `AI_COGNITION_DRIVER` | native | **fatima** | FAtiMA/CiF appraisal + social volition (`host.docker.internal:8092`) |
| `AI_EXPERIENCE_DRIVER` | native | **cbrkit** | CBRKit ranked outcome retrieval (`host.docker.internal:8091`) |
| `AI_MEMORY_DRIVER` | native | **agentos** | AgentOS ranked long-term recall (`host.docker.internal:8093`) |
| `AI_CONVERSATION_ENABLED` | true | true | the social scenarios need replies |
| `AI_LANGUAGE_ENABLED` | false | false | stay provider-off |

---

## 4. The 10-account cohort — fresh, like any new player

Each account is an ordinary account: the host registration path, one Homeworld, zero buildings,
zero research, default resources, staggered join date, renamed homeworld, per-account seed, and a
persona the seeding assigns (miner/turtle/fleeter/trader/casual × 2, spread across skill bands).
Nothing is gifted — the only difference from a human registration is that the module is watching.

| # | Archetype | What the test watches |
| --- | --- | --- |
| 1 | Miner | opening: solar plant → mines → robot factory, never attacks |
| 2 | Turtle | opening + defence units once the shipyard is reachable, never attacks |
| 3 | Fleeter | opening + cargo/probe/research, then spy/raid/fleetsave in the late game |
| 4 | Trader | opening → colony ship → colonize once astrophysics is researched |
| 5 | Casual | sparse; do-nothing when nothing safe exists |
| 6 | Miner | efficient economy, research (energy/astro/plasma) |
| 7 | Turtle | defence that makes attacks unprofitable |
| 8 | Fleeter | fleetsave + occasional (named) save failure |
| 9 | Trader | colonize, probe/cargo; answers inbound greetings |
| 10 | Casual | answers once, then quiet |

The late-game capabilities (colonize, spy, raid, fleetsave) are not reachable in the first hour —
a fresh account must first walk the opening, one build per session. The run continues on the
accounts' own schedules until the chain reaches them; each daily read reports how far the cohort got.

---

## 5. Game speed — the concrete settings change

Applied once in step 3 of world creation, through `SettingsService::set(...)`:

```text
economy_speed            = 1000
research_speed           = 1000
fleet_speed              = 1000
fleet_speed_war          = 1000
fleet_speed_holding      = 1000
fleet_speed_peaceful     = 1000
# basic_income_* left at defaults (30/15/0/0) — opening stays authentic
```

The owner may approve a different multiplier; the plan follows whatever is approved. The multiplier
is recorded in the first review record so every later figure can be interpreted against it.

---

## 6. How the run is read — the observability surface

Everything below already exists (Package 4 + the review loop). No new table, job or dashboard.

| Artifact | Command | What it proves |
| --- | --- | --- |
| Pilot window | `ai:pilot-report --days=N` / `--json` | actions accepted/refused/retried, worker failures, scheduling-lateness percentiles, provider tokens, `read_cost` |
| Decision traces | `ai:explain-decision --player=ID` / `--trace=N` / `--json` | why a session chose X — action, reason, components, ranking, evidence age (redacted) |
| Stop counters | `ai_stop_counters` + operator page | why the population was quiet today, and whether the reason is the game's or ours |
| Public growth | `ai:record-score-samples` (hourly) + `highscore` rows | the public hourly growth curve, slope and spread — the #1 externally-visible signal |
| Work + receipts | `ai_work_items`, `ai_action_receipts` | what the host actually accepted, refused, retried, left stuck |
| Memory/CBR/affect | `ai_memory_facts`, `ai_experience_cases`, `ai_emotional_episodes`, commitments | that the cognitive slices record and later recall, not just exist |
| Social | `ai_observations`, `ai_social_exchanges`, `ai_relationship_*` | AI↔AI contact breadth and bounded conversations |

**Read discipline (from the improvement-loop spec, unchanged):** one bounded pass per window, the
`--json` fields parsed rather than prose re-read, counters read where they are aggregated, zero
generative calls on the read path, and `read_cost` recorded each time. A question that needs a new
query is a **gap**, not a measurement.

---

## 7. Scenario coverage — every capability through the real path

Each row is a capability the decision engine can select; the test watches it actually execute in the
holy universe and records the figure.

| Capability | Persona that must show it | Observable (where it shows) | Pass |
| --- | --- | --- | --- |
| `build` (mines, solar, robot factory, shipyard, research lab) | Miner (fresh), staged accounts | `ai_action_receipts` Accepted; planets' buildings rise; traces show the chain order | fresh accounts build solar → mine → robot factory in prerequisites-first order; staged accounts already hold shipyard/lab |
| `research` | Miner, Fleeter | `ai_action_receipts`; research levels rise; miner takes astro/energy/plasma, fleeter combat | ≥1 research accepted per appropriate persona |
| `queue_units` (defence / cargo / colony ship / probe) | Turtle, Fleeter, Trader | receipts; fleet/defence counts rise | turtle builds defence; fleeter/trader build cargo+probe |
| `colonize` | Trader | receipt Accepted; a second planet appears | ≥1 trader colonizes once astrophysics allows |
| `fleet_save` | Fleeter (late) | traces `fleetsave_skip_reason`; fleet missions | ≥1 fleetsave dispatched; the skip reason is visible in the trace when it skips |
| `spy` | Fleeter, Trader | espionage reports; traces | probes hit the inactive neighbour with escalating probe counts |
| `raid` | Fleeter | battle reports; receipts; profit test (loot ≥ 3× deut, ≤6 attacks/planet/24 h) | ≥1 profitable raid on the inactive target, bashing respected |
| `save_resources` | any (trace-only intent, no executor) | traces | recorded as intent, never falsely shown executed |
| social (greeting/thanks/trade/cooperation approach/ceasefire) | pairs of AI accounts | `ai_social_exchanges`, replies | known exchanges answered; unknown intent answered with silence (never guessed) |
| affect / memory / CBR / commitments | all | episodes, facts, experience cases, commitment fulfilments | records written and later recalled; no duplicated side effect |

**Negative checks (must NOT happen in the hour):** any account attacking a non-inactive neighbour
unprovoked; any reaction faster than the host's 10-second floor; activity stars on planets we have
no reason to view. (The day-scale negatives — 100% save rate, convergence, round-the-clock — belong
to the deferred soak in §8.)

---

## 8. Authenticity — deferred to a later soak, not this hour

The authenticity axes in `plan/details/research/account-authenticity.md` (reaction latency and
save-fail rate, uptime shape, growth-curve self-similarity, social breadth) are day-scale signals:
none of them can be read from an hour of compressed cadence. They stay a separate, later multi-day
soak, unchanged from the original plan.

The hour still runs the one authenticity check that *can* trip instantly: no unprovoked attack on a
non-inactive neighbour, and no reaction faster than the host's 10-second floor. Those two are in the
negative checks of §7.

---

## 9. From findings to algorithm improvements (the loop)

The user's "improve upon algorithms" is the review loop's existing path, and the grand test feeds it:

```
read a window (§6) → review record in plan/details/reviews/
→ findings with evidence class → GAP-REGISTER rows
→ named algorithm in plan/details/specs/gameplay-algorithms.md (host inputs, provenance)
→ smallest slice that closes it (gate 2) + its tests
→ measured before/after on a frozen clock + recorded seed (ai:replay-scenario)
→ DECISIONS.md entry when material; placeholder constants enter the tuning log
```

Rules that keep the loop honest, restated for this run:

- **The holy DB is the observation target, never the tuning target.** A code change is measured on a
  frozen-clock replay or a throwaway parallel universe *before* it is deployed to the holy universe;
  the holy DB is never rewritten to make a number look better.
- **A tuning change replaces a placeholder constant with a measured one** and updates the tuning log;
  a constant that never varied is still not a setting (gate 2).
- **No shortcoming is closed by a per-account constant or list** (gate 1), by a new layer or service
  (gate 2), or by behaviour no player can be named doing (gate 3).
- **Never tune against a window the live pilot is still writing into** — a measurement that a live
  session can change is not evidence about the code.
- **Code deploys to the holy universe are normal ops, not tampering:** stage → test → deploy →
  restart the grand queue worker. The world keeps appending; only future behaviour changes.

---

## 10. Run schedule — one hour

| Phase | Wall-clock | What happens | Exit criterion |
| --- | --- | --- | --- |
| 0. Approval | — | owner approves §12 | plan unblocked |
| 1. Setup | ~15 min | §3.1–§3.3 (DB, compose override, world creation, settings, worker) + one `ai:run-due-work` smoke pass | 10 profiles enabled, sessions due, settings at approved speed |
| 2. Dispatch | ~40 min | scheduler + worker run; a manual `ai:run-due-work` every ~30 s keeps the staggered sessions flowing | every capability in §7 accepted at least once, or the blocker named |
| 3. Read | mid + end (~5 min) | `ai:pilot-report --days=1 --json`, `ai:record-score-samples`, explain-decision on a sample of traces, stop counters | one review record per read exists |
| 4. Findings | after the hour | §9 loop: findings → gap register → named algorithm → smallest slice → measured on frozen clock/replay | each finding closed or explicitly parked |

Anything still mid-chain when the hour ends is recorded in the review record as a gap, not force-fit
into the hour.

---

## 11. Risks and honest limits

- **No real humans, no cadence** — the hour cannot measure "did humans find it worth playing
  against" (that is the disclosed pilot, completion-gate items 3/4) and cannot measure cadence or
  authenticity (that is the deferred soak, §8). It measures functional coverage, decision sanity,
  observability integrity and zero provider cost.
- **Staging is harness, not AI** — a staged account demonstrates a capability but does not prove the
  planner can *reach* it from scratch; that reachability is already covered by the frozen-clock test
  suite and the later soak. A staged account that still cannot act is a real finding.
- **The speed multiplier is a server setting, not a code change** — at an extreme value it may expose
  a host formula assumption. I read the formula once in setup and use the largest safe N.
- **The host bot detector is live** — an instant reaction or a round-the-clock footprint would be a
  finding, not a test failure; it is exactly what the hour's negative checks exist to catch.
- **One world, append-only** — a world-creation mistake cannot be undone by wiping; the recovery is a
  fresh dump-restore from the pre-start backup. That is why the pre-start dump exists.
- **Unmeasured stays unmeasured** — a question the current artifacts cannot answer is recorded as a
  gap in the review record, not answered by adding a query on the spot.

---

## 12. What I need from you to start (approval points)

1. **Go ahead on the plan as written**, or name changes.
2. **Speed multiplier**: proposed **1000×** for economy/research/fleet — verified safe (1-second
   floor, no overflow; research compounds to 1,000,000× and is instant).
3. **The one small change**: approve the module dev command `ai:seed-grand-test` (staged world
   creation, test harness only). Worker count needs no code change — it is the existing Horizon knob
   `AI_HORIZON_WORK_PROCESSES`.
4. **Workers**: proposed **8 AI workers** under Horizon (`QUEUE_CONNECTION=redis`,
   `AI_HORIZON_WORK_PROCESSES=8`) on the 10 vCPUs. (10 accounts is the natural ceiling; more than
   ~10 adds nothing.)
5. **Database name**: proposed **`ogamex-grand`**. Confirm.

On approval I start with Phase 1 (nothing is created or run before that), and report the world up
and the mid-hour read before Phase 2 runs unattended.
