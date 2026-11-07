## Overview

`jaesivsm/pyogame` is a small, **single-purpose, single-shot Python CLI bot** for the browser game OGame. Its README tagline is *"A bot that plays the dull parts of ogame for you."* It automates only the **civil/economy** side: resource repatriation, building construction, research, espionage and debris recycling. It does **not** attack, defend, fleet-save, colonize, or expedite.

- Language: Python (old style — Selenium 2.x-era API `find_element_by_id`, `except Exception:` without `as`, `#!/usr/bin/python`).
- Dependencies (`requierements.txt`, misspelled): `selenium`, `python-dateutil`, `lxml`.
- Repo is essentially abandoned: last commit `c79fd96` ("kinda fixing stuff") ~9 years ago, 1 contributor, 4 stars, no releases, no tests, no CI.
- Browser automation via **Selenium Firefox driver** + `lxml.html` DOM scraping.

## Architecture & entry points

Entry point: `ogame.py`. Flow of `main(ctx, option)` (`ogame.py:11-38`):

```py
ctx.interface.login()
ctx.interface.update_empire_state(ctx.empire)
ctx.interface.crawl(ctx.empire)
if option.rapatriate:   routines.civil.rapatriate(...)
if option.construct:    # in_place_empire_upgrade + resources_reception_and_construction + plan_construction
if option.probes:       routines.guerrilla.check_neighborhood(...)
if option.recycle:      routines.guerrilla.check_neighborhood(...)
if not (any flag):      # default full civil pass
ctx.interface.logout()
```

Package layout:

- `pyogame/tools/context.py` — `Context` (lazy `Interface`, `conf`, `empire`) + `get_context()` per-username singleton (`_CONTEXTES`).
- `pyogame/interface.py` — `Interface`: Selenium wrapper (login, page navigation, DOM scraping, fleet send).
- `pyogame/planet.py` / `planet_collection.py` — `Planet` and `PlanetCollection` (empire) models + build-order heuristics.
- `pyogame/constructions.py`, `technologies.py`, `ships.py` — hardcoded object catalogs + registry builders.
- `pyogame/fleet.py` — `Fleet`, `FlyingFleet`, `Missions`.
- `pyogame/routines/civil.py`, `common.py`, `guerrilla.py` — the decision routines.
- `pyogame/abstract/` — `ogame_objs.py` (cost/energy formulas), `collections.py` (generic filtered collections), `planner.py` (`PlannerMixin` build-plan registry).
- `pyogame/tools/` — `resources.py`, `const.py`, `common.py`, `utils.py`, `ui.py`.

## Scheduling & loop model

**There is no scheduler, daemon, or loop.** Each run is one login → one full pass of state update + actions → logout. Re-running is manual (cron or a shell loop, neither provided).

- `-n/--do-nothing` (`ogame.py:41-47`): logs in, updates state, then `time.sleep(600)` (10 minutes of inactivity), catching `KeyboardInterrupt`.
- The only sleeps are `DEFAULT_WAIT_TIME = 10` (Selenium `implicitly_wait`, `interface.py:21-22`) and `DEFAULT_JS_SLEEP = 1` (after login and galaxy show button).
- Fleet timing is passive: `FlyingFleet` stores `arrival_time`/`return_time` parsed from the fleet page (`interface.get_date`), and `Missions.clean()` prunes fleets whose `return_time < now` (`fleet.py:111-122`). Nothing waits on these times to act.
- Actions fire immediately and deterministically, back-to-back, whenever the local model says resources are affordable.

## Decision engine & algorithms

The build decision is `Planet.to_construct` (`planet.py:91-134`), a hardcoded priority cascade over named building types:

```py
trigger_crys_lvl = metal_mine.level - CRYS_TO_MET_OFFSET   # 3
trigger_deut_lvl = metal_mine.level - DEUT_TO_MET_OFFSET   # 7
trigger_rob_lvl  = int(solar_plant.level / ROB_TO_SOL_RATIO)  # 2.4
cnstr = metal_mine
if crystal_mine.level < trigger_crys_lvl: cnstr = crystal_mine
elif deuterium_synthetizer.level < trigger_deut_lvl: cnstr = deuterium_synthetizer
if cnstr.cost.energy * .95 > resources.energy: cnstr = solar_plant
if robot_factory.level < trigger_rob_lvl:
    cnstr = robot_factory
    if cnstr.level >= 10: cnstr = nanite_factory
```

Then tank logic: on the **capital**, build metal/crystal/deut tank when `tank.capacity < cnstr.cost.metal|crystal|deuterium` or the tank is full. On **colonies**, build a tank when `mine.level / (1 + tank.level) > MET_TANK_RATIO (7)` or `CRYS_TANK_RATIO (9)` (`planet.py:121-127`). The chosen building is yielded through `requirements_for(cnstr.copy(level=cnstr.level+1))`.

Prerequisite/leveling recursion `Planet.requirements_for` (`planet.py:79-89`): for each unmet requirement, recurse; if target level > current+1, recurse on `level-1` (one level at a time); else yield the building.

`PlanetCollection.cheapest` (`planet_collection.py:87-100`) scans all idle planets' `to_construct` and keeps the item with the smallest `cost.movable.total`, then returns `common.cheapest(planet.requirements_for(building))` — i.e. the cheapest overall next build across the empire. (`construct_on_capital` excludes the capital when there are colonies.)

The main routines (`routines/civil.py`):

- `in_place_empire_upgrade` — if not researching, build planned **technologies** on the capital when `plan.cost <= capital.resources`; then per idle planet, build planned constructs if affordable, else fall back to `planet.to_construct` when `planet.resources >= construct.cost`.
- `plan_construction` — while an eligible idle planet exists, ship the **exact construction cost** from the capital via `transport(resources=cost)`; record `planet.waiting_for[travel_id] = construct.name`. Guards: `source.resources.movable < cost.movable` and `source.fleet.capacity < cost.movable.total` → stop.
- `resources_reception_and_construction` — when all awaited transports for a construct have arrived (`waited_constr == waited_travel`), launch the build.
- `rapatriate` — for each non-capital planet, skip if `float(resources.total)/fleet.capacity < 2./3` **and** no tank is full; otherwise `transport(..., all_ships=True)` everything to the capital.

`guerrilla.check_neighborhood` (`guerrilla.py`) — scan systems `distance` in `range(*area)` (default `[0, 20]`), factor `(1, -1)`, `0 <= system <= 500`; spy on `inactive and not (vacation or noob)` planets; recycle when `debris_content.total > 20000`.

## Data model & persistence

- Persistence is a single JSON file `cache.json` in the working directory (`CACHE_PATH_TEMPLATE = 'cache.json'`, `tools/context.py:14`), keyed by username. `Context.dump()` writes the whole empire: planets (resources, fleet, constructs, plans, `waiting_for`, `idle`, `capital`), `technologies`, `plans`, `missions`, `is_researching`. `datetime`s serialized via `o.isoformat()`.
- `Resources` (`tools/resources.py`): `metal`, `crystal`, `deuterium`, `energy`. `total = metal+crystal+deuterium` (excludes energy); `movable` drops energy. All rich comparisons (`__eq__/__gt__/__ge__/__lt__/__le__`) compare **only movable** resources, ignoring energy. `__len__` sums all four (inconsistent with `total`).
- Catalog registries are built by class reflection: `Constructions.registry` (from `ResourcesBuilding.__subclasses__() + StationBuilding.__subclasses__() + Tank.__subclasses__()`), `Technologies.registry`, `Ships.registry` (only `CivilShips.__subclasses__()`).
- `Planet.key = coords_to_key(coords)` → `"galaxy:system:position"`.

## Config surface

- `conf.json` at `~/conf.json` (`CONF_PATH = os.path.abspath(os.path.expanduser('conf.json'))`, `tools/const.py`). Per-account keys (`conf.json.example`):

```json
{"account_key": {"user": "username", "password": "password",
                 "univers": "univers nam", "capital": [5, 57, 7], "lang": "lang"}}
```

- Server URL is derived from language: `"http://%s.ogame.gameforge.com/" % lang` (`interface.py:30`).
- CLI (`tools/utils.py`): positional `user`; flags `-d/--debug`, `-n/--do-nothing`, `-q/--quiet`, `-v/--verbose`, `-l/--log`; actions `-r/--rapatriate`, `-c/--construct`, `-p/--probes`, `-y/--recycle`, `--area-start` (default 0), `--area-end` (default 1), `-b/--build <name>-<lvl>[-<planet_key>]`, `-t/--tech`, `-i/--idles`, `--ui <view>`.

## Edge cases & failure handling

Failure handling is minimal — asserts and a handful of `try/except` blocks (login ad close, `update_planet_resources`, `update_technologies` temp-upgrade parse, cache corruption). No retries, no re-login, no captcha/maintenance/ban handling.

Confirmed bugs (from source reading):

- `ogame.py:25-32` calls `routines.guerrilla.check_neighborhood([area_start, area_end], MISSION)` with **two positional args**, but the signature is `check_neighborhood(interface, empire, area=None, mission=BOTH, planet=None)` (`guerrilla.py:11`). `interface` receives the list, `empire` receives `'SPY'`/`'RECYCLE'`, and `planet` is `None` → `planet.coords` raises `AttributeError`. **`-p` and `-y` are broken.**
- `tools/common.py:cheapest` sorts `reverse=True` and returns `[0]` → returns the **most expensive** item, not cheapest. Used by `PlanetCollection.cheapest`, so it picks the most expensive prerequisite/build among the chosen planet's requirements.
- `CivilShips.xpath` (`ogame_objs.py`): `"...li[%d]/div/a" % self.position + 1` — `%` binds before `+`, so it's `str + int` → `TypeError`. (Unused; fleet parsing in `interface.update_planet_fleet` bypasses it.)
- `--idles` is parsed (`utils.py`) but **never read** anywhere in `ogame.py`; setting it only suppresses the default full pass, then the program logs out → effectively a no-op.
- `Tank.capacity` is a hardcoded level→value dict ×1000 (`ogame_objs.py`) with a comment `"fugly, couldn't find the true formula"`; it is off-by-one (index 0 returns level-1's 10,000 capacity).
- `get_date` assumes a 2-digit year: `datetime(year + 2000, ...)` (`interface.py`).
- DOM scraping is position/class-name based (`//span[@class='level']`, `//div[@id='planetList']`, `icon_wrench`) — brittle to skins/localization; `update_buildings` uses an `offset` hack (`0 if page is Pages.station else 1`).
- `Resources.__len__` vs `total` inconsistency (energy included in `__len__`).
- No validation of `conf.json` contents; `capital` coords must exactly match a planet found on the overview page, else `empire.capital` stays `None`.

## Anti-detection & authenticity

**none.** There is no stealth, no human-like pacing, no randomization, no reaction latency, no uptime shaping. It uses plain Selenium Firefox with a 10 s implicit wait and a 1 s JS sleep, then acts as fast as the browser allows. All actions are deterministic and fire the instant the scraped model says resources are sufficient — clearly non-human behavior.

## Discrete mechanisms

- M01 — **Build cost formula** — `base_<res>_cost * power^(level-1)` (`AbstractConstruct._cost`, `pyogame/abstract/ogame_objs.py`). `power` = 1.5 (metal/deut mine, solar), 1.6 (crystal mine), 2 (station buildings, tanks, tech).
- M02 — **Energy cost of a build** — `_energy(level) = energy_factor*(level-1)*1.1^level`; build energy = `_energy(level+1) - _energy(level)` (`ogame_objs.py`). `energy_factor`: mines 10 (crystal/deut 10/20), tanks/station 0.
- M03 — **Tank capacity** — hardcoded dict `{level: k} * 1000` for levels 0–20 (`Tank.capacity`, `ogame_objs.py`).
- M04 — **Crystal mine trigger** — build crystal when `crystal_mine.level < metal_mine.level - CRYS_TO_MET_OFFSET` (3) (`planet.py`).
- M05 — **Deuterium trigger** — build deuterium synthetizer when `deut.level < metal_mine.level - DEUT_TO_MET_OFFSET` (7) (`planet.py`).
- M06 — **Robot factory trigger** — `int(solar_plant.level / ROB_TO_SOL_RATIO)` (2.4) (`planet.py`).
- M07 — **Nanite factory switch** — if robot_factory is the chosen build and `robot_factory.level >= 10`, build `nanite_factory` instead (`planet.py`).
- M08 — **Energy fallback** — if `cnstr.cost.energy * 0.95 > resources.energy`, build `solar_plant` (`planet.py`).
- M09 — **Capital tank rule** — build metal/crystal/deuterium tank when `tank.capacity < cnstr.cost.<res>` or that tank is full (`planet.py`).
- M10 — **Colony tank ratio** — build tank when `mine.level/(1+tank.level) > MET_TANK_RATIO` (7) or `CRYS_TANK_RATIO` (9) (`planet.py`).
- M11 — **One-level-at-a-time** — if `building.level > current.level + 1`, recurse on `building.__class__(level-1)` (`Planet.requirements_for`, `planet.py`).
- M12 — **Prerequisite recursion** — recurse into any unmet requirement (same building type) and yield only leaves when satisfied (`planet.py`; analogous `PlanetCollection.requirements_for` for tech).
- M13 — **Empire-wide cheapest build** — keep min `cost.movable.total` over idle planets' `to_construct`; exclude capital when `construct_on_capital` is false (`PlanetCollection.cheapest`, `planet_collection.py`).
- M14 — **`cheapest()` helper (inverted)** — `sorted(key=cost, reverse=True)[0]` → returns most expensive (`tools/common.py`).
- M15 — **Rapatriate trigger** — transport all ships to capital when `resources.total/fleet.capacity >= 2/3` **or** any tank is full (`civil.rapatriate`).
- M16 — **Remote construction** — ship exact build cost from capital to the chosen idle planet and record `waiting_for[travel_id] = construct.name` (`civil.plan_construction`).
- M17 — **Construct-on-arrival** — build when `waited_constr == waited_travel` for that construct (`civil.resources_reception_and_construction`).
- M18 — **Tech plans** — if not researching and `plan.cost <= capital.resources`, build next tech plan on capital (`civil.in_place_empire_upgrade`).
- M19 — **Spy target filter** — `inactive and not (vacation or noob)` (`guerrilla.check_neighborhood`).
- M20 — **Recycle threshold** — `debris_content.total > 20000` (`guerrilla.py`).
- M21 — **Neighborhood scan** — `range(*area)` (default `[0,20]`), both directions, `0 <= system <= 500` (`guerrilla.py`).
- M22 — **Fleet loading** — greedy largest-capacity ships first; `nb_ships = ceil(amount/single_ship_capacity)` capped at owned quantity (`Fleet.for_moving`, `fleet.py`).
- M23 — **Transport capacity guard** — skip if `source.fleet.capacity < cost.movable.total` (`civil.plan_construction`).
- M24 — **Build-time estimate** — `(metal+crystal) / (2500 * (robot_factory.level+1) * 2^nanite_factory.level)` (`Planet.time_to_construct`, `planet.py`).
- M25 — **Mission CSS map** — attack `#missionButton1`, transport `#missionButton3`, go `#missionButton4`, spy `#missionButton6`, recycle `#missionButton8`, explore `#missionButton15` (`tools/const.py`).
- M26 — **Mission destination buttons** — planet `pbutton`, moon `mbutton`, debris `dbutton` (`tools/const.py`).
- M27 — **Pages enum** — overview, resources, station, research, shipyard, defense, fleet1, movement, galaxy (`tools/const.py`).
- M28 — **Build click selectors** — resources/station `#button{position} a.fastBuild`; tech `.research{research_number} a.fastBuild` (`ogame_objs.py`).
- M29 — **Mission cleanup** — drop `returned` fleets whose `travel_id` is not in `awaited_travel` (`Missions.clean`, `fleet.py`).
- M30 — **Research-state scrape** — `is_researching` from `//div[@id='overviewBottom']/div[@class='content-box-s'][2]//td[@class='first']` (`interface.update_empire_state`).
- M31 — **Debris parse** — metal/crystal read from `js_debris*` element `.debris-content` spans (`interface.browse_galaxy`).
- M32 — **Ship catalog** — only civil ships: `SmallCargo`, `LargeCargo`, `Probes`, `Colony`, `Recycler` with hardcoded `ships_id` (202/203/210/208/209), unit costs, `single_ship_capacity` (5000/25000/5/7500/20000), and build requirements (`ships.py`).
- M33 — **Ship cost** — `unit_cost * quantity` (`AbstractMultiConstruct.cost`, `ogame_objs.py`).
- M34 — **Ship capacity** — `quantity * single_ship_capacity` (`Ships.capacity`, `ogame_objs.py`).
- M35 — **Tech research numbers** — hardcoded per tech (e.g. Energy 113, Laser 120, Ions 121, Hyperspace 114, Plasma 122, Combustion 115, Impulse 117, HyperspaceDrive 118, Espionnage 106, Computer 108, AstroPhysics 124, InterGalacticNetwork 123, Graviton 199) with hardcoded lab/tech prerequisites (`technologies.py`).

## Notable concerns

**Gate 1 (hardcoded static AI) — massively violated.** The entire object universe is hardcoded in module source: building base costs/power/positions (`constructions.py`), tech costs/`research_number`/requirements (`technologies.py`), ship costs/ids/capacities/requirements (`ships.py`), plus DOM selectors, mission buttons and page ids (`interface.py`, `tools/const.py`). The build-decision cascade in `Planet.to_construct` hardcodes *named* buildings (`metal_mine`, `solar_plant`, `nanite_factory`, …) and their precedence. Adding a building/tech/ship requires editing these files and registering a subclass; nothing is read from the host at planning time. This is exactly the anti-pattern the OGameX AI module forbids.

**Gate 2 (over-engineering) — mixed.** The generic `abstract/` layer (`Collection` with runtime filter objects raising `FilterFailed`, `AbstractOgameObjConstruct`, `ConstructCollection`/`MultiConstructCollection`, `PlannerMixin` shared by planet and empire) is more abstraction than this fixed, single-game bot needs, and the class-reflection registry pattern buys nothing over a plain dict for a static universe. On the other hand the actual routines are refreshingly minimal.

**Gate 3 (human-like behavior) — fails broadly.** Deterministic instant build-on-affordability, mass repatriation of all resources to the capital with all ships whenever tanks fill, and sweeping espionage of every inactive target within 20 systems in one pass are not things a human does in a single session. There is no fleet save, no defence, no reaction latency, no uptime pattern, and no social behavior. As an authenticity reference it is essentially a baseline of what *not* to do.

**Other notable concerns**

- No tests, no CI, no packaging; dependency file misspelled (`requierements.txt`).
- Multiple latent bugs (M14 inverted `cheapest`, broken `-p`/`-y`, `--idles` no-op, `CivilShips.xpath` precedence, off-by-one tank table) suggest the code is unmaintained and lightly exercised.
- `conf.json` passwords stored in plaintext.
- Python 2-era / Selenium 2.x code will not run against current Selenium or current OGame HTML without a rewrite.

## Confidence

**High.** All facts above were read directly from the repository source files (`ogame.py`, `pyogame/interface.py`, `planet.py`, `planet_collection.py`, `constructions.py`, `technologies.py`, `ships.py`, `fleet.py`, `pyogame/abstract/*`, `pyogame/tools/*`, `pyogame/routines/*`, `conf.json.example`, `README.rst`, `INSTALL.rst`) via raw fetches and code search. The repo is small and fully read; the only residual uncertainty is the exact level-0 tank-capacity intent (the dict's `0 → 10` entry vs OGame's level-1 capacity of 10,000), which I flagged as a likely off-by-one rather than asserted as certain. No facts about scheduling, anti-detection, or failure handling were inferred from absence — those sections state "none" where nothing exists.