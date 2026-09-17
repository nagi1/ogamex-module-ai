## Overview

`r4fek/ogame-bot` is a small, archived (Mar 2018), Python **2** script bot for the **Polish** OGame server (`pl.ogame.gameforge.com`). It is a single-process, single-loop screen-scraper built on `mechanize` + `BeautifulSoup` (BeautifulSoup 3). It logs in, scrapes planets/resources/buildings/fleet, decides what to build, transports resources, launches expeditions, farms a hardcoded list of inactive coordinates, and reacts to incoming hostile attacks (defense, SMS, message to attacker, fleet save). It is ~750 lines total across 8 source files.

- Files: `bot.py` (main, ~748 lines), `planet.py` (~120), `transport_manager.py` (~188), `sim.py` (~82), `attack.py` (~45), `config.py` (~57), `smsapigateway.py` (~22), `utils.py` (~18), plus `config.ini`, `ogame.ini`, `requirements.txt`.
- Dependencies (`requirements.txt`): `mechanize`, `BeautifulSoup`, `watchdog`. Python 2 only (`xrange`, `print` statements, `ConfigParser`, `urllib2`, `file()`).
- License: BSD-2-Clause. No tests, no CI, no releases. 44 stars, 29 forks.

## Architecture & entry points

- Entry: `bot.py` `if __name__ == "__main__":` reads `options['credentials']` and constructs `Bot(username, password, uni)`, then calls `bot.start()`.
- `Bot.start()` (`bot.py`): writes PID to `bot.pid`, then an infinite `while True` loop: `login()` → `handle_planets()` → (if no active attacks) `send_expedition()` + `farm()` + `farm()`, else `handle_attacks()` → `sleep()`. Any exception is logged and swallowed; the loop never exits (the `stop()`/`return` calls are commented out).
- `Bot.__init__` builds `self.MAIN_URL = 'https://s{uni}-pl.ogame.gameforge.com/game/index.php'`, `self.PAGES` dict (overview, resources, station, research, shipyard, defense, fleet, galaxy, galaxyContent, eventList), and instantiates `TransportManager()` and `Sim()`.
- Browser: `mechanize.Browser()` with `set_handle_robots(False)`, static `HEADERS` (Chrome/24.0.1295.0 UA).
- `config.py`: `Options` singleton (`options`) wraps `ConfigParser.RawConfigParser`, reads `config.ini`, and uses `watchdog` to hot-reload on file change (`on_any_event` → `reload_config`).
- Deployment: `ogame.ini` is a supervisord program config (`command=/root/.virtualenvs/ogame/bin/python bot.py`, `autostart=true`, `autorestart=true`, `redirect_stderr=true`).
- `utils.py` defines `login_required` (decorator) and `load_sms_gateway` — both appear **unused** elsewhere.

## Scheduling & loop model

- Single thread; one iteration = login + full scan + actions + sleep. No scheduler, no queue, no cron.
- `Bot.sleep()` (`bot.py`): `sleep_time = randint(0, int(seed)) + int(check_interval)`; with defaults `seed=300`, `check_interval=400` → uniform 400–700 s. **If `self.active_attacks` is non-empty, `sleep_time = 60`** (poll every 60 s during an attack).
- Per iteration (no attacks): `send_expedition()` once, then `farm()` **twice** back-to-back (two farm attacks per cycle).
- During an attack: loop switches to `handle_attacks()` only; normal economy actions are skipped.
- Socket timeout: `socket.setdefaulttimeout(float(options['general']['timeout']))` = 10 s.
- No rate limiting except `time.sleep(2)` per system in `find_inactive_nearby` and `time.sleep(5)` per planet in `find_inactives` (both in dead-code paths).

## Decision engine & algorithms

The "AI" is a handful of deterministic heuristics; no planning, no learning, no simulation of combat.

**Building choice** — `Planet.get_mine_to_upgrade()` (`planet.py`):
```py
proposed_levels = [
    b['metalMine']['level'],
    b['metalMine']['level'] - levels_diff[0],
    b['metalMine']['level'] - levels_diff[0] - levels_diff[1]
]
```
with default `levels_diff = 2,3` (so target = metal, metal−2, metal−5; values clamped to ≥0). If crystal/deuterium are already ≥ target, bump metal target by 1. Then pick the first mine (`metalMine`, `crystalMine`, `deuteriumMine`) with `can_build` and `level < proposed_levels[i]`. If that mine lacks energy, or energy ≤ 0, fall back to `solarPlant` if `can_build`, else `fusionPlant` if its level `< max_fusion_plant_level` (default `0`, so fusion is effectively **never** built). `min_energy_level` (default 10) is read but **never used** — dead config.

**Cross-planet build target** — `TransportManager.find_planet_to_upgrade()` (`transport_manager.py`): for each building type compute `diff = max(level) - min(level)` across planets and pick the building with the largest diff, targeting the planet with the minimum level of that building.

**Cost model** — `Sim` (`sim.py`):
```py
metal   = int(FIRST_COST[what]['metal']   * FACTORS[what] ** (level - 1))
...
_energy = math.floor(ENERGY_COST_FACTORS[what] * level * 1.1 ** level) + 1
```
- `FIRST_COST`: metalMine 60/15/0, crystalMine 48/24/0, deuteriumMine 225/75/0, solarPlant 75/30/0, fusionPlant 900/360/180.
- `FACTORS`: metal 1.5, crystal 1.6, deuterium 1.5, solar 1.5, fusion 1.8.
- `ENERGY_COST_FACTORS`: metalMine 10, crystalMine 10, deuteriumMine 20.
- `upgrade_energy_cost(what, to_level) = _calc_energy_cost(to_level) - _calc_energy_cost(to_level-1)`; unknown key → `-10000000`.
- Transport capacity: `lt*5000 + dt*25000`.

**Transport** — `Bot.transport_resources()` calls `TransportManager.find_dest_planet()`, which returns at most **one** task (`res.append(task)` then `return res` inside the loop). `calc_resources_needed` = `max(0, cost(level+1) − available)` per resource. `enough_resources_to_build` sums all planets' resources. Threshold: skip a donor task if `metal + crystal < 50000`.

## Data model & persistence

- **No database, no persistence.** All state is in-memory Python objects; the only disk artifacts are `bot.log` (rotating, `maxBytes=100000`, `backupCount=5`) and `bot.pid`.
- `Planet` (`planet.py`): `id`, `name`, `coords`, `url`, `mother` flag, `in_construction_mode`, `mines` tuple, `resources` dict (metal/crystal/deuterium/energy), `buildings` dict (level / buildUrl / can_build / sufficient_energy), `ships` dict (13 ship codes → counts).
- `Moon(Planet)` subclass: `get_mine_to_upgrade()` returns `(None, None)`, `is_moon()` True.
- `Attack` (`attack.py`): `planet`, `id`, `arrivalTime`, `coordsOrigin`, `destCoords`, `detailsFleet`, `player`, `message_url`, plus flags `noticed_time`, `message_sent`, `sms_sent`.
- `Bot` state: `planets`, `moons`, `active_attacks`, `server_time`/`local_time`/`time_diff`, `farm_no`, `fleet_slots`/`active_fleets` (assigned but never meaningfully used), `transport_manager`, `sim`.

## Config surface

`config.ini` sections (all read through `options['section']`):
- `[credentials]`: `uni=127`, `username`, `password` (placeholders).
- `[general]`: `seed=300`, `check_interval=400`, `timeout=10`.
- `[building]`: `min_energy_level=10` (unused), `max_fusion_plant_level=0`, `levels_diff=2,3`.
- `[attack]`: `max_ships=25`, `messages=:),Pozdro,No i po co te nerwy?,...` (comma-separated), `message_topic='hej!'`.
- `[fleet]`: empty.
- `[sms]`: `send_sms=1`.
- `[farming]`: `farms=<hundreds of "g:s:p" coords>`, `ships_kind=dt`, `ships_number=2`.
- `[expedition]`: `planets=1:9:4 1:50:7 1:277:8 2:9:7 2:371:9`, `ships_number=100`, `ships_kind=dt`.
- `config.py` `Options.valid` property references `self._is_config_valid`, but the code sets `self._config_valid` — the `valid` property is broken (would raise `AttributeError`).

## Edge cases & failure handling

- Login failure → log error, loop continues (`sleep()` then retry). `Bot.login()` returns False if initial open fails or final URL check fails.
- All top-level exceptions in `start()` are logged and swallowed; the loop keeps running.
- `check_attacks`: missing `attack_alert` → exception logged, return; `noAttack` class → clear `active_attacks`; per-row parse failure → SMS `'ATTACKEROR'`.
- `send_fleet`: same-origin/destination coords → refuse; missing `shipsChosen` form → "No available ships"; if mission in (`attack`,`expedition`) and available < requested → return False without sending.
- `fetch_planets`: whole block in `try/except`; on failure planets stay as before but `check_attacks` only runs on success (`else` branch).
- `update_planet_info`: resource parse failure → exception logged; moon planets return early (no buildings). `sufficient_energy` computed even for solar/fusion/satellite (energy producers get `sufficient_energy=True` by default).
- `get_mother()`: `return p[0] if self.planets else None` references undefined `p` if no mother found but planets exist (latent `NameError`; practically unreachable since index 0 is always mother).
- `check_attacks` hardcodes `is_moon = False  # TODO!` — moon attacks are never matched to a moon.

## Anti-detection & authenticity

**None to speak of.** Specifics:
- Static, 12-year-old User-Agent: `Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.15 (KHTML, like Gecko) Chrome/24.0.1295.0 Safari/537.15`.
- Only randomization: `sleep()` jitter (400–700 s) and `farm_no` initial random start index, expedition planet shuffle, random taunt message.
- No proxies, no IP rotation, no request throttling on galaxy scans, no login cadence variation (re-logs in full every iteration), no cursor/click simulation.
- `send_sms` / `send_message` are gated behind `options['sms']['send_sms']` only; no human-confirmation step.

## Discrete mechanisms

- M01 — Login: open `MAIN_URL`; logged in iff no redirect and `attack_alert` present; else submit `loginForm` with `uni=['s{uni}-pl.ogame.gameforge.com']`, `login`, `pass` (`bot.py:login`).
- M02 — Server clock sync: regex `var serverTime=new Date\((.*)\);` → `time_diff = server_time − local_time` (`bot.py:calc_time`).
- M03 — Planet discovery: iterate `a.planetlink`; first planet flagged `mother=True`; moon via `a.moonlink` + `cp=` id (`bot.py:fetch_planets`).
- M04 — Resource scrape: ids `resources_metal|crystal|deuterium|energy`, thousands dots stripped (`bot.py:update_planet_info`).
- M05 — Building scrape: zip buildings tuple with `#building li`; `can_build = 'on' in class`; build URL from `sendBuildRequest('<url>', null, 1)`; `sufficient_energy = energy − upgrade_energy_cost(building, level+1) > 0` (`bot.py:update_planet_info`).
- M06 — Fleet scrape: `soup.find(id='button'+shipId)` for each of 13 ship codes (`bot.py:update_planet_fleet`).
- M07 — Build trigger: if not `in_construction_mode`, open `get_mine_to_upgrade()` URL once and mark planet constructing (`bot.py:update_planet_info`).
- M08 — Mine ladder: target levels `[metal, metal−2, metal−5]` (clamped ≥0); if crystal & deuterium already at target, metal target +1; build first mine with `can_build` and below target (`planet.py:get_mine_to_upgrade`).
- M09 — Energy fallback: energy ≤ 0 or no sufficient-energy mine → solarPlant (if `can_build`), else fusionPlant if `level < max_fusion_plant_level` (`planet.py:get_mine_to_upgrade`).
- M10 — Building cost: `int(FIRST_COST[res] * FACTOR^(level−1))`; factors metal 1.5 / crystal 1.6 / deut 1.5 / solar 1.5 / fusion 1.8 (`sim.py:_calc_building_cost`).
- M11 — Energy cost: `floor(FACTOR * level * 1.1^level) + 1`, FACTOR 10/10/20 (`sim.py:_calc_energy_cost`).
- M12 — Transport capacity: `lt*5000 + dt*25000` (`sim.py:get_total_transport_capacity`).
- M13 — Build-target selection: solar upgrade first (any planet `energy < 0`), else largest mine-level difference across planets, worst planet wins (`transport_manager.py:find_solar_to_upgrade`, `find_planet_to_upgrade`).
- M14 — Transport need: `max(0, cost(next level) − planet stock)` per resource (`transport_manager.py:calc_resources_needed`).
- M15 — Transport feasibility: Σ all planets' stock ≥ need − already_sent per resource (`transport_manager.py:enough_resources_to_build`).
- M16 — Donor skip threshold: skip task if `metal + crystal < 50000` (`transport_manager.py:process_dest_planet`).
- M17 — Load ships for transport: greedily add `dt` (25000) then `lt` (5000) until capacity > total resources (`planet.py:get_fleet_for_resources`).
- M18 — Defense spam: for type ids `406,404,403,402,401` (plasma, gauss, heavy laser, light laser, rocket), post `menge=100`, `modus=1` (`bot.py:build_defense`).
- M19 — Attack detect: `#attack_alert`; class `noAttack` → clear list; else scrape `eventList` rows with hostile `countDown` (`bot.py:check_attacks`).
- M20 — Danger rule: attack is dangerous iff `detailsFleet > max_ships` (default 25) (`attack.py:is_dangerous`).
- M21 — Attack response: build defense (if not moon) → SMS → attacker message (random from `messages`) → fleet save (`bot.py:handle_attacks`).
- M22 — Fleet save: all ships, mission `station`, `speed=10` (maps to `'1'` = 10%), resources = `planet.resources[res] + 500` each, to `get_safe_planet` (`bot.py:fleet_save`).
- M23 — Safe planet: first planet not under attack and ≠ origin, else `planets[0]` (`bot.py:get_safe_planet`).
- M24 — Debris collect (dead code): recyclers to own coords, mission `collect`, target `debris` (`bot.py:collect_debris`; never called in `start()`).
- M25 — Expedition: shuffle configured planets, first 3, send to `<g>:<s>:16` with configured ship kind/count (default 100 `dt`) (`bot.py:send_expedition`).
- M26 — Farm: cycle `farms` via `farm_no`; verify target `inactive`; send configured ships (default 2 `dt`) from closest planet (`bot.py:farm`).
- M27 — Farm advance: `farm_no += 1` inside `send_fleet` on `mission == 'attack'` (`bot.py:send_fleet`).
- M28 — Inactive scan (dead code): radius 15 systems `[max(1,s−r), min(499,s+r))`; keep only rows with `inactive` class **and** debris cell class `js_no_action`; keep rank in [900, 4000]; `sleep(2)` per system (`bot.py:find_inactive_nearby`, `get_nearby_systems`).
- M29 — Player status: galaxyContent row `position−1`; `inactive = 'inactive' in playername class` (`bot.py:get_player_status`).
- M30 — Distance: `|Δg|*100 + |Δs|*10 + |Δp|`; unparseable → 100000 (`planet.py:get_distance`).
- M31 — Sleep cadence: `randint(0, seed) + check_interval` (default 400–700 s); `60` s while attacks active (`bot.py:sleep`).
- M32 — Form constants: `SHIPS` (lm=204…ss=210), `MISSIONS` (attack=1, transport=3, station=4, collect=8, expedition=15), `TARGETS` (planet=1, debris=2, moon=3), `SPEEDS` (100→'10' … 10→'1') (`bot.py` class attrs).
- M33 — Config hot-reload: watchdog observer on `.`, reload on `config.ini` change (`config.py`).
- M34 — SMS: `smsapi.pl/sms.do`, `eco=1`, hardcoded MD5 password and username/phone placeholders (`smsapigateway.py`).
- M35 — Process supervision: supervisord `autorestart=true`, `redirect_stderr=true`, PID file `bot.pid` written/unlinked (`ogame.ini`, `bot.py:start/stop`).

## Notable concerns

- **Gate 1 (hardcoded AI) violations — pervasive.** `Sim.FIRST_COST`/`FACTORS`/`ENERGY_COST_FACTORS` encode building prices and energy costs; `SHIPS`, `MISSIONS`, `TARGETS`, `SPEEDS`, and defense type ids `406/404/403/402/401` hardcode page-form ids; transport capacities `5000`/`25000` are hardcoded; the entire farm list and expedition planets are hardcoded coordinates in `config.ini`; `get_mine_to_upgrade` assumes exactly three mines `(metalMine, crystalMine, deuteriumMine)`. This is the opposite of OGameX Gate 1.
- **Gate 3 (human behavior) violations — many.** Two `farm()` calls per loop cycle; 3 expeditions every cycle with 100 large transports; fleet save always at 10% speed with `resources+500` (asks for more than held); building all five defense tiers to 100 on every dangerous attack; re-login + full-page scrape every iteration; 60-second polling during attacks; galaxy scans without throttling; 12-year-old browser UA.
- **Gate 2 (over-engineering) — mostly the opposite: dead code and broken code**, not over-engineering: `find_inactives`/`find_inactive_nearby`/`collect_debris` are unreachable from `start()`; `if True or not self.transport_resources()` disables the transport branch by accident; `Options.valid` references a nonexistent attribute; `Attack._parse_time` is unused; `min_energy_level` config is unused; `utils.login_required`/`load_sms_gateway` are unused; `get_closest_planet` defines an unused nested `min_dist`.
- **Obsolete**: Python 2, BeautifulSoup 3, `mechanize`, 2014-era OGame HTML (ids like `galaxytable`, `resources_metal`, JS `sendBuildRequest`) — almost certainly incompatible with the current OGame client. Repo archived since 2018.
- **No tests, no type checks, no config validation beyond section presence.**

## Confidence

- **High** for: file inventory, the main loop, formulas/thresholds (cost, energy, transport capacity, sleep, expedition, farm, defense ids, fleet save), and the hardcoded-constant surface — all verified against the actual source and `config.ini`.
- **Medium** for: exact runtime behavior of dead paths (`find_inactives`, `collect_debris`) and the `find_inactive_nearby` debris condition (`js_no_action`) — code was read verbatim but these paths are never executed, and the `js_no_action` semantics (whether it means "no debris" or "no action available") is inferred, not confirmed from OGame's UI.
- **Low** for: whether any of this still works against today's OGame (very likely not).