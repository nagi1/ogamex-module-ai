I now have complete coverage of the repository source. Here is the research document.

---

# Cruiser (`kweimann/cruiser`) — exhaustive research

**Repo:** github.com/kweimann/cruiser · **Language:** Python 99.8% (MIT) · **Target:** OGame **v7.3.0** · last commit ~6 years ago · 29 stars, no releases, no CI, **no tests**. Dependencies (`requirements.txt`): `beautifulsoup4==4.9.1`, `requests==2.23.0`, `pyyaml==5.3.1`, `xmltodict==0.12.0`, `simpleaudio==1.0.4`. Python `>=3.7.3`.

## Overview

Cruiser is a **defensive OGame account-sitter**: it logs in, periodically scrapes the account, fleet-saves planets/moons under attack, returns deployment fleets that would be sniped, optionally recalls saved fleets, runs user-defined repeatable **expeditions**, and **harvests expedition debris** with pathfinders (Discoverer only). It does **no building, no research, no mining, no fleet construction, no attacks**. Notifications go to Telegram and/or local `.wav` audio. Two sub-projects: a scraping `ogame` client + engine, and the decision `bot`.

## Architecture & entry points

- Entry: `start_bot.py` — loads `config.yaml` via `bot/configparser.py`, builds `Scheduler()` → `OGame(**client_params)` → `OGameBot(client, scheduler, **bot_params)`, adds listeners, pushes expeditions as zero-delay scheduler events, `client.login()`, `bot.start()`, then `asyncio.run(scheduler.main_loop(bot.handle_work))`.
- `bot/bot.py` — `OGameBot` (the brain), `GameResourceManager` (per-wakeup cache), helpers (`find_hostile_events`, `sort_escape_flights_by_safety`, `get_escape_flights`, `get_fuel_consumption`, `get_cargo`, `find_fleets`, etc.).
- `bot/eventloop.py` — `Event`, `Scheduler` (heapq priority queue).
- `bot/protocol.py` — event dataclasses (`WakeUp`, `SendExpedition`, `CancelExpedition`) and notification dataclasses (`Notify*`).
- `bot/listeners.py` — `Listener`, `TelegramListener`, `AlertListener`, `parse_notification`, `parse_exception`.
- `bot/configparser.py` — YAML → params.
- `ogame/game/client.py` — `OGame` scraper/controller (`login`, `get_overview`, `get_events`, `get_fleet_movement`, `get_galaxy`, `get_fleet_dispatch`, `send_fleet`, `get_research`, `get_shipyard`, `get_resources`), `NotLoggedInError`, `ParseException`, `keep_session` decorator.
- `ogame/game/engine.py` — `Engine`: pure game-math (distance, duration, fuel, cargo, expedition loot).
- `ogame/game/data.py` — static `SHIP_DATA` + constants (hardcoded game table).
- `ogame/game/const.py` — `IdEnum` base + enums (hardcoded object ids).
- `ogame/game/model.py` — frozen dataclasses (`Coordinates`, `Planet`, `FleetEvent`, `FleetMovement`, `Movement`, `Overview`, `Galaxy`, `FleetDispatch`, `Resources`, `Research`, `Shipyard`, `Production`, `GalaxyPosition`).
- `ogame/api/client.py` + `ogame/api/model.py` — `OGameAPI` (official XML API: players, universe, highscore, alliances, localization, `serverData`) → `ServerData`.
- `ogame/util.py` — HTML/number helpers. `ogame/__init__.py` re-exports `OGame`, `OGameAPI`, `Engine`.
- `analytics/universe_heatmap.py` — offline matplotlib/numpy heatmap (`occupancy` or `points`; args `--server --lang --type --max-position`). `analytics/requirements.txt`: `matplotlib==3.2.1`, `numpy==1.18.4`.
- `Dockerfile` (python:3.7.3 + `libasound2-dev`), `docker-build.sh`, `docker-run.sh`, `logging.yaml` (console + rotating `bot.log`, DEBUG).

## Scheduling & loop model

- `Scheduler` (`bot/eventloop.py`): a single heap of `Event(id, time, priority, data, period)`, ordering `(time, priority)`; `push(delay,…)`, `pushabs(abstime,…)`, `cancel(event_id)` (rebuild heap), `main_loop(consume_event, sleep_delay=0.01)` polls `_pop()` (due = `event.time <= time.time()`), **re-pushes periodic events before consuming**, catches per-event exceptions. `consume_event` = `OGameBot.handle_work`.
- `OGameBot.start()` pushes a **periodic** `WakeUp` with `period=random.uniform(self.sleep_min, self.sleep_max)` → default **600–900 s** (10–15 min).
- `handle_work` dispatches on type: `WakeUp` → `_do_work`; `SendExpedition` → register; `CancelExpedition` → mark cancelled.
- `_do_work`: if retrying after exception, ignore events whose id ≠ retry id; otherwise get overview → update engine character class → `_handle_hostile_events` → `_handle_expeditions` → reset `_exc_count`.
- Exception backoff: `self._exc_retry_delays = [5, 10, 15, 30, 60]` seconds; `retry_delay = _exc_retry_delays[min(_exc_count, len-1)]`; pushes retry `WakeUp` and `raise`s.
- Defensive scheduling in `_handle_hostile_events` computes `earliest_wakeup_time = min(t for t in wakeup_times if current_time < t)` and `pushabs`es one `WakeUp`; any stale `_last_scheduled_fs` is cancelled.

## Decision engine & algorithms

All game math lives in `ogame/game/engine.py` (`Engine`), parameterised by `ServerData` (from the host API) + `character_class`.

- **Distance** (`Engine.distance`): cross-galaxy `20000*|Δg|` (donut: `20000*min(Δg, galaxies-Δg)`); cross-system `2700 + 95*|Δs|` (donut variant); cross-position `1000 + 5*|Δp|`; same position different type `5` (planet↔moon); same `0`.
- **Flight duration** (`_flight_duration`): `round((35000 / speed_percentage * sqrt(distance*1000 / ship_speed) + 10) / fleet_speed)`; `flight_duration()` uses `speed_percentage = 10 * fleet_speed` and the **slowest ship** speed.
- **Ship speed** (`ship_speed`): `base_speed + base_speed*DRIVE_FACTOR[drive]*drive_level + class_bonus`; `DRIVE_FACTOR = {combustion:0.1, impulse:0.2, hyperspace:0.3}` (`data.py`). `_drive_technology` picks the best drive whose `min_level` is met, else slowest.
- **Class speed bonus** (`_class_bonus_ship_speed`): General → military ships × `warrior_bonus_faster_combat_ships`, recycler × `warrior_bonus_faster_recyclers`; Collector → small/large cargo × `miner_bonus_faster_trading_ships`.
- **Fuel** (`flight_fuel_consumption`): `base_fuel = int(deuterium_save_factor * drive.fuel_consumption)`; flying term `base_fuel * distance/35000 * (35000/(flight_duration*fleet_speed - 10) * sqrt(10*distance/ship_speed)/10 + 1)**2`; holding term `holding_time * base_fuel / 10`; total `round(flying + holding) + 1`. `_deuterium_save_factor` = `global_deuterium_save_factor` × `GENERAL_FUEL_CONSUMPTION_FACTOR` (**0.75**) only for General.
- **Cargo capacity** (`_ship_capacity`): `base + base*(cargo_hyperspace_tech_percentage/100)*hst_level + class_bonus`; Collector small/large cargo × `miner_bonus_increased_cargo_capacity_for_trading_ships`; probe base = `server_data.probe_cargo`.
- **Expedition loot** (`expedition_find`): `find = int(loot_boost * expedition_points * expedition_factor)`; `expedition_points = min(5 * total_structural_integrity // 1000, max_expedition_points)` where `structural_integrity = metal_cost + crystal_cost` (`ShipData.structural_integrity`); factor clamped to `[EXPEDITION_MIN_FACTOR=10, EXPEDITION_MAX_FACTOR=200]`. `_expedition_loot_boost` = `EXPEDITION_BASE_LOOT` (1), Discoverer → `(1 + explorer_bonus_increased_expedition_outcome) * server_speed`, pathfinder present → ×`EXPEDITION_PATHFINDER_BONUS` (2).
- **max_expedition_points** thresholds by `server_data.top_score`: `<1e5→2500, <1e6→6000, <5e6→9000, <25e6→12000, <50e6→15000, <75e6→18000, <1e8→21000, else→25000`.
- **Resource conversion** (`_expedition_find_as_resource`): metal → find; crystal → `find//2`; deuterium → `find//3`; dark matter → `1800`.
- **Cargo selection on escape** (`get_cargo`): load priority **deuterium, then crystal, then metal**; each `min(available, free_capacity)`.
- **Escape ordering** (`sort_escape_flights_by_safety`): key tuple `(hostile_event_before_arrival if distance==5 else False, distance, dest.type == CoordsType.planet, duration if distance==5 else fuel_consumption)` (ascending = safest; moons preferred, closer preferred, same-position prefers shorter duration else less fuel).

## Data model & persistence

- **No database, no persistence.** All state is in-memory on `OGameBot`: `_last_scheduled_fs`, `_last_seen_hostile_events: Dict[event_id, FleetEvent]`, `_expeditions: Dict[id, Expedition]`, `_saved_fleets: Dict[fleet_id, origin]`. A restart re-derives everything from scraped pages (README: "restarted at any time without causing any problems").
- `GameResourceManager` caches `overview/events/movement/research` per wakeup; `invalidate_cache=True` only after `send_fleet`.
- Scraping model types (`ogame/game/model.py`): frozen dataclasses. `Movement.free_fleet_slots = max - used`; `FleetMovement.flight_duration` property; `holding`/`holding_time`.
- `ogame/api/model.py`: `ServerData` mirrors the official `serverData.xml` (speeds, `top_score`, `character_classes_enabled`, class bonuses, marketplace tax, etc.) — **read at runtime from the host, not hardcoded**.

## Config surface

`config.yaml` (full template):

```yaml
account:  {username, password, universe (name or number), language, country}
listeners:
  telegram: {api_token, chat_id}
  alert:    {wakeup_wav, error_wav}
bot:
  listeners: []            # e.g. [telegram]
  expeditions: []          # expedition ids
  try_recalling_saved_fleet: false
  max_return_flight_time: 600      # s
  harvest_expedition_debris: true  # discoverer-only
  harvest_speed: 10
  sleep_min: 600                   # s
  sleep_max: 900                   # s
  min_time_before_attack_to_act: 120   # s
  max_time_before_attack_to_act: 180   # s
  request_timeout: 10
  delay_between_requests: 0
expeditions:
  <id>:
    origin: [galaxy, system, position]
    origin_type: planet|moon          # default planet
    dest: [g, s, position]            # default [origin_g, origin_s, 16]
    ships: {<ship_name>: amount}
    speed: 10                         # 1-10
    holding_time: 1                   # hours
    repeat: forever | <int>
    cargo: {<resource>: amount}
```

`parse_bot_config`/`parse_client_config` strip `None` values so defaults in `OGameBot.__init__` apply. `parse_client_config` resolves a universe **name** via `https://lobby.ogame.gameforge.com/api/servers`; `_require` raises `ValueError` on any missing/falsy field. `bot.configparser._initialize_expedition` maps ship/resource names via `Ship.from_name` / `Resource.from_name`.

## Edge cases & failure handling

- `keep_session(maxtries=1)` re-logins once on `NotLoggedInError`; `_request_game_page/_resource` raise `NotLoggedInError` when a `<head>` or missing `ogame-session` meta indicates the login page.
- Per-wakeup exception: retry with backoff `[5,10,15,30,60]`, then `raise` (kills process; Docker restart policy resumes).
- Escape failure branches each set `fs_notification.error` and notify: **no ships**, **no free fleet slots**, **no escape route**, **not enough fuel**, **send_fleet failed**, **failed to find the saved fleet**, **multiple fleets matched**.
- Deployment recall condition guards against a delayed attack: recall if `(hostile_arrival - 10) <= deployment_arrival <= (hostile_arrival + 10)`.
- Saved-fleet recall only if: origin not under attack; fleet still flying; not already returning; `now() - departure_time <= max_return_flight_time`.
- Expedition sending aborts (log + postpone/remove) on: invalid origin; no free fleet/expedition slots; origin under attack; insufficient ships; cargo exceeds capacity (expedition removed); insufficient resources (postpone); insufficient fuel (postpone); `send_fleet` failure (expedition removed).
- Probe-only attacks are ignored (`find_hostile_events`).
- `ships_exist` / `enough_ships` / `remove_empty_values` / `match_planet` / `find_fleets` guard empty states.

## Anti-detection & authenticity

Explicitly present, but minimal:

- **Sleep jitter**: `random.uniform(600, 900)` s between periodic wakeups (`bot.py` `start()`).
- **Action-time jitter**: defensive wakeup scheduled `hostile_arrival - random.randint(120, 180)` s (`_handle_hostile_events`).
- **Request pacing**: `delay_between_requests` minimum gap enforced in `OGame._request` (`time.sleep`), default `0` (off).
- **User-Agent**: hardcoded `Chrome/73.0.3683.103` desktop UA (`client.py`).
- **Phalanx defense** is behavioural, not sensor-modelled: escape flights are sorted to prefer **moons** (`dest.type == CoordsType.planet` False first) and **distance-5 planet↔moon** jumps, which phalanx cannot observe. There is **no** phalanx-range computation anywhere in code.
- A comment in `sort_escape_flights_by_safety` explicitly accepts the residual risk that "a smart opponent who knows how this bot works will force a return and attempt to snipe the returning fleet."
- No day/night cycle, no human-like session cadence, no click/path randomisation, no variable action latency beyond the two jitters above, no idle behaviour, no "occasional failed save" by design.

## Discrete mechanisms

- **M01** — Login/relogin — `OGame.login()`: GET `lobby…/config/configuration.js` → POST `gameforge.com/api/v1/auth/thin/sessions` (token) → set `gf-token-production` cookie → GET `…/api/users/me/accounts` → `_find_account` (match server number + language) → GET `…/api/users/me/loginLink` → GET login URL → require `PHPSESSID` cookie (`ogame/game/client.py`).
- **M02** — Periodic wakeup — `random.uniform(sleep_min=600, sleep_max=900)` s (`OGameBot.start`, `bot/bot.py`).
- **M03** — Hostile detection — `find_hostile_events`: `mission in {attack, acs_attack, destroy, espionage}` AND `dest` in own planets, minus probe-only fleets (`bot/bot.py`).
- **M04** — Probe filter — `only_probes`: `e.ships and Ship.espionage_probe in e.ships and len(e.ships) == 1` (`bot/bot.py`).
- **M05** — Long-term defensive wakeup — if `current_time < hostile_arrival - max_time_before_attack_to_act`, wake at `hostile_arrival - random.randint(min_time_before_attack_to_act, max_time_before_attack_to_act)` (`bot/bot.py`).
- **M06** — Short-term wakeup — wake at `last_friendly_arrival + 1`; or `hostile_arrival - 10` when `current_time < (hostile_arrival-10) < (last_friendly_arrival-1)`; else `hostile_arrival + 1` (post-attack check-up) (`bot/bot.py`).
- **M07** — Escape trigger — `if current_time < hostile_arrival - max_time_before_attack_to_act: continue` (only act inside the action window) (`bot/bot.py`).
- **M08** — Escape enumeration — `get_escape_flights`: for every destination and `fleet_speed in 1..10`, compute distance/duration/fuel → `EscapeFlight` (`bot/bot.py`).
- **M09** — Escape prerequisites — fail with notification if no ships / `free_fleet_slots == 0` / no routes / `fuel_consumption > deuterium` (`bot/bot.py`).
- **M10** — Escape ranking — `sort_escape_flights_by_safety` key `(hostile_event_before_arrival if distance==5 else False, distance, dest.type==planet, duration if distance==5 else fuel_consumption)` (`bot/bot.py`).
- **M11** — Cargo priority on save — deuterium → crystal → metal, `min(amount, free_capacity)` each (`get_cargo`, `bot/bot.py`).
- **M12** — Deployment return — recall deployment to a planet under attack if `(hostile_arrival-10) <= departure_time + flight_duration <= (hostile_arrival+10)` (`bot/bot.py`).
- **M13** — Saved-fleet recall — origin not under attack AND still flying AND not returning AND `now()-departure_time <= max_return_flight_time=600` (`bot/bot.py`).
- **M14** — Distance — galaxy `20000*Δ` (donut `min(Δ, galaxies-Δ)`), system `2700+95*Δ`, position `1000+5*Δ`, type `5`, else `0` (`ogame/game/engine.py`).
- **M15** — Duration — `round((35000/(10*fleet_speed) * sqrt(distance*1000/slowest_ship_speed) + 10) / fleet_speed)` (`ogame/game/engine.py`).
- **M16** — Fuel — `base_fuel * distance/35000 * (35000/(duration*fleet_speed-10) * sqrt(10*distance/ship_speed)/10 + 1)**2` + holding `holding_time*base_fuel/10`, total `round(...)+1` (`ogame/game/engine.py`).
- **M17** — Deuterium save — `global_deuterium_save_factor`, ×`GENERAL_FUEL_CONSUMPTION_FACTOR=0.75` for General (`ogame/game/engine.py`, `data.py`).
- **M18** — Ship speed — `base + base*DRIVE_FACTOR[drive]*level + class_bonus`; factors 0.1/0.2/0.3 (`ogame/game/engine.py`, `data.py`).
- **M19** — Cargo — `base + base*(cargo_hyperspace_tech_percentage/100)*hst_level + collector_trading_bonus`; probe base `= server_data.probe_cargo` (`ogame/game/engine.py`).
- **M20** — Expedition points — `min(5 * Σ(metal_cost+crystal_cost) // 1000, max_expedition_points)` (`ogame/game/engine.py`, `data.py`).
- **M21** — Max expedition points — `top_score` ladder `2500/6000/9000/12000/15000/18000/21000/25000` at `1e5/1e6/5e6/25e6/50e6/75e6/1e8` (`ogame/game/engine.py`).
- **M22** — Expedition find — `int(loot_boost * points * factor)`, factor∈[10,200]; boost = 1, Discoverer `(1+explorer_bonus)*speed`, pathfinder ×2 (`ogame/game/engine.py`, `data.py`).
- **M23** — Find conversion — metal×1, crystal `//2`, deuterium `//3`, dark matter `1800` (`ogame/game/engine.py`).
- **M24** — Expedition launch — requires valid origin, `free_fleet_slots>0` and `free_expedition_slots>0`, origin not under attack, `enough_ships`, cargo ≤ capacity, resources present, `deuterium - cargo.deut >= fuel` (`bot/bot.py`).
- **M25** — Expedition lifecycle — `repeat==0` → finished; `'forever'`; int decremented each send; `running` iff `fleet_id` set; cancelled with optional `return_fleet` recall (`bot/bot.py`).
- **M26** — Expedition fleet matching — unassigned `Mission.expedition` fleets matched by origin/dest/mission/ships (id assigned) (`bot/bot.py`).
- **M27** — Debris harvest — only if `harvest_expedition_debris`; debris at galaxy position **16** (`id='debris16'`); `required_pathfinders = ceil(Σ(debris) / pathfinder_cargo)`; subtract already-flying harvest fleets (assumed zero cargo); send from **closest** origin (`bot/bot.py`, `client.py`).
- **M28** — Pathfinder budget — `available_pathfinders = min(dispatch.ships[pathfinder], deuterium // single_pf_fuel_consumption)` (`bot/bot.py`).
- **M29** — Fleet POST — `send_fleet`: token, `galaxy/system/position/type`, `metal/crystal/deuterium`, `prioMetal=1/prioCrystal=2/prioDeuterium=3`, `mission.id`, `speed` (1-10), `retreatAfterDefenderRetreat=0`, `union=0`, `holdingtime`, `am{ship.id}=amount` (`ogame/game/client.py`).
- **M30** — Retry backoff — delays `[5,10,15,30,60]`, index `min(exc_count, 4)`, non-retry events ignored while retrying (`bot/bot.py`).
- **M31** — Notifications — Telegram `parse_mode=MarkdownV2` with full escaping `_[]()~>#+-=|{}.!`; `parse_notification` maps each `Notify*` (`bot/listeners.py`).
- **M32** — Request throttle — `_request` sleeps to honour `delay_between_requests`; `request_timeout=10` (`ogame/game/client.py`).
- **M33** — Expedition default destination — `[origin_galaxy, origin_system, 16]` (`bot/configparser.py`).
- **M34** — Class detection — overview `#characterclass` div class `miner→collector`, `warrior→general`, `explorer→discoverer` (`ogame/game/client.py`).
- **M35** — Server data — `OGameAPI.get_server_data()` parses `serverData.xml` into `ServerData` (`ogame/api/client.py`, `api/model.py`).
- **M36** — Event parsing — `data-mission-type`, `data-arrival-time`, `data-return-flight`; ships parsed with `has_cargo=False` (event cargo unsupported) (`ogame/game/client.py`).
- **M37** — Movement parsing — return_flight / mission / departure / arrival / `holding` / `holding_time` from movement page (`ogame/game/client.py`).

## Notable concerns

- **Gate 1 (hardcoded AI) — violated.** `ogame/game/data.py` `SHIP_DATA` is a complete hardcoded source of truth for every ship's id, cost, build requirements, drive speeds, fuel consumption, capacity, and rapid-fire table. `ogame/game/const.py` hardcodes every object id (ships 202–219, technologies 106–199, facilities, defenses 401–503, missions, character classes, supplies). Adding a host object would **not** flow through without a module edit — directly contrary to the OGameX gate-1 constraint. (Positive: server speed/bonus/`top_score` parameters *are* read live from `serverData.xml`; and unknown ids on scraped pages are tolerated with a warning via `Ship.from_id`/`Technology.from_id` returning `None`.)
- **Gate 2 (over-engineering) — none material.** The design is lean and single-purpose; no speculative abstractions, no unused layers, no tests/CI to over-build. The only mild scaffolding is the `Listener` base class with two implementations and the frozen-dataclass model, both justified.
- **Gate 3 (human-in-distinguishable play) — several flags.** (1) Constant 24/7 wakeup cadence of 10–15 min with no diurnal variation; (2) actions always fire 2–3 min before attack; (3) `get_cargo` loads deuterium before metal/crystal (value-first ordering a human rarely uses on a save); (4) flat growth curve — the account never builds/researches/mines; (5) deterministic, precise Telegram notifications; (6) `harvest_expedition_debris` auto-sends pathfinders immediately, a distinctly bot-like reflex. These are exactly the observables the OGameX "authenticity" research would flag.
- **Other**: `Ship.trade_ship = 216` exists in `const.py` but has no `SHIP_DATA` entry → `KeyError` if used; `solar_satellite`/`crawler` have `drives={}` so `_drive_technology`'s `min()` would raise if they were ever fleet-calculated; `Resource` enum values are `object()` sentinels (never serialized — `send_fleet` uses literal string keys); no persistence means a process crash loses `_saved_fleets` bookkeeping; `delay_between_requests` defaults to 0 (no pacing by default).

## Confidence

**High** for structure, formulas, constants, thresholds, and file/function names — all quoted directly from `master` source (`bot/bot.py`, `bot/eventloop.py`, `bot/configparser.py`, `bot/protocol.py`, `bot/listeners.py`, `ogame/game/client.py`, `ogame/game/engine.py`, `ogame/game/data.py`, `ogame/game/const.py`, `ogame/game/model.py`, `ogame/util.py`, `ogame/api/client.py`, `ogame/api/model.py`, `start_bot.py`, `config.yaml`, `analytics/universe_heatmap.py`). **Medium** on the absence of things not present in the tree (no tests, no CI config, no other analytics scripts — verified by directory listing and lexical search returning empty). "Anti-detection" and the gate analysis are my synthesis over that source, not the author's stated design.