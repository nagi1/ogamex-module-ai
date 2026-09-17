# PHPOgameBot — Exhaustive Research Report

Repository: `racinmat/PHPOgameBot` (PHP 97.2%, ~10 years old; last commit "Update readme.md" 7 years ago, single contributor `racinmat`). No releases; 8 stars, 3 forks. This is a **Selenium-driven, DOM-scraping** bot for the Czech server `www.ogame.cz` — it drives a real Chrome browser and reads/writes game state from the DOM, with a MySQL/Doctrine store of scraped data.

## Overview

- Stack: **PHP + Nette Framework** (DI, presenters, Latte), **Codeception** + **Selenium 2 (2.53.0, chromedriver)** as the browser client, **Doctrine ORM (Kdyby\Doctrine)** on MySQL, **Symfony Console** for CLI commands, **Monolog** (file + Slack) for logging, **Carbon** for time, **ramsey/uuid**.
- Two parts:
  1. **Web admin** — Nette presenters (`Dashboard`, `AddCommand`, `ManageCommands`, `Farms`) to add/reorder/enable/disable/delete commands.
  2. **Bot** — reads command queues from JSON files, executes them through Chrome.
- Two command types (README, `readme.md`):
  - **Basic commands** — `www/queue.json`, executed once in order, removed after success.
  - **Repetitive commands** — `www/repetitive.json`, executed every run after basics, never removed.
- All game text/selectors are hardcoded in **Czech** (server is `ogame.cz`), e.g. building names `'Důl na Kov'`, free-build text `'Nestaví se žádné budovy.'`, fleet page heading `'Odeslání letky II'`.
- Command actions defined in `app/model/queue/command/ICommand.php`: `upgrade building`, `upgrade research`, `build defense`, `build ships`, `scan galaxy`, `probe players`, `probe farms`, `attack farms`, `send fleet`.

## Architecture & entry points

- **Entrypoint** `www/index.php` → Nette bootstrap → Symfony command `App\Commands\ProcessQueueCommand` (`bot:queue`).
- **`cron.php`** is a forever loop (`while (true) sleep(60)`):
  1. read `www/cron.txt` (next scheduled run); skip if empty;
  2. skip if `www/running.txt` is non-empty (lock/disabled flag — written by `DashboardPresenter::actionDisableBot` as `'stopped'`);
  3. `isConnectedToInternet()` = `@fsockopen('www.google.com', 80)`;
  4. if now ≥ timestamp, `shell_exec('php www/index.php bot:queue --debug-mode')`.
- **`app/commands/ProcessQueueCommand.php`**:
  - `executeDelegated()` → `SignManager::signIn()`; optional `--repeat X` wraps `process()` in an infinite loop with `sleep(60*X)`; else one `process()`; then `signOut()` + `cronManager->addNextPeriodicRun()`.
  - `process()` = `attackChecker->checkIncomingAttacks()` **before** queue → `queueConsumer->processQueue()` → `checkIncomingAttacks()` **after** queue.
- **`app/bootstrap.php`** wires `config.neon`; DI container resolves everything.
- **Command pattern**:
  - `ICommandProcessor` (4 methods): `canProcessCommand()`, `processCommand(): bool`, `getTimeToProcessingAvailable(): Carbon`, `isProcessingAvailable(): bool`.
  - `ICommandPreProcessor`: only implementation `UpgradeStoragesPreProcessor`.
  - `CommandDispatcher` discovers processors/preprocessors via `$container->findByType($interface)` and filters by `canProcessCommand()`.
- **Processors** (all implement `ICommandProcessor`): `Game\UpgradeManager` (buildings/research), `Game\BuildManager` (ships/defense), `Game\FleetManager` (send fleet), `Game\GalaxyBrowser` (scan galaxy), `Model\Prober`/`PlayersProber`/`FarmsProber`/`FarmsAttacker`.
- Commands are classes in `app/model/queue/command/`: `UpgradeBuildingCommand`, `UpgradeResearchCommand`, `BuildDefenseCommand`, `BuildShipsCommand`, `ScanGalaxyCommand`, `ProbePlayersCommand`, `ProbeFarmsCommand`, `AttackFarmsCommand`, `SendFleetCommand`. All extend `BaseCommand` (carries `Coordinates`, `Uuid`, `disabled` flag, dependency type).

## Scheduling & loop model

- **Next-run computation** (`QueueConsumer::resolveTimeOfNextRun()`): after processing, collect `failedCommands` + all repetitive commands; for each call `CommandDispatcher::getTimeToProcessingAvailable()` (skipped/`Carbon::maxValue()` if disabled or `isEvaluatedForNextRun() === false`); sort ascending; `CronManager::setNextStart(earliest)`.
- **Periodic floor** (`CronManager::addNextPeriodicRun()`): `now + interval + random(0..4 min) + random(0..59 s)`, where interval is **30 minutes** during the day and **1 h 55 m** between 01:00–07:00 (`getNextRunInterval()`), then `addNextStart()` keeps the min of the two candidate times.
- **Per-command availability time** (`FleetManager::getTimeToProcessingAvailable()`): `minimalTime = max(...)` over:
  - expedition return time if no free expedition slot (`getMyExpeditionsReturnTimes()` min);
  - my-fleet return time if no free fleet slot (`getMyFleetsReturnTimes()` min);
  - time-of-fleet-return for missing ships (`FleetInfo::getTimeOfFleetReturn(missingShips, planet)`);
  - `resourcesCalculator->getTimeToEnoughResources(...)` if `waitForResources()` (or `getTimeToEnoughResourcesTotal(planet, fleetCapacity)` if capacity is the binding constraint).
- **Upgrade time** (`UpgradeManager::getTimeToProcessingAvailable()`) = `max(getTimeToEnoughResourcesToEnhance(), planetManager->getTimeToFinish(upgradable))`.
- **Queue draining** (`QueueConsumer::processQueue()`): `refreshAllData()` → group basic queue by `getDependencyType()` (`resources`|`fleet`|`nothing`) → process each group FIFO; on the **first failure the loop `break`s** and the rest of that group is left for next run; on success `removeCommand(uuid)`. Repetitive commands are all attempted; failures skipped.
- `FleetManager::sendMultipleFleetsAtOnce()` (batch probe/attack): per flight, skip if cached (`uuid/toCoords` in Nette cache `processedFlights`), else `while (!isProcessingAvailable) { sleep(min(seconds, 60)) }`, then send.

## Decision engine & algorithms

- **Production per hour** (`app/model/ResourcesCalculator.php`, `acceleration = 3` from config):
  - metal = `3*30 + round(3*30*L*1.1^L) * (1 + plasma*0.01)`
  - crystal = `3*15 + round(3*20*L*1.1^L) * (1 + plasma*0.0066)`
  - deuterium = `round(3*10*L*1.1^L * (1.36 - 0.004*avgTemp)) * (1 + plasma*0.0033)`
- **Storage capacity** = `5000 * (int)(2.5 * e^(20*level/33))` per resource type.
- **Time-to-resources** = `missing / productionPerHour`, added to `planet->getLastVisited()` (uses last-visit timestamp, not `now`, as the production baseline).
- **Resource estimate at time t** = `production*hoursSinceLastVisit + current`, **capped at storage capacity** (`getResourcesEstimateForTime`).
- **Upgrade price** (`Upgradable::getPriceToNextLevel`) = `basePrice * priceConstant^currentLevel`. Constants (`Building`): metal mine 1.5, crystal mine 1.6, deuterium 1.5, solar 1.5, fusion 1.8, default 2. `Research`: astrophysics 1.75, default 2.
- **Espionage probe math** (`app/utils/OgameMath.php`):
  - `calculateEnemyLevel(myLevel, probes, result) = ceil(myLevel + sign(probes - result) * sqrt(abs(probes - result)))`
  - `calculateProbesToSend(myLevel, enemyLevel, desiredResult) = max(1, desiredResult - (myLevel - enemyLevel) * abs(myLevel - enemyLevel))`
  - `calculateProbesToGetAllInfo(...)` uses `desiredResult = 7` (GOT_ALL_INFORMATION).
- **Farm selection** (`PlanetCalculator::getFarms(limit, lastVisitedFrom, lastVisitedTo)`) = `DatabaseManager::getInactiveDefenselessPlanets(...)` → estimate resources → `uasort` desc by total → `array_slice` top N.
- **Cargo count to farm** = `ceil(resourcesTotal / 2 / shipCapacity)` — comment "Every attack takes only one half of resources"; after attack, `saveResourcesEstimateAfterAttack()` halves the stored estimate.
- **Fleet capacity** = Σ(ship capacity × count); capacities hardcoded in `Ships::getCapacity()` (small cargo 5000, large cargo 25000, light fighter 50, heavy fighter 100, cruiser 800, battleship 1500, battlecruiser 750, destroyer 2000, deathstar 1000000, bomber 500, recycler 20000, probe 5, satellite 0, colony ship 7500).
- **Fleet base speeds** (`Ships::getBaseSpeed()`): small cargo 5000, large cargo 7000, light fighter 12500, heavy fighter 10000, cruiser 15000, battleship 10000, battlecruiser 10000, destroyer 5000, deathstar 100, bomber 4000, recycler 2000, probe 100000000, satellite 0, colony ship 2500. (Used only to define `getMovingShips()`; no flight-time computation was found.)

## Data model & persistence

- **Queues**: JSON files on disk (`www/queue.json`, `www/repetitive.json`) via `QueueFileRepository`; each command serializes through `toArray()`/`fromArray()`.
- **Scheduling/state files**: `www/cron.txt` (next run timestamp), `www/running.txt` (lock/stopped flag).
- **Doctrine entities** (`app/model/entity/`):
  - `Planet` (21 KB): name, coordinates, all 14 building levels, all 13 ship amounts, all 10 defense amounts, metal/crystal/deuterium, debris metal/crystal, min/max temperature, moon flag, `lastVisited`.
  - `Player`: all 15 research levels, `status` (`PlayerStatus`), alliance, `probingStatus` (`ProbingStatus`), `probesToLastEspionage`, name.
- Custom Doctrine types: `CarbonDateTimeType`, `EnumType` + `PlayerStatusType`/`ProbingStatusType`/`PlanetProbingStatusType`.
- `DatabaseManager` provides queries like `getInactiveDefenselessPlanets(lastVisitedFrom, lastVisitedTo, ...)`, `getAllPlayers()`, `getPlanet(coords)`, `removePlanetsInSystemExceptOf(...)`.
- `PlanetManager::refreshAllData()` scrapes every my-planet's buildings + resources and every research level from the DOM on each run; temperature loaded once.

## Config surface

- `app/config/config.neon` (parameters + services):
  - `acceleration: 3`, `cronFile/runningFile/queueFile/repetitiveCommandsFile` → `%wwwDir%/...`.
  - `php.date.timezone: Europe/Prague`, `session.expiration: 14 days`.
  - Monolog: `SlackHandler(%slackApiToken%, %slackChannel%, ogameBot, true, null, ALERT, true)` + `FingersCrossedHandler(RotatingFileHandler(log/bot.log, 30, DEBUG))`.
  - Console commands registered: `TestCommand`, `ProcessQueueCommand`, `RecalculateProbesCommand`.
- `app/config/config.local.neon.dist`: `user`, `password`, `slackApiToken`, `slackChannel`, doctrine `host/user/password/dbname` (all `####`).
- No per-universe or per-object configuration; everything else is code.

## Edge cases & failure handling

- **Attack response** (`AttackChecker::attackDetected()`): on any incoming hostile non-returning flight, take the attacked planet's **entire present fleet** and send as `DEPLOYMENT` to the **first other planet** with `Resources(100000000, 100000000, 100000000)` (hardcoded "fill with everything"). Logs `'Fleetsave done.'` / `'Fleetsave failed.'`.
- **Flight cache invalidation** (`FleetInfo::getFlights()`): flights reloaded if older than **3 minutes**; `waitUntilCloseFlightsArrive()` loops and sleeps until no flight is within 1 minute of arrival (avoids parsing a flight that disappears mid-read).
- **Sign-out / stale session**: `FleetManager::processCommand()` catches `NoSuchElementException` and calls `SignManager::checkSignedIn()`; `GalaxyBrowser::scanSystem()` catches `TimeOutException` → reload, re-sign in if needed, retry system.
- **Non-existent planet**: `FleetManager` throws `NonExistingPlanetException` on timeout at "Odeslání letky III"; batch sender optionally removes non-existing planets.
- **Galaxy scan** deletes planets in a system that are no longer present (`removePlanetsInSystemExceptOf`), and skips my own planets.
- **Probing** sleeps a **fixed 40 seconds** after sending probes (`sleep(40)` with a `//todo: wait until probes return`), then reads reports newer than the probing start.
- **Report parsing** (`ReportReader`): per section (fleet/defense/buildings/research), a missing section (detected via `li.detail_list_fail`) lowers the player's `ProbingStatus` to the corresponding `min(...)`; page numbering mismatches are recovered by `goToReport($i)`.
- **Batch wait cap**: per-flight wait is clamped to 60 s.

## Anti-detection & authenticity

- **Jittered sleeps between DOM actions** via `Random::microseconds(a, b)` (i.e. `random_int(a*1e6, b*1e6)`), used pervasively with small ranges: 0.2–0.4 s, 0.5–1 s, 1–2 s, 1.5–2.5 s, 2–2.5 s.
- **Next-run jitter**: `+ random_int(0,4) minutes + random_int(0,59) seconds`.
- **"Fast" fleet sending** (`SendFleetCommand::fast`, `FleetManager::sendFleet()`): builds the fleet URL via GET parameters (`am{shipsNumber}=count`, `galaxy`, `system`, `position`, `type`, `mission`, `speed/10`) and calls `amOnUrl(...)` — bypasses the human click flow entirely; resources cannot be set this way.
- **`PlayerStatus` re-check** before probing: `FleetManager::sendFleet()` reads the target's status class and aborts if it is not in the command's allowed `statuses` (inactive / long inactive).
- The README TODO explicitly lists the intended-but-absent humanization: "randomize intervals, set how slow or big should be waiting between actions (slider more bot - more human)" — **not implemented**. A fixed `sleep(40)` and 3-minute flight cache are the only coarse timing behaviors.

## Discrete mechanisms

1. **M01 — Periodic run floor** — next run ≥ `now + (30 min day | 1h55m night 01:00–07:00) + rand(0–4 min) + rand(0–59 s)` (`CronManager::addNextPeriodicRun`, `getNextRunInterval`).
2. **M02 — Scheduler earliest-gate** — next run = min over failed + repetitive commands of each `getTimeToProcessingAvailable()`; disabled / `isEvaluatedForNextRun()===false` → `Carbon::maxValue()` (`QueueConsumer::resolveTimeOfNextRun`, `CommandDispatcher::getTimeToProcessingAvailable`).
3. **M03 — Basic queue FIFO with break** — commands grouped by dependency type, processed in order; first failure breaks that group, leaving the rest queued (`QueueConsumer::processQueue`).
4. **M04 — Repetitive skip-continue** — each repetitive command attempted every run; failures skipped, never removed (`QueueConsumer::processQueue`, `QueueManager`).
5. **M05 — Fleetsave on attack** — incoming hostile non-returning flight → send all present fleet + `Resources(100e6,100e6,100e6)` as DEPLOYMENT to first non-attacked colony (`AttackChecker::attackDetected`).
6. **M06 — Flight cache TTL** — flights reloaded if older than 3 minutes (`FleetInfo::getFlights`).
7. **M07 — Near-arrival parse guard** — loop-wait until no flight is within 1 minute of arrival before parsing (`FleetInfo::waitUntilCloseFlightsArrive`).
8. **M08 — Free-slot check** — fleet send requires `getFreeSlotsCount() > 0`; expeditions additionally require free expedition slot (`FleetManager::areFreeFleets`, `areFreeExpeditions`, `isProcessingAvailable`).
9. **M09 — Capacity vs resources** — if fleet capacity < requested resources, the binding condition is total-resources ≥ fleet capacity, not the command's resources (`FleetManager::isProcessingAvailable`).
10. **M10 — Ship present check** — fleet not sent unless present fleet `contains(command fleet)` (`FleetManager::isFleetPresent`).
11. **M11 — Fast GET-URL fleet send** — `type=1` planet, `type=2` debris; `speed/10`; `am{shipNo}` params (`FleetManager::sendFleet`).
12. **M12 — Expedition target** — position forced to `16` for expedition missions (`FleetManager::sendFleet`).
13. **M13 — Storage preprocessor** — if `buildStoragesIfNeeded` and price exceeds storage, compute minimal storage levels and prepend `UpgradeBuildingCommand`s for metal/crystal/deuterium storages, sorted by price, before the command (`UpgradeStoragesPreProcessor::preProcessCommand`).
14. **M14 — Storage capacity formula** — `5000 * (int)(2.5 * e^(20*level/33))` (`ResourcesCalculator::getStorageCapacity`).
15. **M15 — Minimal storage levels** — increment level while capacity < required resource amount (`ResourcesCalculator::getMinimalStorageLevelsForResources`).
16. **M16 — Production formulas** — metal `accel*30 + round(accel*30*L*1.1^L)*(1+plasma*0.01)`; crystal `accel*15 + round(accel*20*L*1.1^L)*(1+plasma*0.0066)`; deut `round(accel*10*L*1.1^L*(1.36-0.004*avgTemp))*(1+plasma*0.0033)` (`ResourcesCalculator`).
17. **M17 — Time-to-resources baseline** — hours = missing ÷ production, anchored at `planet->lastVisited` (not `now`) (`ResourcesCalculator::getTimeToResources`, `addHours`).
18. **M18 — Resource estimate capped** — `production * hoursSinceLastVisit + current`, clamped to storage capacity (`ResourcesCalculator::getResourcesEstimateForTime`).
19. **M19 — Upgrade price** — `basePrice * priceConstant^currentLevel` (`Upgradable::getPriceToNextLevel`).
20. **M20 — Price growth constants** — metal/crystal/deut/solar 1.5, crystal mine 1.6, fusion 1.8, default 2; astrophysics 1.75, default 2 (`Building`, `Research`).
21. **M21 — Enemy espionage level estimate** — `ceil(myLevel + sign(probes-result)*sqrt(|probes-result|))` (`OgameMath::calculateEnemyLevel`).
22. **M22 — Probes to send** — `max(1, desiredResult - (myLevel-enemyLevel)*|myLevel-enemyLevel|)` (`OgameMath::calculateProbesToSend`).
23. **M23 — Probe escalation** — first probe = 1; if not GOT_ALL, recompute from last probes/status; target statuses = inactive + long-inactive (`Prober::createEspionageCommands`).
24. **M24 — Fixed probe wait** — `sleep(40)` after sending probes, then read reports since probing start (`Prober::probePlanets`).
25. **M25 — Farm ranking** — inactive defenseless planets, estimate resources, sort desc by total, take top `limit` (`PlanetCalculator::getFarms`).
26. **M26 — Farm cargo count** — `ceil(total/2/shipCapacity)` (loot = half) (`PlanetCalculator::countShipsNeededToFarmResources`).
27. **M27 — Post-attack estimate** — halve stored resource estimate after attacking farms (`PlanetCalculator::saveResourcesEstimateAfterAttack`).
28. **M28 — Batch send cache** — skip already-sent flight by `uuid/coords` cache key; wait per flight ≤ 60 s (`FleetManager::sendMultipleFleetsAtOnce`).
29. **M29 — Probe report thresholds** — minimal result: missing fleet 1, missing defense 2, missing buildings 3, missing research 5, got-all 7; maximal result 1/2/4/6/7 (`ProbingStatus`).
30. **M30 — Report section detection** — `li.detail_list_fail` marks a missing section → `probingStatus = probingStatus->min(section status)` (`ReportReader::readSection`, `readCurrentEspionageReport`).
31. **M31 — Galaxy scan range** — iterate systems from `from` to `to` coordinates, right-arrow for next system, re-login on timeout, delete vanished planets (`GalaxyBrowser`).
32. **M32 — Fleet mission numbers** — expedition 15, colonization 7, harvesting 8, transport 3, deployment 4, espionage 6 (+ attacking/destroy constants, number mapping truncated in capture) (`FleetMission`).
33. **M33 — Ship numbers** — small cargo 202, large cargo 203, LF 204, HF 205, cruiser 206, BS 207, colony 208, recycler 209, probe 210, bomber 211, satellite 212, destroyer 213, deathstar 214, battlecruiser 215 (`Ships`).
34. **M34 — Building numbers** — metal 1, crystal 2, deut 3, solar 4, fusion 12, robotic 14, nanite 15, shipyard 21, metal store 22, crystal store 23, deut tank 24, research lab 31, terraformer 33, alliance depot 34, missile silo 44 (`Building`).
35. **M35 — Defense CSS numbers** — 401–408 launchers/lasers/shields, 502 ABM, 503 IPM (`Defense`).
36. **M36 — Research CSS numbers** — espionage 106, computer 108, weapon 109, shielding 110, armour 111, energy 113, hyperspace 114, combustion 115, impulse 117, hyperdrive 118, laser 120, ion 121, plasma 122, IRN 123, astro 124, graviton 199 (`Research`).
37. **M37 — Inactive target statuses** — farming/probing restricted to `inactive` + `long inactive`; re-checked at send time against the target's live status class (`FarmsProber`, `FarmsAttacker`, `FleetManager::sendFleet`).
38. **M38 — Human jitter** — `Random::microseconds(a,b)` sleeps between every UI step (ranges 0.2–2.5 s) (`app/utils/Random.php`, call sites throughout).

## Notable concerns

- **Gate 1 (no static/hardcoded AI) — massively violated.** The entire object universe — building/ship/defense/research **ids, base prices, price-growth constants, ship capacities, base speeds, translated names, and CSS selectors** — is hardcoded in `app/model/enum/*.php` as switch statements. Adding a host object requires editing this module (the exact anti-pattern this gate forbids). There is no host-data source.
- **Gate 2 (simple, never over-engineered) — mostly fine, with one caveat.** The command/dispatcher/processor design is proportionate. But `Planet` and `Player` entities enumerate every single object as a separate typed property + getter/setter (60+ columns), and each enum mirrors this with a `switch` per method (`getCurrentLevel`, `setCurrentLevel`, `getPrice`, `getNumber`, `getFromTranslatedName`) — high repetition, low abstraction. The `ICommand`→`ICommandProcessor` + `ICommandPreProcessor` split is reasonable. Net positive on simplicity, but the data modeling is brittle and verbose.
- **Gate 3 (what a good human player does) — multiple failures:**
  - Fleetsave is hardcoded: **all** fleet to the **first** other colony, with **100,000,000** of each resource — an obviously scripted, lossy reaction a human would not do (no recall, no colony selection, no moon/debris nuance).
  - Fixed `sleep(40)` after probing and 3-minute flight-cache TTL are mechanical, not human.
  - `fast` fleet sending via GET URL bypasses the normal UI (no human does this).
  - Farms are attacked purely by estimated-resource ranking, no deuterium budget, no ratio/predictability modeling (all listed as TODO).
  - No fleetsave from main planet, no resource-reservation, no "leave N minutes before attack" — all TODO.
- **Other**: Czech-only selectors make the bot server-locked and locale-locked; production formula has a likely copy-paste bug (`PlasmaTech`ologyLevel` typo visible in `getProductionPerHour`); `countShipsNeededToFarmResources` ignores `getCapacity()` rounding of loot rules beyond the /2 assumption; `isProcessingAvailable` for `FarmsProber`/`PlayersProber`/`GalaxyBrowser` returns `true` unconditionally; no unit coverage of decision logic beyond `ResourcesCalculator`/`OgameParser`/`Strings`.

## Confidence

**High.** The README and every core source file were read directly from the repository (enums `Building/Ships/Defense/Research/FleetMission/PlayerStatus/ProbingStatus/Upgradable`, `ResourcesCalculator`, `PlanetCalculator`, `OgameMath`, `CronManager`, `QueueConsumer`, `QueueManager`, `CommandDispatcher`, `UpgradeStoragesPreProcessor`, `FleetManager`, `FleetInfo`, `GalaxyBrowser`, `ReportReader`, `PlanetManager`, `EnhanceManager`/`BuildManager`/`UpgradeManager`, `Prober`/`PlayersProber`/`FarmsProber`/`FarmsAttacker`, `AttackChecker`, `Fleet`/`Flight`, `config.neon`, `cron.php`, and the full git tree). Snippets were retrieved via the GitHub API with line numbers; the only uncertain detail is the attacking/destroy mission-number mapping (source excerpt truncated after `6 => espionage`), noted as such in M32. Some long files were read partially (e.g. `Planet`, `Player`, `DatabaseManager`, `AddCommandPresenter` bodies), so a few minor helper behaviors may be omitted, but no decision rule is invented.