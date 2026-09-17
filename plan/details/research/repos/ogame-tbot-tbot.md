# TBot — OGame bot (`ogame-tbot/TBot`) — Research Report

> C# / .NET 6 bot built on top of `ogamed` (Go daemon by `alaingilbert`), supporting OGame v11.15. Latest release v0.3.4 (~2 years ago), 96 stars. Repo layout: `TBot` (console app), `TBot.Common` (logging/settings), `TBot.Ogame.Infrastructure` (models + ogamed HTTP client), `TBot.WebUI` (Blazor/Razor UI), `Docker`, `ogame` (ogamed submodule).

## Overview
- A headless OGame "manager": login via ogamed, then a set of timer-driven `Worker`s automate defender/fleetsave, expeditions, mining/research ("Brain"), farming inactives, harvesting, colonizing, auto-discovery, sleep windows, plus Telegram remote control and a WebUI proxy.
- All game state flows through `ogamed` (a Go REST daemon spawned as a child process); TBot talks to it over HTTP at `http://host:port/` and never touches the OGame web API itself.
- Persistence is effectively **none of its own**: credentials/session/cookies live in ogamed's `.ogame` folder; config is JSON; all runtime state is in-memory (`UserData`) and lost on restart.

## Architecture & entry points
- Entry: `TBot/Program.cs` — `static void Main(string[] args) { MainAsync(args).Wait(); }`. `MainAsync` builds the DI container, reads settings (`--settings=<path>` CLI arg), creates `_instanceManager`, starts `TBot.WebUI` if enabled, then `await tcs.Task` (block until cancelled), finally `DisposeAsync()`.
- `TBot/Services/InstanceManager.cs` — owns the set of live instances. `OnSettingsChanged()` reads `settings.json`, detects **single-instance** (no `Instances` key) vs **multi-instance** (`Instances[]`), and diffs against running instances: de-init removed, init added. `StartTBotMain()` creates a DI scope and calls `tBotInstance.Init(settingsPath, alias, telegramMessenger)`.
- `TBot/Services/TBotMain.cs` — the per-account orchestrator (huge, ~1400+ lines). `Init()` → load settings → `InitializeOgame()` (spawn ogamed with credentials/device/proxy/user-agent) → `Login()` (captcha fallback) → `InitUserData()` → `InitializeSleepMode()` → install `SettingsFileWatcher` (hot reload). `DisposeAsync()` stops workers, logs out, `KillOgamedExecutable()`.
- `TBot/Workers/WorkerFactory.cs` — maps `Feature` → worker class; `IsBrain()` brain features all share one `SemaphoreSlim _brain = new SemaphoreSlim(1,1)` (serializes brain features per instance).
- `Feature` enum (`TBot.Ogame.Infrastructure/Enums/Feature.cs`): `Defender=1, BrainAutobuildCargo=2, BrainAutoRepatriate=3, BrainAutoMine=4, BrainOfferOfTheDay=5, Expeditions=6, Harvest=7, SleepMode=8, BrainAutoResearch=9, Colonize=10, AutoFarm=11, BrainLifeformAutoMine=12, BrainLifeformAutoResearch=13, AutoDiscovery=14`; celestial workers `101..103`. `Features.AllFeatures` lists the 14.
- `TBot/Workers/TBotOgamedBridge.cs` — wrapper of `IOgameService` with fallback-on-exception semantics (`UpdatePlanet`, `UpdateCelestials`, `CheckCelestials`, `GetDateTime`, `UpdateSlots`, …).
- `TBot.Ogame.Infrastructure/OgameService.cs` — HTTP client to ogamed (`GetAsync`/`PostAsync` to `/bot/*`), process spawn/kill, Polly retry.

## Scheduling & loop model
- `TBot/Workers/WorkerBase.cs` — base class; `StartWorker(ct, period, dueTime)` runs an `AsyncTimer` (`TBot/Includes/AsyncTimer.cs`) whose callback is `ExecutionWrapper` → abstract `Execute()`.
- `AsyncTimer` caps `dueTime`/`period` at `TimeSpan.FromDays(1)` (`EnsureMaximumTimeSpan`) and supports `ChangePeriod`/`ChangeDueTime`.
- After each run a worker computes the **next interval dynamically** and calls `ChangeWorkerPeriod(interval)`. Intervals come from three sources:
  1. `RandomizeHelper.CalcRandomInterval(min, max)` from `CheckIntervalMin/Max` (settings values are **minutes**): `rand.Next(min*60*1000, max*60*1000)`.
  2. Event-anchored waits: next fleet return `(BackIn ?? 0) * 1000 + jitter`, construction `BuildingCountdown*1000 + jitter`, research `ResearchCountdown*1000 + jitter`.
  3. Fixed `IntervalType` jitter buckets.
- `TBot/Helpers/RandomizeHelper.cs` — `CalcRandomInterval(IntervalType)`:
  `LessThanASecond→500..1000`, `LessThanFiveSeconds→1000..5000`, `AFewSeconds→5000..15000`, `SomeSeconds→20000..50000`, `AMinuteOrTwo→40000..140000`, `AboutFiveMinutes→240000..360000`, `AboutTenMinutes→540000..720000`, `AboutAQuarterHour→840000..960000`, `AboutHalfAnHour→1500000..2100000`, `AboutAnHour→3000000..42000000` (≈50 min–11.7 h — an apparent copy/paste bug), default `500..1000` (all ms).
- `CalcRandomIntervalSecToMs(min,max)` → `rand.Next(min*1000, max*1000)` (seconds in).
- Sleep mode runs on its own `System.Threading.Timer` in `TBotMain` (`HandleSleepModeAsync`), not a worker; `Feature.SleepMode` is "always enabled" (`InitializeFeature` early-returns for it).

## Decision engine & algorithms
Central file: `TBot/Includes/CalculationService.cs` (~3600 lines; interface `ICalculationService`). Formulas:
- **Distance** (`CalcDistance`): same coordinate → `5`; same system → `1000 + 5*|Δposition|`; same galaxy → `2700 + 95*min(|Δsys|, wrap)` (donut); cross-galaxy → uses `CalcGalaxyDistance` with `donutGalaxy` flag (galaxy constant not directly observed in excerpts; standard OGame is `20000 + 95*|Δgalaxy|`).
- **Flight time** (`CalcFlightTime`): `t = (35000/s * sqrt(d*10/v) + 10) / a`, where `s`=speed (10=100%), `v`=slowest ship speed, `a`=universe fleet speed, `d`=distance. Mission speed class: war (`Attack/Spy/Harvest/Destroy`), holding (`FederalDefense`), peaceful (rest).
- **Fuel** (`CalcFuelConsumption`): per ship `Σ consumption*qty*distance/35000 * ((tempSpeed/10)+1)²`, `+1` at the end. Base consumption constants in `CalcShipConsumption`: SmallCargo 20 (×2 if ImpulseDrive≥5), LargeCargo 50, LightFighter 20, HeavyFighter 75, Cruiser 300, Battleship 500, Deathstar 1, Battlecruiser 250, Reaper 1100, Pathfinder 300, …; scaled by `serverData.GlobalDeuteriumSaveFactor`; General class halves fuel.
- **Cargo capacity** (`CalcShipCapacity`): base cargo per ship (SmallCargo 5000, LargeCargo 25000, LF 50, HF 100, Cruiser 800, BS 1500, Colony 7500, Recycler 20000, Probe = `serverData.ProbeCargo`, Deathstar 1,000,000, Battlecruiser 750, Reaper 10000, Pathfinder 10000); bonus = `hyperspaceTech * serverData.CargoHyperspaceTechMultiplier` (+25 Collector for SC/LC, +20 General recycler, +25 General pathfinder); `cargo = baseCargo*(bonus+100)/100`.
- **Fleet prediction** (`CalcFleetPrediction`) → `{Fuel, Time}`; used before every `SendFleet`.
- **Optimal farm speed** (`CalcOptimalFarmSpeed`): iterate class-valid speeds; pick the **fastest** speed with `Fuel < loot.ConvertedDeuterium * ratio` and `Time < maxFlightTime`; else 100%.
- **Max transportable** (`CalcMaxTransportableResources`): fill capacity deut-first, then crystal, then metal.
- **Mining ROI**: `CalcROI = Δprod/cost`; `CalcDaysOfInvestmentReturn = cost / (nextOneDayProd − currentOneDayProd)` using per-day production (`metal/2.5*24`, `crystal/1.5*24`, deuterium `*24`). `CalcNextDaysOfInvestmentReturn = min(metalDOIR, crystalDOIR, deuteriumDOIR)`; also `CalcNextAstroDOIR`, `CalcNextPlasmaTechDOIR`.
- `GetNextMineToBuild`: if `optimizeForStart` and (`MetalMine<10 || CrystalMine<7 || DeutSynth<5`) → `MetalMine` if `metal ≤ crystal+2`, else `CrystalMine` if `crystal ≤ deut+2`, else `DeuteriumSynthesizer`; otherwise compute DOIR for each eligible mine, pick minimum, then require `maxDaysOfInvestmentReturn ≥ DOIR` (default 36500).
- `GetNextEnergySourceToBuild`: SolarPlant first; FusionReactor if DeutSynth≥5; else SolarSatellite (needs Shipyard≥1, else RoboticsFactory≥2, else RoboticsFactory).
- **Expedition fleet** (`CalcIdealExpeditionShips`): `freightCap` from `serverData.TopScore` tiers — `<10k→40000`, `<100k→500000`, `<1M→1200000`, `<5M→1800000`, `<25M→2400000`, `<50M→3000000`, `<75M→3600000`, `<100M→4200000`, else `5000000`; then Discoverer `×ecoSpeed×3`, else `×2`; add `expeditionResourcesBonus` %; `cargoNumber = ceil(freightCap / oneCargoCapacity)`. `CalcFullExpeditionShips` adds 1 probe, 1 best military ship (`CalcMilitaryShipForExpedition`: Reaper→Destroyer→Bomber→Battlecruiser→Battleship→Pathfinder→Cruiser→HeavyFighter→LightFighter), and 1 Pathfinder if available.
- Defender decisions in `TBot/Workers/DefenderWorker.cs` (see Discrete mechanisms).

## Data model & persistence
- `TBot/Services/UserData.cs`: `Server serverInfo`, `ServerData serverData`, `UserInfo userInfo`, `AllianceClass allianceClass`, `List<Celestial> celestials`, `List<Fleet> fleets`, `List<AttackerFleet> attacks`, `Slots slots`, `Researches researches`, `List<FleetSchedule> scheduledFleets`, `List<FarmTarget> farmTargets`, `Dictionary<Coordinate,DateTime> discoveryBlackList`, `float lastDOIR/nextDOIR`, `Staff staff`, `bool isSleeping`. All in-memory.
- Models (`TBot.Ogame.Infrastructure/Models`): `Fleet` (ID, Mission, ReturnFlight, InDeepSpace, Origin/Destination, ArriveIn/BackIn, ArrivalTime/BackTime), `FleetHypotesis` (Origin/Destination/Ships/Mission/Speed/Duration/Fuel), `FleetSchedule : FleetHypotesis` (+Payload, Departure/Arrival/Comeback/SendAt/RecallAt/ReturnAt), `Ships` (17 ship properties + `GetFleetPoints()`, `GetMovableShips()`, `IsOnlyProbes()`), `EspionageReport` (`IsDefenceless()`, `Loot(class)`), `AttackerFleet` (`IsOnlyProbes()`), `ServerData` (universe parameters), `AutoMinerSettings` (defaults: `MaxDaysOfInvestmentReturn=36500`, `DepositHours=6`, `BuildSolarSatellites=true`, `DeutToLeaveOnMoons=1000000`).
- **No database**; `ServerData` is fetched at runtime from ogamed (`/bot/server-data`) — the universe profile (speeds, galaxies/systems, donut flags, `TopScore`, `ProbeCargo`, `CargoHyperspaceTechMultiplier`, `GlobalDeuteriumSaveFactor`, `Bashlimit`, `NewbieProtection*`, `ResearchDurationDivisor`, `DebrisFactor`…) is **host-supplied, not hardcoded**.
- `FarmState` enum (`Enums/FarmState.cs`): `Idle, ProbesPending, ProbesSent, ProbesRequired, FailedProbesRequired, AttackPending, AttackSent, NotSuitable`.

## Config surface
- **settings.json** (global): `TelegramMessenger{Active, API, ChatId, TelegramAutoPing{Active,EveryHours}}`, `Instances[{Alias, Settings}]`, `WebUI{Enable, Urls, MaxLogsToShow}`. Passed via `--settings=`.
- **instance_settings.json** (per account; dynamic object, validated lazily by `SettingsService.IsSettingSet`): `Credentials{Universe, Email, Password, Language, LobbyPioneers, BasicAuth{Username,Password}, DeviceConf{UserAgent…}}`, `General{Host, Port, SlotsToLeaveFree}`, `Defender{Active, CheckIntervalMin/Max, WhiteList, IgnoreProbes, IgnoreWeakAttack, WeakAttackRatio, IgnoreAttackIfIHave{Active,MinResourcesToSave,MinFleetToSave}, SpyAttacker{Active,Probes}, MessageAttacker{Active,Messages[]}, Autofleet{Active}, Alarm{Active}, RandomActivity, Home{Galaxy,System,Position,Type}, DefendFromMissiles, TelegramMessenger{Active}}`, `SleepMode{Active, GoToSleep, WakeUp, PreventIfThereAreFleets, AutoFleetSave{Active, DefaultMission, DeutToLeave, Recall, OnlyMoons}, TelegramMessenger{Active}}`, `Expeditions{Active, CheckIntervalMin/Max, PrimaryShip, SecondaryShip, PrimaryToKeep, MinPrimaryToSend, MinSecondaryToSend, SecondaryToPrimaryRatio, ManualShips{Active,Ships{…17 fields…}}, RandomizeOrder, Origin[], WaitForAllExpeditions, WaitForMajorityOfExpeditions, MinWaitNextRound, MaxWaitNextRound, MinWaitNextFleet, MaxWaitNextFleet, FuelToCarry}`, `Brain{Active, Transports{Origin, RoundResources, CargoType}, AutoCargo{Active, CheckIntervalMin/Max, Exclude[], RandomOrder, …}, AutoRepatriate{Active, CheckIntervalMin/Max, Target, MinimumResources, ExcludeMoons, LeaveDeut{DeutToLeave, OnlyOnMoons}, CargoType}, AutoMine{Active, CheckIntervalMin/Max, RandomOrder, Exclude[], MaxMetalMine…MaxDeuteriumTank, MaxRoboticsFactory…MaxSpaceDock, MaxLunarBase/MaxLunarShipyard/MaxLunarRoboticsFactory/MaxSensorPhalanx/MaxJumpGate, MaxDaysOfInvestmentReturn, PrioritizeRobotsAndNanites, DepositHours, BuildDepositIfFull, DeutToLeaveOnMoons, BuildSolarSatellites}, AutoResearch{Active, Target, MaxEnergyTechnology, MaxLaserTechnology, MaxIonTechnology, MaxHyperspaceTechnology, MaxPlasmaTechnology, MaxCombustionDrive, MaxImpulseDrive, MaxHyperspaceDrive, MaxEspionageTechnology, MaxComputerTechnology, MaxAstrophysics, MaxIntergalacticResearchNetwork, MaxWeaponsTechnology, MaxShieldingTechnology, MaxArmourTechnology, OptimizeForStart, EnsureExpoSlots}, LifeformAutoMine{Active, Exclude[], MaxT2Building, MaxT3Building, MaxBuilding1..12}, LifeformAutoResearch{Active, MaxTechs1..31…}, BuyOfferOfTheDay{Active, CheckIntervalMin/Max}}`, `AutoFarm{Active, CheckIntervalMin/Max, ScanRange[], NumProbes, SlotsToLeaveFree, MaxSlots, PreferedResource, Origin[], Exclude[], ExcludeMoons, MinLootFuelRatio, MaxFlightTime, FleetSpeed, MinCargosToKeep, MinCargosToSend, CargoType, TargetsProbedBeforeAttack}`, `AutoColonize{Active, Origin, Targets[], Abandon{Active, MinFields, MinTemperatureAcceptable, MaxTemperatureAcceptable}, IntensiveResearch{Active, MaxSlots, MinWaitNextFleet, MaxWaitNextFleet}}`, `AutoHarvest{Active, CheckIntervalMin/Max}`, `AutoDiscovery{Active, CheckIntervalMin/Max, Origin, MaxSlots}`, proxy (`type=socks5|http`, address, username/password, login-only).
- Hot reload via `SettingsFileWatcher` (`TBotMain.OnSettingsChanged`) and instance add/remove (`InstanceManager.OnSettingsChanged`).

## Edge cases & failure handling
- Every worker `Execute()` is wrapped in `try/catch`: logs `"{feature} exception: {msg}"` + stacktrace, reschedules with a random interval, and calls `_tbotOgameBridge.CheckCelestials()`.
- `FleetScheduler.SendFleet` guards (returns `SendFleetCode` enum): no movable ships; expedition with only probes; origin==destination; destination galaxy out of `serverData.Galaxies` or position `>17`; fleet fuel capacity `< predicted fuel`; sleep-window collision (`returnTime >= goToSleep && returnTime <= wakeUp` → `AfterSleepTime`); no free slots or `Free <= SlotsToLeaveFree` (unless `force`).
- `OgameService.GetRetryPolicy()`: Polly `WaitAndRetryAsync(3, r => 2^r seconds)` on transient HTTP errors.
- ogamed process lifecycle: `RerunOgamed()`, `KillOgamedExecutable()`; `OnError` event → `InstanceManager.TBotInstance_OnError` removes the instance; `ValidatePrerequisites()` checks the `ogamed` binary exists.
- Sleep mode edge cases: `GoToSleep == WakeUp` → disable sleep mode; `PreventIfThereAreFleets` delays going to sleep until all non-discovery fleets have returned.
- AutoDiscovery blacklist: failed dest blacklisted `+1 day`, successful `+7 days`; stops when galaxy exhausted.
- `GetNeededProbes`: `NumProbes ×3` (ProbesRequired), `×9` (FailedProbesRequired).
- AutoFarm: retries `NotEnoughSlots` up to `maxRetryCount`; waits for spy/attack fleets to return; skips celestials mid-upgrade (Shipyard/NaniteFactory); builds probes/cargo on demand.
- `FleetScheduler` holds a `_fleetLock` object; `CancelFleet` inserts `AFewSeconds` random delays around the recall.

## Anti-detection & authenticity
- Randomization everywhere via `RandomizeHelper` (interval buckets above; jitter on fleet send/recall; `Task.Delay` between actions; random expedition destinations `rand.Next(system−range, system+range+1)`).
- `DefenderWorker.FakeActivity()` — deliberately opens the account from the configured home celestial (or a random celestial if `RandomActivity`) to mimic a player checking in.
- `SleepMode` — night window with auto-fleetsave; wake at random offset; features suppressed while `isSleeping`.
- Proxy support (HTTP/SOCKS5) with README warning "Ogame is positively blocking IPs from datacenters… residential proxy"; per-instance user-agent via `SetUserAgent`; cookie file sharing across same-lobby instances.
- Captcha: built-in `OgameCaptchaSolver` (a very large MD5-image-hash → "the Object" table), plus manual (`host:port/bot/captcha`) and Ninja Autoresolve.
- `MessageAttacker` picks a random string from a configured array.
- Disclaimer in README: "Scripting and botting are forbidden by the OGame rules… I cannot, and never will, guarantee anything."

## Discrete mechanisms
- M01 — **Worker loop** — every worker reschedules itself via `ChangeWorkerPeriod(interval)` after `Execute()` (`TBot/Workers/WorkerBase.cs`).
- M02 — **Config-driven interval (minutes)** — `RandomizeHelper.CalcRandomInterval(min,max) = rand.Next(min*60000, max*60000)` (`TBot/Helpers/RandomizeHelper.cs`).
- M03 — **IntervalType jitter ladder** — exact ms ranges listed in Scheduling section (`RandomizeHelper.CalcRandomInterval(IntervalType)`).
- M04 — **Sleep truth table** — `TBotMain.HandleSleepModeAsync` 8-case `time/goToSleep/wakeUp` matrix with `+AMinuteOrTwo` jitter (`TBot/Services/TBotMain.cs`).
- M05 — **Attack poll** — `IsUnderAttack()` → optional `PlayAlarm()` → `GetAttacks()` → `HandleAttack` each (`TBot/Workers/DefenderWorker.cs`).
- M06 — **Fake activity** — open from `Defender.Home` (or random celestial) to generate plausible account activity (`DefenderWorker.FakeActivity`).
- M07 — **Whitelist** — skip attack if `attack.AttackerID ∈ Defender.WhiteList` (`DefenderWorker`).
- M08 — **Ignore probes** — if `EspionageTechnology ≥ 8`, `IgnoreProbes` and `attack.IsOnlyProbes()` → skip (`DefenderWorker`).
- M09 — **Ignore weak attack** — skip if `attack.Ships.GetFleetPoints() < attackedCelestial.Ships.GetFleetPoints() / WeakAttackRatio` (`DefenderWorker`).
- M10 — **Ignore if nothing to save** — skip if `TotalResources < MinResourcesToSave` **and** `fleetPoints*1000 < MinFleetToSave` (`DefenderWorker`).
- M11 — **IPM defense** — if `MissileSilo ≥ 2`, build `Silo − AntiBallisticMissiles − 2*InterplanetaryMissiles` ABMs (`DefenderWorker`).
- M12 — **Spy attacker** — send `SpyAttacker.Probes` probes to `attack.Origin` (`DefenderWorker`).
- M13 — **Message attacker** — send one random message from `MessageAttacker.Messages` (`DefenderWorker`).
- M14 — **Auto-fleetsave on attack** — `AutoFleetSave(attackedCelestial, false, minFlightTime)` with `minFlightTime = attack.ArriveIn + ArriveIn*30/100 + random` (`DefenderWorker`).
- M15 — **Fleetsave mission fallback** — default mission (parse `DefaultMission`, fallback `Harvest`) → `Harvest` (debris) → `Deploy` → `Colonize` → `Spy` → moon `Switch` if moon exists (`TBot/Workers/FleetScheduler.cs`).
- M16 — **Fleetsave destination filter** — `GetFleetSaveDestination`: `Duration >= minFlightTime/2 && Fuel <= maxFuel`; Deploy targets other moons (fallback planets); Harvest requires recyclers, scans systems `±5` for debris (`FleetScheduler`).
- M17 — **SendFleet guards** — no ships / probes-only-expedition / same coords / out-of-range destination / insufficient fuel capacity / sleep-window return / no slots (`FleetScheduler.SendFleet`).
- M18 — **Sleep-window return check** — abort if `returnTime >= goToSleep && returnTime <= wakeUp` (`FleetScheduler.SendFleet`).
- M19 — **Deploy-with-recall fleetsave** — when `AutoFleetSave.Recall`, schedule `RecallTimer-{id}` at `minDuration/2*1000 + AMinuteOrTwo` → `RetireFleet` (`FleetScheduler.AutoFleetSave`).
- M20 — **Ghost sleep** — wait all fleets return (`GhostSleepTimer`), then fleetsave and `SleepNow(NextWakeUpTime)` (`FleetScheduler.GhostandSleepAfterFleetsReturn*`).
- M21 — **Farm target scan** — `GetGalaxyInfo`, keep `Inactive && !Administrator && !Banned && !Vacation`, drop already-under-attack (`AutoFarmWorker.GetScannedTargetsFromGalaxy`).
- M22 — **Minimum rank filter** — `IsTargetInMinimumRank(planet, scannedTargets)` drops targets below configured rank (`AutoFarmWorker`).
- M23 — **Probe escalation** — `GetNeededProbes = NumProbes ×3 (ProbesRequired) / ×9 (FailedProbesRequired)` (`AutoFarmWorker`).
- M24 — **Probe origin selection** — closest celestial with probes (or able to build probes) wins; `GetBestOrigin` prefers `BackIn` minimal (`AutoFarmWorker`).
- M25 — **Report triage** — `IsDefenceless()` → `AttackPending`; insufficient info → `ProbesRequired`; else `NotSuitable` (`AutoFarmWorker.AutoFarmProcessReports`, `EspionageReport.IsDefenceless`).
- M26 — **Attack ordering** — sort `AttackPending` by `PreferedResource` (`Metal|Crystal|Deuterium`) desc, else `TotalResources` desc (`AutoFarmWorker`).
- M27 — **Optimal farm speed** — fastest class-valid speed with `Fuel < loot*MinLootFuelRatio` (default 0.0001) and `Time < MaxFlightTime` (default 86400), else 100% (`CalculationService.CalcOptimalFarmSpeed`).
- M28 — **Cargo sizing** — cargo count from loot/capacity, respect `MinCargosToKeep`/`MinCargosToSend`, build cargo if short (`AutoFarmWorker`).
- M29 — **Expedition cargo cap** — `freightCap` tiered by `serverData.TopScore` (thresholds listed in Decision engine), `×ecoSpeed×3` Discoverer / `×2` otherwise, `+resourcesBonus%`, `ceil(freightCap/oneCargo)` ships (`CalculationService.CalcIdealExpeditionShips`).
- M30 — **Military expo ship** — priority `Reaper→Destroyer→Bomber→Battlecruiser→Battleship→Pathfinder→Cruiser→HeavyFighter→LightFighter` (`CalcMilitaryShipForExpedition`).
- M31 — **Expo fleet completion** — add 1 probe + 1 military ship + 1 Pathfinder if available (`CalcFullExpeditionShips`).
- M32 — **Expedition slot gating** — `WaitForAllExpeditions` (all in use → 0), `WaitForMajorityOfExpeditions` (`expsToSend < ExpTotal/2+1 → 0`), else send while `ExpFree>0 && Free>0` (`ExpeditionsWorker`).
- M33 — **Mine selection** — `optimizeForStart` (`M<10||C<7||D<5`) → balancing rule; else min-DOIR mine gated by `MaxDaysOfInvestmentReturn` (`GetNextMineToBuild`).
- M34 — **DOIR formula** — `cost / (nextOneDayProd − currentOneDayProd)` with `/2.5` (metal), `/1.5` (crystal), `*24` (deut) (`CalcDaysOfInvestmentReturn`).
- M35 — **Research selection** — `GetNextResearchToBuild` with per-tech max levels, `optimizeForStart`, `ensureExpoSlots`, plasma-DOIR ordering (`AutoResearchWorker`).
- M36 — **Repatriate** — `CollectImpl`: skip moons if `ExcludeMoons`, `LeaveDeut` (moons-only optional), `MinimumResources` gate, transport to `Target` (`FleetScheduler.CollectImpl`).
- M37 — **AutoCargo** — build preferred cargo ships when capacity < resource delta (`AutoCargoWorker`).
- M38 — **Offer of the day** — `BuyOfferOfTheDay()` on interval (`BuyOfferOfTheDayWorker`).
- M39 — **AutoDiscovery** — enumerate whole galaxy (`Systems × 15`), shuffle then order by distance; require `5000M/1000C/500D`; blacklist `+1d` fail / `+7d` success (`AutoDiscoveryWorker`).
- M40 — **Colonize** — random/ordered target slots, `IntensiveResearch.MaxSlots`, abandon planets by fields/temperature (`ColonizeWorker`).
- M41 — **Spycrash** — scan systems `±2`, exclude strong/inactive/banned/vacation/newbie/own, crash 1 probe to make a debris field (`FleetScheduler.SpyCrash`).
- M42 — **Captcha solver** — MD5(image) → label lookup in giant table (`TBot/Services/OgameCaptchaSolver.cs`).
- M43 — **Distance formula** — `5` same / `1000+5|Δpos|` / `2700+95|Δsys|` (donut) (`CalcDistance`).
- M44 — **Flight time** — `(35000/s·√(10d/v)+10)/a` (`CalcFlightTime`).
- M45 — **Fuel** — `Σ cons·qty·d/35000·((speed/10)+1)² + 1` (`CalcFuelConsumption`).
- M46 — **Cargo** — `baseCargo·(100+hyperspaceTech·mult+classBonus)/100` (`CalcShipCapacity`).
- M47 — **HTTP retry** — Polly `WaitAndRetryAsync(3, 2^r s)` (`OgameService.GetRetryPolicy`).
- M48 — **Pre-sleep fleet check** — delay sleep if any non-discovery fleet returns before wake-up (`TBotMain.GoToSleepAsync`).

## Notable concerns
- **Gate 1 (hardcoded object truth)** — the biggest violation risk. `CalculationService.CalcPrice(Buildables,…)` hardcodes the cost formula and base cost of **every building, ship, defence and LF building**; `CalcShipCapacity`/`CalcShipConsumption`/`CalcShipSpeed` hardcode per-ship stats; `Ships.GetFleetPoints()` hardcodes points; `GetLFBuildingRequirements()` hardcodes LF prerequisite maps (e.g. `Sanctuary=42`); `HumansBuildables` enum hardcodes ids `11103..11112`. In OGameX terms: TBot reads **universe** parameters from the host (good), but treats the **object universe (prices/capacities/requirements)** as compile-time constants. Only the `CalcIdealExpeditionShips` freight tiers are framed as "taste" over host `TopScore`; the rest is source-of-truth duplication.
- **Gate 2 (over-engineering)** — `CalculationService` is a ~3600-line god class with hundreds of public methods; `TBotMain` is a ~1400-line orchestrator mixing persistence, timing, telegram, and sleep logic; near-identical check-interval boilerplate is copy-pasted in every worker; `Feature` enum duplicates `Features.AllFeatures`; `WorkerFactory` itself notes "This is going to be replaced with ServiceProvider"; `OgameCaptchaSolver` is a multi-thousand-entry hash table. (Reference only — this is the upstream bot, not the OGameX module.)
- **Gate 3 (inhuman behaviour)** — without `SleepMode` the bot is active 24/7 with reaction latency bounded by `CheckIntervalMin/Max` (defaults minutes); it probes/attacks inactives, re-sends expeditions the instant a slot frees, and auto-replies to attackers. The only "humanization" is randomized timing, `FakeActivity`, and sleep windows — no reaction-delay modelling, no inactivity, no social behavior beyond scripted messages. `Spycrash`, `ghost`, and systematic whole-galaxy discovery are bot-only signatures a human would not exhibit.

## Confidence
- **High** for: entry point / DI wiring, `InstanceManager`, `TBotMain.Init`/sleep logic, `WorkerBase`/`AsyncTimer`, `RandomizeHelper` exact ranges, `Feature`/`FarmState`/`LogSender` enums, `SendFleet` guards, `AutoFleetSave` mission-fallback chain, `CalcIdealExpeditionShips` tiers, `CalcDistance`/`CalcFlightTime`/`CalcFuelConsumption`/`CalcShipCapacity` formulas, and the defender/farm/expedition worker mechanics — all confirmed from source excerpts with file paths.
- **Medium** for: the complete `settings.json`/`instance_settings.json` schema (reconstructed from usage sites; the authoritative schema files were not read in full), `CalcGalaxyDistance` exact constant (inferred from OGame standard), and `EspionageReport.Loot()`/`IsTargetInMinimumRank` internals (referenced but not fully shown).
- **Low** for: exact default values of every setting (only `AutoMinerSettings` defaults and a few inline defaults were verified) and any behaviour in `TBot.WebUI`/Telegram handlers beyond the command list.

Nothing was invented; every quoted snippet carries its file path, and unverified specifics are flagged as such.