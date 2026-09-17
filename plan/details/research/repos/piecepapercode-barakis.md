## Overview

**Barakis** (`PiecePaperCode/barakis`) is a minimal, unmaintained OGame bot written in Python. It is a thin reference implementation on top of the `pyogame` library (`alaingilbert/pyogame`). It is not a strategist: it does exactly one thing — read each planet's state and queue **one** build action per planet per scheduler pass, chosen from a single hardcoded, ordered priority list. There is no combat, no fleet saving, no expeditions, no espionage, no messaging, no research beyond "keep upgrading toward a fixed level table."

- Repo facts: 1 contributor, 3 stars, MIT license, last commit `208a3b0` "Updatet to Ogame 8.4.0" ~5 years ago (2021). Python 97.8%, Dockerfile 1.5%, Shell 0.7%.
- README claims: *"Advanced free to use Ogame Bot using the pyogame lib. This Bot is used in pyogame.net."* — "Advanced" overstates it; the bot is a fixed build queue.
- Version pinned in `requirements.txt`: `ogame~=8.4.0.22` (the `pyogame` OGame 8.4.0-era package).

## Architecture & entry points

Four source files in `src/` plus a config wrapper:

- `src/main.py` — entry point. Starts two daemon threads:
  ```py
  BOT = Thread(target=scheduler)
  API = Thread(target=lambda: serve(app, host='0.0.0.0', port=80))
  ```
  `hostname = socket.gethostname()` is computed but never used.
- `src/API.py` — Flask app served by `waitress` on port 80. Routes:
  - `GET /` → `{'response': 'This is the Barakis API'}`
  - `POST /start` → `add(universe, username, password)`
  - `POST /remove` → `remove(universe, username)`
  - `POST /active` → `active(universe, username)`
- `src/bot.py` — all bot logic: `Credentials` dataclass-ish class, global `bot_queue`, `bot()`, `scheduler()`, `add()`, `remove()`, `stop()`, `active()`.
- `src/buildings.py` — the decision engine: a single function `queue(ID, empire)` returning an ordered list of `Order` objects.
- `src/proxys.py` — `random_proxy()` helper (broken; see below).

Deployment files: `bot.dockerfile` (Python 3.9, installs requirements then `pip install git+https://github.com/alaingilbert/pyogame.git@develop` — a second, conflicting ogame source that overrides the pinned 8.4.0.22), `docker-compose.yml` (single replicated service, overlay network `api`, image `127.0.0.1:5000/barakis`), `swarm.sh` (build → push → `docker stack deploy`).

## Scheduling & loop model

`scheduler()` in `src/bot.py` is an infinite, always-on loop:

```py
while True:
    print(bot_queue)
    for creds in bot_queue:
        try:
            bot(creds)
        except Exception as e:
            print(e, traceback.print_tb(e.__traceback__))
        time.sleep(random.randint(5, 60))
    time.sleep(5)
```

- Per account: run `bot()`, then sleep a **random 5–60 s**. After the whole account list, sleep 5 s, then repeat.
- Effective visit cadence for one account ≈ every 5–60+ seconds, continuously, 24/7, with no diurnal pattern.
- `bot(creds)` per invocation:
  ```py
  empire = OGame(creds.universe, creds.username, creds.password)
  ids = empire.planet_ids()
  for ID in ids:
      for order in buildings.queue(ID, empire):
          if order.condition:
              empire.build(what=order.build, id=ID)
              break
  del empire
  ```
  So: **one build action per planet per pass** — the first `Order` whose `condition` is truthy. A fresh `OGame` session is created and destroyed every pass (full re-login each cycle).
- The queue is scanned in **fixed priority order**; only the first satisfied order fires, then `break`.

## Decision engine & algorithms

`queue(ID, empire)` in `src/buildings.py` is the entire decision engine. It pulls seven snapshots from `pyogame`:

```py
mines = empire.supply(ID)          # supply buildings + storage + energy
factory = empire.facilities(ID)    # robotics, shipyard, lab, nanite, silo
research = empire.research(ID)     # technology levels
ships = empire.ships(ID)           # fleet counts
resources = empire.resources(ID)   # current energy balance
defences = empire.defences(ID)     # defence counts
celestial_queue = empire.celestial_queue(ID)  # build queue end-times
```

It defines a tiny local class and returns a **fixed ordered list** of ~40 `Order` items:

```py
class Order:
    def __init__(self, build, condition):
        self.build: tuple = build
        self.condition: bool = condition
```

Algorithm: pure **greedy priority queue with level-cap thresholds**. No cost accounting, no ratio planning (metal/crystal/deuterium mining levels are independent fixed caps), no production simulation, no fleet composition logic beyond one colony ship. "Decision" = "highest-priority thing whose level/count is below its cap and whose prerequisite is met." The `celestial_queue.shipyard < datetime.datetime.now()` gate is the only lookahead: defence/shipyard items only fire once the shipyard queue is empty.

## Data model & persistence

- **None.** No database, no files. `bot_queue: list[Credentials]` is in-memory only:
  ```py
  class Credentials:
      def __init__(self, universe, username, password):
          self.universe = universe
          self.username = username
          self.password = password
  ```
- A restart loses all registered accounts. `requirements.txt` declares `redis`, but `redis` is never imported anywhere — dead dependency.
- Credentials are held in plaintext in memory and printed every pass by `print(bot_queue)` (passwords leak to stdout/logs).

## Config surface

- No config file, no environment variables, no command-line flags. The only "configuration" is editing the source: the threshold table in `buildings.py`, and hardcoded values in `docker-compose.yml` / `bot.dockerfile`.
- The proxy is configured by **commenting/uncommenting** a line in `bot.py`:
  ```py
  empire = OGame(creds.universe, creds.username, creds.password,
      # proxy=proxys.random_proxy()
  )
  ```
  It is currently disabled.
- Universe/credentials arrive only at runtime via the REST API (`/start`).

## Edge cases & failure handling

- Every exception in a `bot()` pass is caught, printed, and swallowed:
  ```py
  except Exception as e:
      print(e, traceback.print_tb(e.__traceback__))
  ```
  No backoff, no retry policy beyond the loop naturally retrying next pass, no alerting, no dead-lettering.
- A banned/suspended/locked account simply fails login forever and retries forever; the account stays in the queue.
- No input validation at the API boundary: `json.loads(request.data)` then direct key access — a missing `universe`/`username`/`password` raises `KeyError`, and Flask returns a 500. No auth on `/start`/`/remove`/`/active`; anyone who can reach port 80 can add/remove arbitrary accounts.
- The API's `stop` route is actually `remove`; `bot.stop()` (clear all) exists but is never wired to a route.
- `datetime` comparison: `celestial_queue.shipyard < datetime.datetime.now()` — depends on pyogame's returned datetime being comparable (naive vs aware mismatch would raise or miscompare; not guarded).

## Anti-detection & authenticity

- **Effectively none.** Proxy support is the only anti-detection mechanism, and it is disabled by default.
- `src/proxys.py` is also broken even if enabled:
  ```py
  proxy = requests.get(url='').text.split('\r\n')
  ```
  `requests.get('')` raises `MissingSchema`, so `random_proxy()` can never return a proxy.
- No user-agent spoofing, no session persistence, no login-time jitter beyond the 5–60 s inter-account sleep, no "human hours" model, no variation in build order, no occasional idleness. It re-logs in every ~5–60 s around the clock — a trivially detectable pattern.

## Discrete mechanisms

Build-priority table from `queue()` in `src/buildings.py` (order is significant; first satisfied condition wins):

- M01 — solar_plant — `resources.energy < 0 AND mines.solar_plant.level < 30 AND is_possible`
- M02 — crystal_mine — `level < 25 AND is_possible`
- M03 — metal_mine — `level < 25 AND is_possible`
- M04 — deuterium_mine — `level < 15 AND is_possible`
- M05 — fusion_plant — `resources.energy < 0 AND level < 15 AND is_possible`
- M06 — solar_satellite(1) — `resources.energy < 0 AND ships.solarSatellite.is_possible AND amount < 100`
- M07 — robotics_factory — `level <= 15 AND is_possible`
- M08 — shipyard — `level < 14 AND is_possible`
- M09 — research_laboratory — `level < 14 AND is_possible`
- M10 — nanite_factory — `level < 6 AND is_possible`
- M11 — missile_silo — `level < 9 AND is_possible`
- M12 — espionage — `research.espionage.is_possible AND level < 8`
- M13 — computer — `research.computer.is_possible AND research.espionage.level < 10` (**bug:** gates on `espionage`, not `computer`)
- M14 — weapons — `is_possible AND level < 3`
- M15 — shielding — `is_possible AND level < 6`
- M16 — armor — `is_possible AND level < 2`
- M17 — energy — `is_possible AND level < 12`
- M18 — hyperspace — `is_possible AND level < 8`
- M19 — combustion_drive — `is_possible AND level < 6`
- M20 — impulse_drive — `is_possible AND level < 17`
- M21 — hyperspace_drive — `is_possible AND level < 15`
- M22 — laser — `is_possible AND level < 12`
- M23 — ion — `is_possible AND level < 5`
- M24 — plasma — `is_possible AND level < 7`
- M25 — research_network — `is_possible AND level < empire.slot_celestial().total`
- M26 — astrophysics — `is_possible` only (**no level cap** → researches forever once reachable)
- M27 — graviton — `is_possible AND level < 1`
- M28 — colonyShip() — `ships.colonyShip.amount == 0 AND is_possible AND 0 < empire.slot_celestial().free`
- M29 — metal_storage — `level < 14 AND is_possible`
- M30 — crystal_storage — `level < 10 AND is_possible`
- M31 — deuterium_storage — `level < 11 AND is_possible`
- M32 — shield_dome_small() — `is_possible` only
- M33 — shield_dome_large() — `is_possible` only
- M34 — rocket_launcher(100) — `is_possible AND 4 < factory.shipyard.level AND celestial_queue.shipyard < now AND amount < 1000`
- M35 — laser_cannon_light(100) — `is_possible AND celestial_queue.shipyard < now AND 4 < shipyard.level AND amount < 1000`
- M36 — laser_cannon_heavy(100) — `is_possible AND celestial_queue.shipyard < now AND 4 < shipyard.level AND amount < 400`
- M37 — gauss_cannon(10) — `is_possible AND celestial_queue.shipyard < now AND 8 < shipyard.level AND amount < 100`
- M38 — ion_cannon(10) — `is_possible AND celestial_queue.shipyard < now AND 8 < shipyard.level AND amount < 100`
- M39 — plasma_cannon(5) — `is_possible AND celestial_queue.shipyard < now AND 10 < shipyard.level AND defences.laser_cannon_heavy.amount < 40` (**bug:** gates on `laser_cannon_heavy`, not `plasma_cannon`)
- M40 — missile_interceptor(5) — `is_possible AND celestial_queue.shipyard < now AND 1 < shipyard.level AND 0 < missile_silo.level AND amount < 60`

Runtime mechanisms (`src/bot.py`, `src/API.py`):

- M41 — scheduler pass — `while True`: for each queued account run `bot()` then `sleep(randint(5,60))`; after list, `sleep(5)`
- M42 — one-action-per-planet — `for ID in ids: for order in queue(ID, empire): if order.condition: empire.build(...); break`
- M43 — add — `add()` refuses duplicates (`active()` check) and appends `Credentials`
- M44 — remove — `remove()` deletes by `(universe, username)` match
- M45 — active — `active()` returns whether `(universe, username)` is in `bot_queue`
- M46 — session lifecycle — new `OGame` login per pass, `del empire` at end

## Notable concerns

- **Gate 1 (hardcoded AI):** this is the textbook counter-example. Every object id, level cap, and threshold is a literal in module code (`constants.buildings.solar_plant`, `mines.crystal_mine.level < 25`, `rocket_launcher(100)`, etc.). Reachability of every capability is decided by a static list the module owns. Adding a host object requires a module edit. Fails gate 1 outright.
- **Gate 2 (simplicity):** actually passes — one function, one loop, one local class. No over-engineering. (Minor: `redis` dependency declared but unused; `stop()` defined but never exposed.)
- **Gate 3 (human-like):** fails. 24/7 fixed-cadence re-login; deterministic fixed build order with no variation; no fleet save, no attack response, no idle periods, no mining-while-short reasoning; it will research `astrophysics` indefinitely (no cap) and build only colony ships for fleet, which it never sends to colonize.
- **Critical logic bug — infinite Computer Technology loop:** M13's condition checks `research.espionage.level < 10`, but M12 caps espionage at `< 8`, so that predicate is permanently true. Once `computer` is possible (lab ≥ 1), the first-satisfied-order scan always stops at M13, and the bot researches Computer Technology forever, never reaching weapons/shielding/armor/fleet/defences. This is the kind of bug "grep every caller" would catch — the sibling condition at M39 has the same copy-paste class of error.
- **Second logic bug — plasma cap:** M39 caps on `laser_cannon_heavy.amount < 40` instead of `plasma_cannon` count, so plasma cannons can be built to that cap regardless of actual plasma count, and the intended plasma cap is never enforced.
- **No persistence, no auth, plaintext credentials, credentials logged every pass.**
- **Stale/conflicting dependency:** `requirements.txt` pins `ogame~=8.4.0.22` while `bot.dockerfile` then installs pyogame `@develop`, overriding the pin.

## Confidence

High. The repository is tiny (4 Python source files, ~400 lines total), and I read the full text of `bot.py`, `buildings.py`, `API.py`, `main.py`, `proxys.py`, `requirements.txt`, `docker-compose.yml`, `bot.dockerfile`, `swarm.sh`, `.gitignore`, the README, and the LICENSE. All 40 build orders and their exact thresholds were reconstructed from the fetched `buildings.py` source. The only uncertainty is precise line-number placement within `buildings.py` (the fetch did not preserve line numbers), but the ordering and every condition were captured verbatim.