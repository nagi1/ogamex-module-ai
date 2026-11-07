## Overview

`ogame-infinity/web-extension` ("Ogame Infinity", OGI) is a **Manifest V3 browser extension / UI tool for the OGame browser game** (JavaScript 85.1%, CSS 14.7%). The README calls it "the monolithic code mess for the Ogame Infinity extension." It is **not an autonomous bot**: all automation is initiated by a human click on a page and driven by URL-parameter redirect chains across page reloads; there is no background scheduler, no login handling, and no request faking. The extension is "officially tolerated by Ogame" (welcome screen links the Gameforge board thread), and it deliberately **disables** direct probing because that feature was requested removed by Gameforge rules (`probingWarning()` in `src/ogkush.js`).

Core value: empire-wide resource/fleet overview, one-click farming/fleet-save ("collect"), expedition fleet composition, per-player intel ("stalk" + PTRE), message/report analyzers, ROI (return-on-investment) ranking, and statistics. Last commit `2bddf1f` (PR #551, "P16Ghost"). License MIT, version `2.1.1` (package.json), 42 stars / 50 forks.

The whole application lives in one 736 KB file: `src/ogkush.js` (18,406 lines), containing a single `OGInfinity` class plus helper classes/functions.

## Architecture & entry points

- **Manifest** `src/manifest.json` (MV3) and `src/manifest-firefox.json` (MV3, `gecko` id `{082ec2d7-...}`): permissions `["storage"]`, host `https://*.ogame.gameforge.com/game/*`, excludes `admin2/*` and `standalone&component=empire`.
- **Content scripts** (both `run_at: document_start`): one injects `global.css`; one injects `main.js`.
- **`src/main.js`** — on `DOMContentLoaded` dynamically `import()`s the content-script entry `src/ctxcontent/index.js` and calls `main()`.
- **`src/ctxcontent/index.js`** — content-script side. Registers the **Callback Event Bridge** (`contentContextInit`), defines `dataHelper` (`DataHelper`, universe/planet/player cache), then injects into the page: `libs/lz-string.min.js`, `libs/purify.min.js` (DOMPurify), and `ogkush.js` (as ES module).
- **`src/ogkush.js`** — page-context monolith. Imports `conf-options.js`, analyzers, utils, `messages-analyzer`, `OverviewPage`, `TraderImportExportPage`, `RecyclingYieldCalculator`. Bottom IIFE: `pageContextInit()`, instantiate `new OGInfinity()`, `ogKush.init()`, `new Messages()`.
- **`src/background.js`** — service worker, only routes `chrome.runtime.sendMessage({type:"notification",...})` → `chrome.notifications.create`. No loop, no automation.
- **Callback Event Bridge** `src/util/service.callbackEvent.js` — page↔content communication via `CustomEvent` and a random token in `document.documentElement.dataset.ogiCallbackEventToken`. Commands registered: `ptre.galaxy`, `ptre.setTeamKey`, `ptre.setDebugLogs`, `ptre.galaxyInfo`, `messages.expeditionType` (docs: `docs/context.content.commands.md`, `docs/service.callbackEvent.md`). `_createToken()` = `(Math.floor(Math.random()*0xffffffffffff)+1e6).toString(16).padStart(12,"0")`.
- **Other page modules**: `src/ctxpage/conf-options.js` (config), `src/ctxpage/messages-analyzer/index.js`, `src/ctxpage/messages/index.js`, `src/ctxpage/overview/OverviewPage.js`, `src/ctxpage/traderOverview/TraderImportExportPage.js`.
- **Third-party deps** (vendored in `src/libs/` + `src/assets/expeditions.tsv` 133 KB multilingual message table): Chart.js 2.9.3, chartjs-plugin-labels, DOMPurify 2.4.1, lz-string.

## Scheduling & loop model

There is **no scheduler**. "Loops" are UI/DOM driven, all in the page context of the currently open tab:

- **`oglMode` query parameter** is the automation state machine. `src/util/enum/ogiMode.js`: `DEFAULT 0, HARVEST 1, LOCK 2, AUTOHARVEST 3 (dead), RAID 4, UNKNOWN_NB_5 5 (dead), AUTOEXPEDITION 6, CUSTOM_MISSION 7`. Each mode changes what `fleetdispatch` does on load and what URL it redirects to after a fleet is sent.
- **Redirect chains**: after send, `onFleetSentRedirectUrl` (string or function) is the next URL; `FleetDispatcher.prototype.submitFleet2` is wrapped (`onFleetSent`) to `window.location = href` after 50 ms. `localStorage["ogl-redirect"]` is also set.
- **`setInterval`/`setTimeout`** (UI only): empire click-binding 100 ms; event box 10 ms; jump-gate countdown 1 s; activity timers 60 s; movement-page timer 500 ms; ACS/union join timer 200 ms; storage-full refresh 2 s; `showTabTimer` 1 s (disabled). `FPSLoop()` = `setTimeout(..., 1000/20)` + `requestAnimationFrame` (used by `checkDebris`).
- **`MutationObserver`** (`src/util/observer.js`): a right-side observer re-runs `sideOptions, minesLevel, resourceDetail, harvest, activitytimers, needsUtil.display, jumpGate, updateFlyings, updatePlanets_*` when the planet list DOM changes; other observers watch technology templates and the galaxy page.
- **Event listeners**: keyboard shortcuts (`keyboardActions`), planet/moon click handlers (`harvest`), and page load hook `start()`.
- **Debounce/throttle**: `debounce(func, wait, immediate)` global; `fleetDispatcher.updateMissions` debounced 200 ms; Enter-to-send throttled 650 ms.

## Decision engine & algorithms

There is no planner or objective engine. The "decisions" are deterministic one-shot actions computed on the fleet page:

- **Expedition composer** (`expedition()` in `ogkush.js`): pick bracket by `EXPEDITION_TOP1_POINTS.findIndex(points => points > topScore)`, take `EXPEDITION_MAX_RESOURCES[level]`; multiply by `(1 + explorerBonusIncreasedExpeditionOutcome) * speed` if Explorer, by LF class/expedition bonus, and by item booster (`itemImageID` sha1→bonus). Pathfinder (219) selected → `maxResources *= 2`. Combat ship priority `[218,213,211,215,207,206,205,204]`. Cargo ships to satisfy both expedition points and cargo: `minSC = Math.ceil((maxExpeditionPoints - expeditionPoints) / SHIP_EXPEDITION_POINTS[202])`, `maxSC = Math.max(minSC, calcNeededShips({fret:202, resources: maxResources - cargoCapacity}))`. `maxResources = Math.floor(maxResources * options.expedition.limitCargo)`.
- **Cargo math** `src/util/calcNeededShips.js`: `Math.ceil(total / cargoCapacity)`, `* 107/100` when `moreFret`.
- **Best cargo ship** (`selectBestCargoShip`): try preferred then `[202,203,219,210]`; pick first whose `neededShips <= onPlanet`; else fill greedily.
- **ROI ranking** (`getBestRoi`): `roiMine`, `roiAstrophysics` (124), `roiPlasmatechnology` (122), `roiLfResearch`, `roiLfBuilding`, each returns `(costInStandardUnit * 3600) / productionDiffPerHour`, sorted ascending, shown top 20.
- **Mine production** (`updateEmpireProduction`, `minesProduction`): metal `30*lvl*1.1^lvl*speed*posBonus`; crystal `20*lvl*1.1^lvl*speed*posBonus`; deut `10*lvl*1.1^lvl*speed*(1.36-0.004*(temp+20))`. Position bonus metal: pos 6/10 → 1.17, 7/9 → 1.23, 8 → 1.35 (`METAL_POS_BONUS`); crystal pos 1 → 1.4 (`CRYSTAL_POS_BONUS = [1.4,1.3,1.2,1,…]`).
- **Storage capacity**: `5000 * Math.floor(2.5 * Math.exp((20/33) * lvl))`.
- **Building cost/time** (`building()`): `cost = baseCost * factorCost^(lvl-1) * costFactor`; `time = ((metal+crystal) / (2500*(1+robotic)*2^nanite*(...) * speed)) * 3600` (LF tech capped).
- **Research cost/time** (`research()`): `cost = baseCost * factorCost^(lvl-1) * (1 - 0.0025*labLvl for LF)`; `time = ((metal+crystal)/(speed*1000*(1+labLvl))/researchDivisor)*3600`; technocrat −25 %, Explorer −25 %, acceleration −25 %.
- **Standard unit** `src/util/standardUnit.js`: `Σ (amount[i]/tradeRate[i]) * tradeRate[base]`; default `tradeRate [2.5,1.5,1,0]`, base 0 (MSU).
- **Recycling yield** `src/util/recyclingYieldCalculator.js`: `cost * debrisFactor` (from `universeSettingsTooltip.debrisFactor`), deut included only if `deuteriumInDebris`.
- **Expedition message classifier** `src/ctxcontent/callbacks/expedition-type.js`: Levenshtein similarity vs `assets/expeditions.tsv`, accepts type if similarity `> 0.35`.
- **Crawler production cap** (`updateEmpireProduction`): `maxCrawlers = (mine1+mine2+mine3) * MAX_CRAWLERS_PER_MINE (8)` × `(1+minerBonusMaxCrawler)` if miner+geologist; `crawlerProd = mineProd * min(count, maxCrawlers) * resourceBuggyProductionBoost * …`, capped by `resourceBuggyMaxProductionBoost`.

## Data model & persistence

- **`localStorage["ogk-data"]`** (`src/util/OGIData.js`, class `OGIData`, singleton) — the whole app state JSON, written on every setter via `#save()`. Fields include: `playerId, universeUrl, options, technology, playerMarkers, markers, ships, expeditions(+Sums), discoveries(+Sums), spies, combats(+Sums), trades, harvests, empire, needs, lastSentFleet, sideStalk, searchHistory, keepTooltip, tchat, welcome, myActivities, productionProgress*, researchProgress, jumpGate, selectedLifeforms, lifeformBonus, reminders, pantrySync, trashsimSettings, serverSettingsTimeStamp, topScore, speed, speedResearch, speedFleetWar/Peaceful/Holding, universeSettingsTooltip, cargoHyperspaceTechMultiplier, miner/explorer bonus constants`.
- **`chrome.storage.local`**: per-universe blob keyed `[UNIVERSE]` (serialized `DataHelper` minus runtime fields), `ogi-galaxy-<universe>` (PTRE galaxy snapshot, own key so hot writes stay small), `ogi-scanned-<universe>` (scanned planets/players). Written debounced (`scheduleGalaxyStorageFlush(delayMs = 2000)`).
- **`sessionStorage.lastPantryTry`**; **`localStorage.ogl-redirect`**, **`localStorage.detailsOpen`**.
- **Cloud sync (beta)**: Pantry `getpantry.cloud` (`checkPantrySync`, `pantrySync`) with `LZString.compressToUTF16(JSON.stringify(...))`; baskets keyed `<universe>-<lang>-full`; POST vs merge decided by timestamps (10.1 s retry gate).
- **Export/import**: `.data` JSON file (`settings()` dialog), `reset` with confirm.
- **Empire data**: fetched from `standalone&component=empire` JSON (planets + moons), plus `serverData.xml` for universe settings (24 h TTL).

## Config surface

`src/ctxpage/conf-options.js` exports a frozen `_options` object behind a `Proxy` (`getOptions()`) that forbids setting/defining/removing undeclared keys. Defaults (notable):

- `limitCrawler: true`, `crawlerPercent: 1.5`, `tradeRate: [2.5, 1.5, 1, 0]`, `dispatcher: true`, `rvalLimit: 1e6`, `rvalSelfLimitPlanet: 1e7`, `rvalSelfLimitMoon: 1e6`, `standardUnitBase: 0`.
- `expedition: { cargoShip: 202, combatShip: 218, defaultTime: 1, limitCargo: 1, rotation: false, rotationAfter: 3, sendCombat: true, sendProbe: true, standardFleet: false, standardFleetId: 0 }`.
- `collect: { ship: 202, mission: 3, target: {galaxy:0,system:0,position:0,type:1} }`.
- `fret: 202`, `spyFret: 202`, `expeditionMission: 15`, `foreignMission: 3`, `harvestMission: 4`, `alertHostileIncomingMode: 0`, `importExportReminderMode: 2`, `spyFilter: "DATE"`, `nbCustomMissions: 0`, `customMissions: {}`, `ptreTK: ""`, `pantryKey: ""`, `simulator: ""`.
- `kept: {}`, `defaultKept: {}`, `defaultKeptMoon: {}`, `hiddenTargets: {}`, `lessAggressiveEmpireAutomaticUpdate: false`.
- Icon display modes `regularConstructionsIconsDisplayMode/lifeformConstructionsIconsDisplayMode/lifeformResearchsIconsDisplayMode/ownFleetYieldIconsDisplayMode` (0–4, enum `iconMode`).
- The settings dialog in `ogkush.js settings()` wires most of these; `initConfOptions(json.options)` merges persisted options; `setOption` allows only known keys through the proxy.

## Edge cases & failure handling

- `waitFor(predicate, interval=10, timeout=5e3)` rejects with `"Wait for timeout exception"` (`src/util/wait.js`).
- All `fetch`es attach an `AbortController` aborted on `window.onbeforeunload` (`fetching.js`, `service.ptre.js`, `getJSON`).
- Expedition: if chosen combat ship absent, fall back down priority list; if `204`/`205`/`206` fallback conflicts with pathfinder or large-cargo, set `combatShip = 0`; missing pathfinder/probe/cargo produce localized warnings (`getTranslatedText(107..110)`).
- Custom mission: `findTargetByIdOrCoords` — if a saved target no longer exists (moved/destroyed), **do not auto-select** and let the player re-pick ("avoid error and bad experience").
- Fleet send: `limitReached` without `force` → `errorBoxDecision` then `submitFleet2(true)`; cost mismatch vs computed → inline `"resources not correct, try to update LF bonus"` warning.
- PTRE key validation: must start `TM` and have 18 chars after stripping `-`; else cleared.
- PTRE galaxy scan: `try/catch` returns `{}` and logs — never breaks the OGame galaxy render; final `secureCoords` re-check before sending; only positions 1..15 (`PTRE_MIN_POS/PTRE_MAX_POS`); identical revisits skip the write.
- Pantry errors mapped to toasts: 400 "Invalid Pantry Key", 413 "Too much data (reset addon)", 502/503/500 "unavailable".
- Storage purge: `getLocalStorageSize()` and `purgeLocalStorage()` when total > 4.5 MB.
- Record TTL cleanup (`cleanupMessages`): expeditions/discoveries/harvests deleted after 5 days (non-favorited), combats after 30 days.
- Reset guarded by `confirm("Are you sure ? :)")`; `json` import reloads to overview.
- `#migrations()` fills missing nested keys (`lifeformBonus.productionBonus` etc.) on load.

## Anti-detection & authenticity

**None.** There is no stealth, no user-agent spoofing, no random action delay, no human-latency simulation. `Math.random` appears only in the callback-bridge token generation. The project's posture is the opposite of covert: it is "officially tolerated" by Gameforge, and it **removed** direct probing from stalks/target lists at Gameforge's request (`probingWarning()` shows the forum "forbidden features" link; the probe buttons are inert). The only "authenticity"-adjacent option is `lessAggressiveEmpireAutomaticUpdate` (slows empire re-fetch to 5-minute intervals) and empire update gating (`5*60*1e3` / `1*60*1e3`). Fleet-composition logic (expedition points, cargo limits, rotation) mirrors what a competent human does, but there is no attempt to vary timing or behavior to look human.

## Discrete mechanisms

- **M01 — Empire auto-refresh gate** — refresh if `mode==DEFAULT && ((Δt>5min && needsUpdate) || (Δt>1min && !lessAggressiveEmpireAutomaticUpdate))` (`src/ogkush.js updateEmpireData`).
- **M02 — Needs-update marking** — `setInterval(100ms)` attaches click→`needsUpdate=true` to `.scrap_it, .build-it_wrap, button.upgrade, button.buildmulti, .abortNow, .build-faster, .og-button.submit, .abort_link, .js_executeJumpButton` (`src/ogkush.js`).
- **M03 — One-click harvest** — planet/moon picture click → `fleetdispatch&galaxy..&mission=${harvestMission}&oglMode=1` (`src/ogkush.js harvest`).
- **M04 — Auto-harvest chain** — `oglMode=3|5` cycles planets, clicks `#allresources` + needed cargo, Enter sends, redirects to next planet (`src/ogkush.js autoHarvest`; mode 3/5 marked dead/remanent in `enum/ogiMode.js`).
- **M05 — Collect (farm / fleet-save)** — resets, `send_none`+`select-most`, `selectBestCargoShip(collect.ship)`, sets target `collect.target` or home planet, mission `collect.mission` (3/4), redirects to next planet `oglMode=0` (`src/ogkush.js collect`).
- **M06 — Expedition fleet composition** — top-1 score brackets `EXPEDITION_TOP1_POINTS=[1e4,1e5,1e6,5e6,25e6,5e7,75e6,1e8]`, `EXPEDITION_MAX_RESOURCES=[4e4,5e5,12e5,18e5,24e5,3e6,36e5,42e5,5e6]`, × Explorer/speed/LF/item bonuses, pathfinder ×2, probe, combat ship priority `[218,213,211,215,207,206,205,204]`, cargo = `ceil((maxPoints-points)/SHIP_EXPEDITION_POINTS[...])`, resources × `limitCargo` (`src/ogkush.js expedition`).
- **M07 — Expedition system rotation** — if same-system outgoing expeditions `>= rotationAfter` and another own system exists, rotate to next planet's system; force P16; keep `cp` when rotating (`src/ogkush.js expedition`).
- **M08 — Auto-expedition trigger** — `oglMode=6 && expeditionCount < maxExpeditionCount && fleetCount < maxFleetCount` → click `.ogl-expedition` (`src/ogkush.js expedition`).
- **M09 — Custom missions (1..5)** — default `{ship:202, mission:4, rotation:false, keepSpeed:false, resources:true, target:{}, color:"orange"}`; mission 6 = espionage "ghost" to P16 with `systemDistance` offset (donut-aware); per-planet target keyed by current planet id; Ctrl+1..5 triggers (`src/ogkush.js customMissions`).
- **M10 — Raid redirect** — `oglMode=4` (RAID) sets `localStorage["ogl-redirect"]` to messages page after send (`src/ogkush.js spyTable`).
- **M11 — Select-most/all ships minus kept** — `selectMostShips` = `ship.number - kept[ship.id]`; `selectAllShips` = `ship.number` (`src/ogkush.js`).
- **M12 — Needed cargo ships** — `Math.ceil(totalResources / cargoCapacity)`, `+7%` if `moreFret` (`src/util/calcNeededShips.js`).
- **M13 — Needed-cargo badges** — on 202/203 show `ceil((available - kept)/capacity)`, click selects ships + `select-most` cargo (`src/ogkush.js neededCargo`).
- **M14 — Recycling yield** — `fleetCost(ships) * debrisFactor` (+defence), deut only if `deuteriumInDebris` (`src/util/recyclingYieldCalculator.js`).
- **M15 — RVAL / self-yield warning** — highlight debris when `standardUnit ≥ rvalLimit (1e6)`; own-fleet yield icon when `≥ rvalSelfLimitPlanet (1e7)` / `rvalSelfLimitMoon (1e6)` (`src/ogkush.js checkDebris, fleetOverview, updateSpaceShipsPresence`).
- **M16 — Own activity timers** — `myActivities[coords]` updated on load; display `min(round((now-last)/6e4),60)`, refresh every 60 s; show minutes only if `≥15 && ≠60` (`src/ogkush.js activitytimers`).
- **M17 — Galaxy activity read** — `getActivity(row)` → planet/moon `minute15`→0, `.showMinutes`→number, else 61 (`src/ogkush.js`).
- **M18 — PTRE activity send** — for watched players (sideStalk/marked/searchHistory) and P1..15, POST `ptreJSON[coords] = {id,player_id,teamkey,mv,activity,galaxy,system,position,main,cdr_total_size, moon:{id,activity}}` (`src/ogkush.js scan section + ptreActivityUpdate`).
- **M19 — PTRE galaxy diff** — diff `playerId/planetId/moonId` per position vs `galaxyStorage[g][s]`; first visit emits all 15; persist only on change; flush debounced 2 s (`src/ctxcontent/data-helper.js scan`).
- **M20 — Universe cache refresh** — `update()` throttled 1 min; fetches highscore/players/planets/alliances in parallel; caches weekly planets snapshot (`src/ctxcontent/data-helper.js update`).
- **M21 — Server settings read** — fetch `https://s<universe>-<lang>.ogame.gameforge.com/api/serverData.xml`, parse into `json.*`, TTL 24 h (`src/ogkush.js updateServerSettings`).
- **M22 — Message analyzers** — spy/fight/expedition/harvest/trade classification and stat accumulation (`src/ctxcontent/services/analyzer/*` + `src/ctxpage/messages-analyzer/index.js`).
- **M23 — Expedition-type classifier** — Levenshtein similarity vs `assets/expeditions.tsv`, accept if `> 0.35` (`src/ctxcontent/callbacks/expedition-type.js`).
- **M24 — Combat-report parse** — `fetchAndConvertRC` pulls CR JSON, `isProbes` = only ship 210 + zero loot + `debris.crystalTotal < 2e5`, `win/draw` from result (`src/ogkush.js`).
- **M25 — Record TTL cleanup** — expeditions/discoveries/harvests > 5 d, combats > 30 d (non-favorited) deleted on load (`src/ogkush.js cleanupMessages`).
- **M26 — Local storage purge** — `getLocalStorageSize()`, `purgeLocalStorage()` when total > 4.5 MB (`src/ogkush.js`).
- **M27 — Pantry cloud sync** — LZString-compressed basket POST/merge; retry gate 10.1 s; error→toast mapping (`src/ogkush.js checkPantrySync/pantrySync`).
- **M28 — Jump-gate cooldown** — `jumpTimes=[60,53,47,41,36,31,27,23,19,17,14,13,11,10,10]` minutes ÷ `speedFleetWar`, 1 s countdown until ready (`src/ogkush.js jumpGate`).
- **M29 — Storage-full ETA** — `(storage - amount)/hourlyProd` → date; reload when reached; refresh 2 s (`src/ogkush.js showStorageTimers`).
- **M30 — Solar-satellite suggestion** — `satsNeeded = ceil(-diff / (1+energyBonus) / floor((temp+140)/6))` (`src/ogkush.js technoDetail`).
- **M31 — ROI ranking** — `getBestRoi()`: mines lvl+1..max+5, astro +2..+10, plasma +1..+5, LF research/buildings; sort by payback time; top 20 (`src/ogkush.js getBestRoi/roi*`).
- **M32 — Mine production formula** — metal `30*lvl*1.1^lvl*speed*pos`, crystal `20*…`, deut `10*1.1^lvl*speed*(1.36-0.004*(temp+20))` (`src/ogkush.js minesProduction/updateEmpireProduction`).
- **M33 — Crawler cap** — `maxCrawlers = (m1+m2+m3) * 8` × `(1+minerBonusMaxCrawler)` (miner+geologist); production capped by `resourceBuggyMaxProductionBoost` (`src/ogkush.js updateEmpireProduction`).
- **M34 — Building cost/time** — `cost = baseCost * factorCost^(lvl-1) * costFactor`; `time = ((metal+crystal)/(2500*(1+robotic)*2^nanite*max(4-lvl/2,1)*speed))*3600` (`src/ogkush.js building`).
- **M35 — Research cost/time** — `cost = baseCost * factorCost^(lvl-1)` (LF ×lvl, `1-0.0025*labLvl`); `time = ((metal+crystal)/(speed*1000*(1+labLvl))/researchDivisor)*3600`; technocrat/explorer/acceleration −25 % each (`src/ogkush.js research`).
- **M36 — Standard unit** — `Σ (amount[i]/tradeRate[i]) * tradeRate[base]`; base 0=MSU,1=K,2=M (`src/util/standardUnit.js`).
- **M37 — Fleet-speed selector** — Warrior steps 0.5 (5..100), others steps 1 (10..100) (`src/ogkush.js utilities`).
- **M38 — ACS/union join timing** — `maxDelay = union.time*1000 - serverTime` → `diff*0.3`; 200 ms live countdown (`src/ogkush.js initUnionCombat`).
- **M39 — Incoming-hostile-fleet alert** — `updateFlyings` parses event box; `updatePlanets_IncomingHostileFleet` paints alert icon per planet/moon, mode `alertHostileIncomingMode` 0/1/2 (`src/ogkush.js`).
- **M40 — Debris highlight** — galaxy `.cellDebris` + P16 expedition debris; total > `rvalLimit` → active (`src/ogkush.js checkDebris`).
- **M41 — Keyboard shortcuts** — fleet1: `E` expedition, `C` collect, `N` resetall, `A` sendall, `M` select-most, Ctrl+1..5 custom missions; fleet2: mission letters; Enter send throttled 650 ms (`src/ogkush.js keyboardActions`).
- **M42 — Keep-on-planet floors** — per-coords `kept[coords]` (resources `[0..3]` + ship counts); fallback `defaultKept`/`defaultKeptMoon`; honored by select-most, cargo, jump-gate (`src/ogkush.js keepOnPlanetDialog/selectMostShips/neededCargo/jumpGate`).
- **M43 — Number formatting** — `toFormattedNumber/fromFormattedNumber` handle K/M/B/T + locale separators (`src/util/numbers.js`).
- **M44 — Coordinate packing** — `GSSSPPPT` integer (`src/util/ogame.coordinate.js`).
- **M45 — Player status decode** — `b` banned, `v` vacation, `i` inactive, `I` long-inactive, `o` outlaw (`src/util/player.js`).
- **M46 — Side-stalk pin list** — max 20 players, 6 s undo / 300 ms fade (`src/util/stalk.js side`, `SIDE_STALK_UNDO_DURATION`).
- **M47 — Search history** — max 5 players, dedupe + push + shift (`src/ogkush.js`).
- **M48 — Target-list markers** — 8 colors; per-galaxy/system tabs (step 50 systems); `hiddenTargets` toggles (`src/ogkush.js targetList`).
- **M49 — Production attribution** — base + plasma + geologist + officer + alliance + player-class + item + LF + crawler per resource (`src/ogkush.js updateEmpireProduction`).
- **M50 — Timezone offset** — `json.timezoneDiff` from `window.timeZoneDiffSeconds`; `timeZone` option shifts displayed dates (`src/ogkush.js timeZone`).
- **M51 — Welcome wizard** — first run redirects to `fleetdispatch`, character-class detection, tips popup (`src/ogkush.js welcome`).
- **M52 — PTRE spy import** — `importSpy(teamKey, reportKey)` GET (`src/util/service.ptre.js`).
- **M53 — Simulator deep-links** — `sr-` keys → TrashSim prefill (base64), `cr-` keys → Ogotcha (`src/ogkush.js uvlinks`).
- **M54 — Fleet API clipboard** — `characterClassId;…|114;lvl|109..118|ship;count|` string (`src/ogkush.js APIStringToClipboard`).
- **M55 — Import/export/reset** — `.data` file import (FileReader), export via download, reset with checkbox keep-cache (`src/ogkush.js settings`).
- **M56 — Utility helpers** — `debounce`, `Queue` class, `FPSLoop` (20 fps), `extractJSON` first-valid-JSON scanner (`src/ogkush.js`, `src/util/json.js`).
- **M57 — Auto-delete messages** — `autoDeleteEnable`/`kept` cleaning on messages page (`src/ctxpage/messages/index.js`).

## Notable concerns

**Gate 1 (static, hardcoded AI) — heavily violated by design.** The extension hardcodes the OGame object universe as a source of truth: ship ids (`src/util/enum/ship.js`: 202 SmallCargo … 219 Pathfinder), defence ids (`defence.js`: 401–408, 502, 503), costs (`shipCosts.js`, `defenceCosts.js`), mission ids (`missionType.js`), player classes (`playerClass.js`), and item bonuses by **image sha1** (`itemImageID.js`). Worse, `ogkush.js` embeds full building/research cost curves (`RESEARCH_INFO`, `BUILDING_INFO` with `baseCost/factorCost/factorTime/basePop/factorEnergy`), full ship stats + rapid-fire tables inline in `defenseOverview()` (`shipsInfo` JSON), position-bonus tables, `MAX_CRAWLERS_PER_MINE = 8`, and production/storage formulas. It partially reads live data (`fleetDispatcher.fleetHelper.shipsData`, `serverData.xml`, `resourcesBar`) but duplicates the same numbers statically. Any host object change requires an extension edit.

**Gate 2 (over-engineering).** The 736 KB single `OGInfinity` class is the antithesis of "smallest mechanism": dozens of methods, duplicated ship-stat JSON inline, proxy-wrapped config (`conf-options.js`), proxy getter/setter boilerplate for ~40 fields in `OGIData.js`, fully-commented-out `notifications.js`, disabled `betterTooltip`, dead `oglMode` 3/5 (`AUTOHARVEST`, `UNKNOWN_NB_5`), `showTabTimer` TODO, and deprecated `createDOM` aliases. Not abstraction-over-engineering so much as accumulation-over-engineering.

**Gate 3 (human reference behavior).** This is a human-assist tool, so most concerns are inverted: the "player" is real. But the auto-chained flows (M03–M05, M08, M09) are one-click mass actions across the whole empire with deterministic, non-varying timing — the kind of behavior Gameforge's "automation" rule targets; the extension survives only because it is explicitly tolerated. There is no fleet-save scheduling, no probe-avoidance, no social behavior, and no notion of latency/uptime shaping (irrelevant for a browser tool).

## Confidence

**High** for architecture, entry points, config surface, data model, and all `src/util/*` files (read directly from raw source). **High** for the discrete mechanisms in `ogkush.js` (read via GitHub blob rendering; function bodies quoted verbatim). **Medium** for a few numeric constants I could not fully expand (`SHIP_EXPEDITION_POINTS`, `EXPEDITION_EXPEDITION_POINTS`, `RESEARCH_INFO`/`BUILDING_INFO` table contents, full `METAL_POS_BONUS` array) — they exist and are referenced, but exact values were truncated in extraction. **Low** for anything not observed: I found no fleet-save timer, no background scheduler, no anti-detection code, and no LLM/AI of any kind — asserted as "none" rather than invented.