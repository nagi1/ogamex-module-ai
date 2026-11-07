## Overview

`klaasvp/trashsim-public` is **TrashSim**, a browser-based combat simulator for the MMO **OGame**. It is an **orphan copy** of the final state of the original private code by Klaas Van Parys, published under the MIT license. It is explicitly frozen: *"The code in this repository will also not be updated in any way, it will remain in this fixed state."* The single commit is 3 years old (2023). It is a **human-facing web tool**, not a bot or autonomous player: 13 stars, 14 forks, 1 contributor. Languages: PHP 33.7%, HTML 33%, JavaScript 24.7%, CSS 8.5%.

Stack: custom PHP framework **Plinth** (back-end), **AngularJS** (old version, front-end), **Grunt** for asset compilation, **Redis/Predis** for share-link storage, **Matomo** for analytics, and the third-party `OGetIt` / `OGotcha` libraries for OGame spy-report parsing.

The README itself points at the important code: *"where is the simulation code … you can find the worker code under `src/Resources/js/workers/simulator/`."*

## Architecture & entry points

- **Back-end entry**: `public/index.php` → Plinth. Path constants in `const.php` (`__BASE_ROOT`, `__APP_PATH`, `__APP_CONFIG_PATH`, …).
- **Routing**: `app/config/routing.json` — maps paths to templates + action classes, e.g. `api/player` → `\TrashSim\Action\API\Player`, `api/share` → `\TrashSim\Action\API\Share`, `api/share/{uuid}` → `\TrashSim\Controller\API\Share::getData`, `r/players` → `PlayersPost`.
- **PHP actions**: `app/action/PlayersPost.php` (multi SR-key "players" import), `src/TrashSim/Action/API/Player.php` (single spy-report proxy), `src/TrashSim/Action/API/Share.php` (save a share UUID).
- **Front-end entry**: `src/Resources/js/app.js` — Angular module `trashSimApp`; selects `entityInfoV6` vs `entityInfoV7` via `c.Application.updateEntityInfo()`.
- **Main controller**: `src/Resources/js/controllers/simulator.js` (`SimulatorController`) — owns `$scope.simulator`, settings, parties, hydrators, simulation trigger/cancel, result rendering.
- **Shared domain classes**: `src/Resources/js-shared/classes/` — `simulator.class.js`, `settings.class.js`, `options.class.js`, `fleet.class.js`, `fleet.flying.class.js`, `party.hydrator.class.js`, `simulation.module.javascript.class.js`, `simulation.module.javascriptMW.class.js`.
- **Three Web Workers**:
  - `src/Resources/js/workers/simulator/` — the combat round engine (`simulator.js`, `party.class.js`, `party.legacy.class.js`, `messaging.js`).
  - `src/Resources/js/workers/simulator-result/` — post-processing (`calculator.js`, `simulation.class.js`, `messaging.js`): debris, plunder, moon chance, profits.
  - `src/Resources/js/workers/simulator-ipm/` — IPM (interplanetary missile) simulator (`simulator.js`), plus `src/Resources/js/controllers/ipm.js`.

## Scheduling & loop model

There is **no scheduling, no clock, no autonomous loop** — this is a user-triggered web calculator. The only "loop" is the in-browser Monte Carlo:

- `Simulator.handleSimulations()` in `simulator.js` runs `simulations` trials (default `settings.simulations = 50`).
- Each trial loops rounds:

```js
do {
    round++;
    Simulator.sendProgress(s, round, ID);
    attackers.shootTo(defenders, useRapidFire);
    defenders.shootTo(attackers, useRapidFire);
    attackers.resetEntities();
    defenders.resetEntities();
} while (round < 6 && attackers.remaining > 0 && defenders.remaining > 0);
```

- The **round cap is 6**; if both sides survive, result is `{draw: 1}`.
- Multi-worker module `simulation.module.javascriptMW.class.js` splits the N simulations across `workers = navigator.hardwareConcurrency || 1`, and if `workers > 6`, `workers = Math.ceil(workers / 2)`; a user `simulationWorkerCount` overrides. Single-worker fallback is `simulation.module.javascript.class.js` (selected when `options.simulationModule !== "JavascriptMW"`; default is `"JavascriptMW"`).
- `simulator-result` is a separate worker that aggregates results and emits the final `{response:'result'}`.

## Decision engine & algorithms

There is **no strategic decision engine** (no planning, no targeting policy, no build order). The "engine" is the combat resolution and the profit/result calculators.

**Combat core** (`party.class.js` = Chromium/ArrayBuffer variant; `party.legacy.class.js` = `Uint32Array` fallback selected by `chromium = /chrome/i.test(navigator.userAgent)` in `messaging.js`):

- Per-entity stats derived at load (`loadPlayerEntities`):
  - `attack = Math.floor(weapon + weapon * 0.1 * weaponLvl)`
  - `fullShield = Math.floor(shield + shield * 0.1 * shieldLvl)`
  - `HP = Math.floor((armour + armour * 0.1 * armourLvl) * 0.1)` — i.e. structural integrity / 10.
- `shootTo(enemies, useRapidFire)` — random target selection (`ex = Math.floor(random() * enemies.remaining)`), then:
  - **Shield bounce**: if `attackPower < eEntityFullShield && eEntityShield >= 0`, compute `damagePercentage = attackPower / eEntityFullShield * 100`; if `damagePercentage <= 1` the shot bounces (0 damage). Otherwise shield damage = `floor(damagePercentage) * 0.01 * fullShield` (with a fractional remainder fix), and attack is consumed.
  - **Shield break**: else `attackPower -= eEntityShield`, shield set to `-1`, remainder hits hull.
  - **Hull**: `hull -= attackPower`; `if (hull <= 0)` clear alive bit, `destroyed++`.
  - **Explosion roll**: if still alive and `hull <= maxHP * 0.7`, `if (random() >= hull / maxHP)` → explode. (A ship at 30% hull has a 70% explosion chance.)
- **Rapid fire**: `do { … } while (useRapidFire !== false && random() > (1 / entityRapidFire.rapidfire_against[eType]));` — repeat shot chance `1 - 1/RF`.
- `resetEntities()` compacts the array in place and **fully regenerates shields** between rounds; dead entities are accumulated into `lostEntities`.

**Result calculator** (`simulator-result/simulation.class.js`):
- `calculatePlunder()` — cargo capacity of surviving attackers, then 6-step load: metal capped at ⅓, crystal at ½, deuterium at remainder, then leftover capacity back-fills metal, crystal, metal. Plunderable = `resources * settings.plunder / 100`.
- `calculateDebris()` — `toDebris = entity >= 400 ? defenceDebris * defenceRepair : fleetDebris` where `defenceRepair = 1 - settings.defenceRepair/100`; engineer halves defence losses (`defenceRepair /= 2`).
- `calculateReaperData()` — counts surviving Reapers (type `218`) and applies `settings.combatDebrisFieldLimit` (25%) harvest cap, split metal/crystal by the debris ratio.
- `calculateMoonChance()` — `min(20, floor(debris.total / 100000))`.
- `calculateValue()` / `calculateProfits()` — losses valued at `resources.metal/crystal/deuterium`; profit = `-losses + debris + plunder − fuelConsumption`.

**Flight model** (`fleet.class.js`, `fleet.flying.class.js`, `simulator.class.js::getFlightData`):
- Distance (`getDistance`): cross-galaxy `20000 * galaxies`; same galaxy `2700 + 95 * systems`; same system `1000 + 5 * positions`; same coordinate `5`. Donut wrap-around when `settings.donutGalaxy/donutSystem`.
- Duration: `Math.round((35000 / (fleet.speed / 10)) * Math.sqrt((distance * 10) / slowest) + 10)`; divided by `settings.fleetSpeed`.
- Fuel per ship type: `1 + Math.round(((fuel * count * distance) / 35000) * Math.pow(speed/10 + 1, 2))`; small cargo ×2 fuel at impulse ≥ 5, recycler ×3 at hyperspace ≥ 15 / ×2 at impulse ≥ 17.

**IPM model** (`controllers/ipm.js` + `workers/simulator-ipm/simulator.js`):
- IPM damage = `entityInfo[503].weapon * (1 + 0.1 * weaponLvl)`; target HP = `(armour * (1 + 0.1 * armourLvl)) * 0.1`; remaining = `(HP*count − damage*ipms) / HP`; ABM (`502`) is subtracted one-for-one from incoming IPMs; flight time = `(30 + 60 * systemDistance) / speed`.

## Data model & persistence

- **No database.** No ORM, no tables. The only persistence is **Redis** (Predis):
  - `trashsim-share-$UUID` — JSON of the full simulation state, `expire 60*60*24*3` (3 days), max 8192 bytes (`Action/API/Share.php`).
  - `trashsim-server-$lang-$uni` — cached OGame server settings, `expire 60*60*24` (1 day) (`Action/API/Player.php`).
- **Client-side** state is plain JS objects: `Fleet` (ships, techs, coords, class), `Defender` (defence, resources), `Party`, hydrated by `party.hydrator.class.js`. `storeManager` (localStorage) saves/loads "saved_default"/"saved_data".
- **Entity universe** is a static JS table `entityInfoV6`/`entityInfoV7` in `src/Resources/js-libs/entityInfo.js` — see Notable concerns.
- Entity ids are OGame's real ids: ships `202–219`, defence `401–408`, `502` (ABM), `503` (IPM). In `party.class.js` ids are packed as `subType = type - 200` (8 bits, so max 511), plus an alive bit and player-index bits.

## Config surface

- `env.ini` (root, secrets): `[settings] assetpath`, `[ogotcha] api`, `[ogame] key` (OGame API key), `[matomo] api`, `[admin] user/pass`.
- `app/config/config.ini`: `userservice=false`, `defaultlocale/fallbacklocale = en`, `assets.version = 2.3.4`, `date.timezone = Europe/Brussels`, 20 locales, `[ogame] api`, `[matomo] url`, `[ogotcha] api`.
- `app/config/routing.json` + `routing_*.json` — all routes; `API/settings` returns `locales/default_locale/version/timezone`.
- Front-end `Settings` defaults (`settings.class.js`):

```js
simulations=50, plunder=50 (options 50/75/100), fleetSpeed=1, rapidFire=true,
fleetDebris=30, defenceDebris=0, defenceRepair=70, donutGalaxy=true, donutSystem=true,
galaxies=9, systems=499, deuteriumSaveFactor=1, cargoHyperspaceTechMultiplier=5,
characterClassesEnabled=true, minerBonusFasterTradingShips=100,
minerBonusIncreasedCargoCapacityForTradingShips=25, warriorBonusFasterCombatShips=100,
warriorBonusFasterRecyclers=100, warriorBonusRecyclerFuelConsumption=25,
warriorBonusCombatTechs=2, combatDebrisFieldLimit=25
```

- Front-end `Options` (`options.class.js`): `simulationModule="JavascriptMW"`, `simulationWorkerCount=null`, `customEntityInfo=null` (user can paste a JSON override).

## Edge cases & failure handling

- **Input validation** at the API boundary: SR-key regex `/^sr-[a-z]{2}-\d{1,3}-\w{40}$/`, party `attackers|defenders`, fleet index `>= 0`, share `version >= 2` (Plinth `ValidationVariable` in `Action/API/Player.php`, `Share.php`, `PlayersPost.php`).
- **Share size cap**: payload > 8192 bytes → `error.share.length`.
- **API failure mapping**: `OGetIt\Exception\ApiException` code `INVALID_CR_ID` → `error.api.6000`; other `ApiException`/`CurlException` → `error.convert` (in `Player.php`, `PlayersPost.php`).
- **Bounce rule** guards underpowered shots (attack < 1% of shield deals 0).
- **Tech validation**: `Fleet.hasMissingTechs()` blocks simulation with a "missing technologies" dialog; `hasHighTechs()` flags any tech ≥ 100.
- **Round cap 6** prevents infinite loops → draw.
- **Float precision**: shield-damage remainder fix (`newShield === 0 && damagePercentage > shieldDamagePercentage`) in both party classes.
- **ABM/IPM special cases** (`type === "502"` subtracted directly; IPM armour scaling).
- **Dead/broken code**: `workers/simulator-ipm/simulator.js` has an empty missile loop (`for (var i = ipm; i--;) {}`) and returns `{lost:{}, losses:{}}` — the IPM worker is effectively unfinished; the live IPM math lives in `controllers/ipm.js`.

## Anti-detection & authenticity

**None.** This is not an automaton and contains no stealth, rate-limiting, humanization, jitter, or account-security logic. The only network calls are (1) the OGame API key used server-side to fetch a spy report *on behalf of the user*, (2) Matomo usage analytics, and (3) the Redis share/store endpoints. Nothing here mimics human behaviour because it never acts in-game.

## Discrete mechanisms

- M01 — **Combat round cap** — max 6 rounds; survivors ⇒ draw — `do…while (round < 6 && …)` (`src/Resources/js/workers/simulator/simulator.js`).
- M02 — **Round order** — attackers fire, then defenders fire, then both `resetEntities()` — `simulator.js`.
- M03 — **Shield reset between rounds** — full shield restored in `resetEntities()` — `party.class.js` / `party.legacy.class.js`.
- M04 — **Attack power** — `floor(weapon * (1 + 0.1 * weaponLvl))` — `loadPlayerEntities`.
- M05 — **Shield strength** — `floor(shield * (1 + 0.1 * shieldLvl))` — `loadPlayerEntities`.
- M06 — **Hull points** — `floor((armour * (1 + 0.1 * armourLvl)) * 0.1)` — `loadPlayerEntities`.
- M07 — **Shield bounce** — attack < 1% of target full shield ⇒ 0 damage — `shootTo`.
- M08 — **Shield damage** — `floor(attack/fullShield*100) * 0.01 * fullShield` (fractional remainder fix) — `shootTo`.
- M09 — **Shield break** — if attack ≥ shield: `attack -= shield`, shield := -1, remainder to hull — `shootTo`.
- M10 — **Hull destruction** — `hull -= attack`; `hull <= 0` clears alive bit, `destroyed++` — `shootTo`.
- M11 — **Explosion roll** — if alive and `hull <= 0.7*maxHP`, explode when `random() >= hull/maxHP` — `shootTo`.
- M12 — **Rapid fire** — repeat shot while `random() > 1/rapidfire_against[target]` — `shootTo`.
- M13 — **Entity packing** — `subType = type - 200` (8 bits, max 511), alive bit, player-index bits — `party.class.js` / `party.legacy.class.js`.
- M14 — **Backend selection** — `/chrome/i.test(navigator.userAgent)` → `Party` (ArrayBuffer/DataView) else `PartyLegacy` (Uint32Array) — `messaging.js` + `simulator.js`.
- M15 — **Monte Carlo count** — `settings.simulations` default 50 — `settings.class.js`, `Simulator.run()`.
- M16 — **Win/loss/draw percentage** — `outcome[side] = round(count/simulations*10000)/100` — `simulator-result/calculator.js`.
- M17 — **Average simulation** — mean of lost/remaining/rounds appended as `cases.average` — `calculator.js::getAverageSimulation`.
- M18 — **Desired cases** — best/worst `profits.attackers|defenders.total`, `debris.remaining.total` recyclers — `simulator.class.js::getDesiredResultCases` + `calculator.js::getDesiredCaseSimulation`.
- M19 — **Plunder (6-step)** — metal ≤ ⅓, crystal ≤ ½, deuterium remainder, then back-fill metal/crystal/metal — `simulation.class.js::calculatePlunder`.
- M20 — **Plunder percentage** — `resources * settings.plunder/100`; options 50/75/100 — `simulation.class.js` + `settings.class.js`.
- M21 — **Debris** — fleet 30%, defence 0% × `(1 - defenceRepair/100)`; engineer halves defence losses — `calculateDebris`.
- M22 — **Reaper harvest cap** — `combatDebrisFieldLimit` (25%) split by metal/crystal ratio — `calculateDebris` + `calculateReaperData`.
- M23 — **Moon chance** — `min(20, floor(debrisTotal/100000))` — `calculateMoonChance`.
- M24 — **Loss value** — Σ lost × `resources.{metal,crystal,deuterium}` — `calculateValue`.
- M25 — **Profit** — `-losses + debris.remaining + reaper - fuel` (attackers add plunder) — `calculateProfits`.
- M26 — **Distance** — galaxy `20000*g`, system `2700+95*s`, position `1000+5*p`, same coord `5`, donut wrap — `fleet.class.js::getDistance`.
- M27 — **Flight duration** — `round((35000/(speed/10)) * sqrt((distance*10)/slowest) + 10)` — `simulator.class.js::getFlightData`.
- M28 — **Fuel** — `1 + round((fuel*count*distance/35000) * (speed/10+1)^2)`; small cargo ×2 @impulse5, recycler ×3 @hyperspace15 / ×2 @impulse17 — `getFlightData`.
- M29 — **Speed per ship** — base × drive factor (combustion 0.1, impulse 0.2, hyperspace 0.3 per tech) + class bonuses — `fleet.flying.class.js::getSpeeds`.
- M30 — **Cargo capacity** — +5%/lvl hyperspace; collector +25% for types 202/203 — `fleet.class.js::getCargoCapacity` / `simulation.class.js::getEntityCargoCapacity`.
- M31 — **Tactical retreat ratio** — `(Σ metal+crystal+deuterium of fleet)/4` for cargo types 202/203/208/209 else full value; ratio between parties — `fleet.class.js::getTacticalRetreatCosts` + `controllers/simulator.js`.
- M32 — **Worker pool** — `hardwareConcurrency`, halved if > 6, `simulationWorkerCount` overrides, split `floor(N/W)` + remainder to last worker — `simulation.module.javascriptMW.class.js`.
- M33 — **IPM damage & flight** — `503.weapon*(1+0.1*lvl)`; HP `(armour*(1+0.1*lvl))*0.1`; ABM `502` 1:1 intercept; time `(30+60*systemDistance)/speed` — `controllers/ipm.js`.
- M34 — **Spy-report ingestion** — SR-key regex; `OGetIt::getSpyReport`; research mapping `{106 espionage, 108 computer, 109 weapon, 110 shield, 111 armour, 113 energy, 114 hyperspacetech, 115 combustion, 117 impulse, 118 hyperspace, 120 laser, 121 ion, 122 plasma, 123 irn, 124 astrophysics, 199 graviton}` — `party.hydrator.class.js` + `Action/API/Player.php`.
- M35 — **Character classes** — collector (1), general (2), discoverer (3) via `getPlayerClass`; general speed bonus excludes deathstar `214` — `party.hydrator.class.js`, `fleet.flying.class.js`.
- M36 — **Share persistence** — UUIDv4 → Redis `trashsim-share-$UUID`, TTL 3 days, ≤ 8192 B — `Action/API/Share.php`, `Controller/API/Share.php`.

## Notable concerns

- **Gate 1 (no static hardcoded AI) — heavily violated by design, but deliberately.** The entire object universe is hardcoded in `src/Resources/js-libs/entityInfo.js`: two full copies `entityInfoV6` and `entityInfoV7` with per-entity `speed, cargo_capacity, fuel_usage, armour, shield, weapon, rapidfire_from, rapidfire_against, type, resources{metal,crystal,deuterium,energy}`. Entity ids (`202…219`, `401…408`, `502`, `503`), prices, requirements and rapid-fire values are all literals. Tech research ids and class ids are hardcoded in `party.hydrator.class.js` and `fleet.class.js`. For OGameX this is exactly the pattern the gates forbid — but note TrashSim's purpose is to *mirror* OGame's fixed data, and the copy is frozen. There is **no host-read-at-planning-time** mechanism; adding a host object would require editing `entityInfo.js` plus `getDefaultShipsList()` and `getSpeeds()`.
- **Gate 2 (over-engineering).** Live code is lean, but `src/Resources/js/workers/simulator/old/` is a graveyard of ~12 abandoned `party.*` implementations (`party.v1–v4`, `party.x`, `org`, `prototype`, `.skip`/`.tmp`) and `simulator-result/old/simulator-old.js` — dead code left in-tree. The V6/V7 duplication of the whole entity table is also a maintenance smell.
- **Gate 3 (human behaviour).** Not applicable — nothing here plays OGame. No human-observable behaviour exists.
- The IPM worker is half-finished dead code (empty loop, empty result), with the real IPM logic duplicated in the Angular controller.
- `warriorBonusCombatTechs` is declared in `Settings` and passed in `Simulator.run()` but the actual tech bonus application is commented out in `fleet.class.js::getSimulationData` — a live config knob with no effect.
- No tests, no CI, no database migrations; minimal error UX (several `catch` blocks and the share-404 path are empty/TODO).

## Confidence

**High.** This is a small, fully readable repository and I inspected the actual source files (not just the README): the simulator workers, both `Party` classes, the result calculator, flight/fuel/distance math, settings/options defaults, hydrators, the entity data table, PHP actions/controllers, routing, and config. The only caveat is that the `fetch_webpage` tool returns excerpted, re-flowed text (not byte-exact formatting), so a few long literals (the full entity table, the full `const.php`) may have line-break differences, but all named functions, constants, formulas and thresholds were read directly and are quoted accurately. `github_repo` semantic search was unavailable during this session; lexical search was used instead.