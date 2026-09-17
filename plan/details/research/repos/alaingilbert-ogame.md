# alaingilbert/ogame — research document

## Overview

`github.com/alaingilbert/ogame` is a Go (99.9%) client wrapper around the Gameforge OGame web game. MIT license, ~106 stars, 60 forks, latest release `53.0.0` (~1 year old). The README bills it as the "OGame automation toolkit" usable three ways: **as a library**, **as an HTTP service (`ogamed`)**, and **as a Docker container**. It is an **API wrapper, not a bot**: it authenticates, scrapes/parses the game's HTML + JSON Ajax endpoints, and exposes typed domain objects and formulas — but contains **no strategy, decision engine, or game loop** of its own.

Top-level layout: root `ogame.go` (package decl only), `cmd/` (ogamed), `pkg/{ogame, wrapper, extractor, parser, device, gameforge, httpclient, taskRunner, exponentialBackoff, simulator, utils}`, `samples/`, `doc/`. `pkg/ogame` holds domain types + all game formulas; `pkg/wrapper` is the live client; `pkg/extractor` holds 12 versioned HTML parsers (`v6`…`v12_0_0`) plus an interface; `pkg/device` fakes a browser fingerprint.

## Architecture & entry points

- Entry constructors (`pkg/wrapper/ogame.go`): `wrapper.New(device, universe, username, password, lang)`, `NewNoLogin(...)`, `NewWithParams(Params)`. `New` defaults `AutoLogin: true` and immediately calls `LoginWithExistingCookies()`.
- `OGame` struct (`pkg/wrapper/ogame.go`) is the client; it is `sync.Mutex` + `atomic.Bool` guarded ("safe for concurrent use by multiple goroutines"). State atoms: `isEnabledAtom`, `isLoggedInAtom`, `isConnectedAtom`, `lockedAtom`, `chatConnectedAtom`.
- `Params` struct: `Ctx, Username, Password, BearerToken, OTPSecret, Universe, Lang, PlayerID, AutoLogin, Proxy, ProxyUsername, ProxyPassword, ProxyType, ProxyLoginOnly, TLSConfig, Lobby, APINewHostname, Device, CaptchaSolver, Logger, Quiet`.
- `newWithParams` builds a `taskRunner.NewTaskRunner(ctx, factory)` where `factory` returns `&Prioritize{bot: b}` — **every public method executes through this task runner and a per-call bot lock** (`pkg/wrapper/prioritize.go`: `b.begin(name)` / `defer b.done()`).
- Login flow (`pkg/wrapper/ogame_login.go`): `wrapLoginWithExistingCookies()` → `loginWithBearerToken(token, phpSessID)` → `postSessions` (Gameforge SSO via `pkg/gameforge`) → `loginPart1` (`gameforge.GetServerAccount`), `loginPart2` (fetch `serverData.xml`, set `serverURL = https://s%d-%s.ogame.gameforge.com`), `loginPart3` (pick extractor by server version, cache player/planets, start chat websocket, intro bypass).
- Extractor selection `getExtractorFor(ogVersion)` maps server version (`sanitizeServerVersion` regex `\d+\.\d+\.\d+`) to `v12_0_0`, `v11_15_0`, `v11_13_0`, `v11_9_0`, `v11`, `v104`, `v10`, `v9`, `v874`, `v8`, `v71`, `v7`, `v6`.
- Server data is parsed from `https://s%d-%s.ogame.gameforge.com/api/serverData.xml` into `ServerData` (see Config surface).
- Public interface is generated over `Prioritize` (`pkg/wrapper/ogame_public_interface.go`); the README's "Available methods" list matches it: `GetPlanets`, `GetShips`, `GetDefense`, `GetResources`, `GetResourcesBuildings`, `GetFacilities`, `GetResearch`, `GetTechs`, `Build*`, `SendFleet`, `EnsureFleet`, `GalaxyInfos`, `GetEspionageReport*`, `GetFleets`, `CancelFleet`, `GetAttacks`, `IsUnderAttack`, `Phalanx`, `JumpGate`, `SendIPM`, `SendMessage`, marketplace/auction/officer/preferences, etc.
- `ogamed` service exposes these as HTTP endpoints (e.g. `POST /bot/fleets/:fleetID/cancel`, `GET /bot/planets/:planetID/resources`, `POST /bot/planets/:planetID/build/:ogameID/:nbr`, `POST /bot/planets/:planetID/send-fleet`, `GET /bot/moons/:moonID/phalanx/...`).

## Scheduling & loop model

- **No built-in game loop.** The library is event/caller driven; `ogamed` is a request/response HTTP daemon. A consumer bot must supply its own scheduler.
- Concurrency is a single-worker **priority queue** (`pkg/taskRunner/taskRunner.go`): priorities `Low=1, Normal=2, Important=3, Critical=4`; two goroutines (producer push, consumer pop); `WithPriority(priority)` blocks the caller until its task is popped, guaranteeing **one OGame action executes at a time**. `GetTasks()` returns `TasksOverview{Low, Normal, Important, Critical, Total}`.
- `Prioritize` transaction model (`pkg/wrapper/prioritize.go`): `Begin()`/`BeginNamed(name)`/`Done()`/`Tx(fn)`/`TxNamed(name, fn)`. `begin` increments an `isTx int32`; the first increment calls `botLock(name)` (sets `lockedAtom`, fires `OnStateChange`); `Done` decrements and on zero closes `taskIsDoneCh` + `botUnlock`. `state` string records the actor holding the lock.
- Latency simulation is opt-in: `Option Delay(time.Duration)` (`pkg/wrapper/wrapper.go`) — "simulating slow request/response from ogame server"; `pageContent` runs `applyDelay(b, cfg.Delay)`. One hardcoded sleep: `sendDiscoveryFleet2` waits `utils.RandMs(250, 500)` before re-reading the movement page (`pkg/wrapper/ogame.go`).
- Retry loop: `withRetry` (`pkg/wrapper/ogame.go`) = `maxRetry := 10`, `exponentialBackoff.New(b.ctx, 60)`, re-login on `ErrNotLogged`. `SkipRetry` option bypasses it.

## Decision engine & algorithms

No strategic decision engine exists. What exists is a **deterministic formula library** (`pkg/ogame`), one file per object, and read-only "policy" helpers on the espionage report. Key formulas:

- **Price**: `BaseLevelable.GetPrice(level)` = `base * IncreaseFactor^(level-1)` per resource, minus lifeform cost bonus (`baseLevelable.go`).
- **Building time**: `BuildingConstructionTime` = `(metal+crystal) / (2500 * (1+robotics) * universeSpeed * 2^nanite)`; for non-nanite levels <5 multiplied by `2/(7-(level-1))`; floored, `math.Max(1, secs)` (`baseBuilding.go`).
- **Research time**: `(metal+crystal) / (1000 * (1+researchLab) * universeSpeed)`, then `-25%` technocrat, `-25%` discoverer, ≥1s (`baseTechnology.go`).
- **Deconstruction price**: `floor(base*factor^(level-1)) * (1 - 0.04*ionTech)` (`baseBuilding.go`).
- **Ship/defense build time**: `StructuralIntegrity / (2500*(1+shipyard)*universeSpeed*2^nanite)` per unit (`baseDefender.go`).
- **Combat stats**: `weapon*(1+0.1*weaponsTech)`, `shield*(1+0.1*shieldingTech)`, `structure*(1+0.1*armourTech)` (`baseDefender.go`).
- **Cargo capacity** (`GetCargoCapacity`): base + lifeform bonus + `hyperspaceTech*base*hyperspaceBonusMultiplier`; probes return 0 unless `probeRaids`; Collector +25% on Small/LargeCargo, General +20% on Recycler (`baseShip.go`).
- **Fuel consumption** (`GetFuelConsumption`): base, upgraded ×2 (SmallCargo w/ impulse≥5), ×3 (Recycler w/ hyperspace≥15), ×2 (Recycler w/ impulse≥17); × `fleetDeutSaveFactor`; General ÷2; `max(...,1)` (`baseShip.go`).
- **Ship speed** (`GetSpeed`): `base + base*driveFactor*driveLvl` (driveFactor 0.1 combustion / 0.2 impulse / 0.3 hyperspace) + lifeform + alliance (Trader +10% cargo) + class; special base overrides: SmallCargo→10000 (impulse≥5), Bomber→5000 (hyperspace≥8), Recycler ×3/×2; General +baseSpeed on recycler/combat (not Deathstar), Collector +baseSpeed on cargoes (`baseShip.go`).
- **Distance** (`Distance`): cross-galaxy `20000*Δgal` (donut: `min(Δ, size-Δ)`), cross-system `2700 + 95*Δsys`, cross-position `1000 + 5*Δpos`, same position `5` (`pkg/ogame/ogame.go`).
- **Flight time** (`CalcFlightTimeWithBaseSpeedDistance`): `((3500/speed) * sqrt(dist*10/baseSpeed) + 10) / universeSpeedFleet` (`pkg/ogame/ogame.go`).
- **Fuel** (`calcFuel`): per ship `(cons*nb*dist)/35000 * (shipSpeedValue/10+1)^2`, `max(...,1)`; total `1+round(sum)`; holding adds `holdingCosts/10` (`pkg/ogame/ogame.go`).
- **Phalanx range** (`sensorPhalanx.GetRange`): level 0→0, 1→1, else `level²-1`; Discoverer `+round(0.2*range)`; plus `rangeBonus` ratio (`sensorPhalanx.go`). Scan costs `5000` deuterium.
- **Metal production** (`metalMine.Production`): `30*speed + 30*(1+plasma/100)*speed*level*1.1^level`, scaled by `productionRatio*globalRatio` (`metalMine.go`). Energy consumption `ceil(10*level*1.1^level)`.
- **Plunder ratio** (`EspionageReport.PlunderRatio`): 0.5 base; Discoverer+inactive 0.75; bandit 1.0; starlord (inactive=false) 0.75. `Loot()` applies it; `IsDefenceless()` requires both fleet+defense info present and empty (`espionageReport.go`).

## Data model & persistence

- Domain types (`pkg/ogame`): `Coordinate`, `CelestialID/PlanetID/MoonID/FleetID/ID`, `Planet` (Img, ID, Name, Diameter, Coordinate, Fields, Temperature, Moon), `Moon`, `Resources`, `ResourcesBuildings`, `Facilities`, `ShipsInfos`, `DefensesInfos`, `Researches`, `LfBuildings`, `LfResearches`, `Techs`, `EspionageReport`, `EspionageReportSummary`, `CombatReportSummary`, `Fleet`, `AttackEvent`, `Slots`, `ServerData`, `Preferences`, `Auction`, `Item`, `Chapter`, `Temperature`, `Fields`, `UserInfos`, `SystemInfos`.
- `EspionageReport` has nullable (`*int64`) per-building/ship/tech/defense fields plus `Has{Fleet,Defenses,Buildings,Researches}Information` flags and typed getters returning nil if info missing (`espionageReport.go`).
- Persistence is only client-side state: a `persistent-cookiejar` at `~/.ogame/storage/<device>/cookies` and the browser fingerprint at `~/.ogame/storage/<device>/fingerprint` (`pkg/device/device.go`). "Not logged" HTML dumps go to `~/.ogame/not_logged/` (kept at most 20, sorted by mtime) (`pkg/wrapper/ogame.go`, `saveNotLoggedHTML`).
- In-memory cache (`OGame.cache`): `serverData, location, player, CachedPreferences, researches, lfBonuses, characterClass, allianceClass, planets (mtx.RWMutex), ogameSession, token, ajaxChatToken, serverURL, coloniesCount/Possible, planetID, isVacationModeEnabled, hasCommander/Admiral/Engineer/Geologist/Technocrat`.
- Full-page responses are cached via `cacheFullPageInfo` → `parser.AutoParseFullPage` unless `SkipCacheFullPage`; token and chat token are refreshed from every response (`processResponseHTML`).

## Config surface

- Library `Params` (above) + proxy types `socks5`/`http` with optional basic auth (`getTransport`/`getSocks5Transport`).
- `Device` builder (`pkg/device/device.go`): `SetOsName`, `SetBrowserName`, `SetOsVersion`, `SetBrowserEngineName`, `SetHardwareConcurrency`, `SetMemory`, `SetCanvas2DInfo`, `SetOfflineAudioCtx`, `ScreenColorDepth`, `SetScreenWidth/Height`, `SetWebglInfo`, `SetUserAgent`, `SetNavigatorVendor`, `SetTimezone`, `SetLanguages`, `SetPersistor`.
- `ServerData` XML fields: `Speed, SpeedFleetPeaceful, SpeedFleetWar, SpeedFleetHolding, SpeedFleet, Galaxies, Systems, ACS, RapidFire, DefToTF, DebrisFactor, DebrisFactorDef, RepairFactor, NewbieProtectionLimit, NewbieProtectionHigh, TopScore, BonusFields, DonutGalaxy, DonutSystem, WfEnabled, WfMinimumRessLost, WfMinimumLossPercentage, WfBasicPercentageRepairable, GlobalDeuteriumSaveFactor, Bashlimit, ProbeCargo, ResearchDurationDivisor, DarkMatterNewAcount, CargoHyperspaceTechMultiplier, FleetIgnoreEmptySystems, FleetIgnoreInactiveSystems, Domain, Version, Timezone`.
- `ogamed` flags/env (`README`): `--universe --username --password --language`, Docker via `.env` `OGAMED_*`; listens on `127.0.0.1:8080`.

## Edge cases & failure handling

- **Logout detection** (`detectLoggedOut`, `pkg/wrapper/ogame.go`): per-page heuristics — full pages via `v6.IsLogged`, event list via `eventListWrap`, eventbox via JSON parse, galaxy content via `canParseSystemInfos`/`canParseNewSystemInfos`; sets `isConnectedAtom=false`, returns `ogame.ErrNotLogged`, dumps HTML, triggers re-login in retry loop.
- **Account errors surfaced**: `gameforge.ErrAccountNotFound`, `AccountBlockedError` (with `BannedReason`), `ErrBadCredentials`, `ErrOTPRequired`, `ErrOTPInvalid` (`withRetry`).
- **Fleet send guards** (`sendFleet`): attack block (`ExtractAttackBlockFromDoc` → `ogame.NewAttackBlockActivatedErr(blockedUntil)`), vacation mode (`ErrAccountInVacationMode`), self-target/spy-self checks, `ErrAllSlotsInUse`, `ErrNotEnoughShips` (EnsureFleet mode only), `ErrNoShipSelected`, cargo clamping (`resources.Total() > cargo` clamps in order deut→crystal→metal), `checkTarget` `TargetOk`, error-coded server responses.
- **Holding time clamps**: Expedition `1..18`, ParkInThatAlly `0..32` (`sendFleet`).
- **Phalanx** (`getPhalanx`): 3 server calls; validates moon exists, `phalanxLvl > 0`, enough deuterium (`ErrNotEnoughDeuterium`), coordinate in range, valid planet, not own planet. The public doc comment warns: *"My account was instantly banned when I scanned an invalid coordinate"* — hence `UnsafePhalanx` exists separately.
- **IPM/rockets**: clamps `nbr` to available, `IsValidIPMTarget` rejects ABM/IPM as targets; `destroyRockets` clamps to `maxABM/maxIPM`.
- **Input validation** at trust boundaries: `Highscore` (category 1-2, type 0-11, page ≥1), `RecruitOfficer` (type ∈ {2,3,4,5,6}, days ∈ {7,90}), `GalaxyInfos` (galaxy/system range), marketplace itemID typing (int 1-3 resource, int ship, 40-char hash), `Build*` id-type checks, `UseDM` type validity.
- **Known OGame quirk workaround**: `fixAttackEvents` corrects attack destination type when a moon name >12 chars breaks the eventbox image (`pkg/wrapper/ogame.go`).
- **Server data guards**: `SpeedFleetWar/Peaceful/Holding` clamped to `max(...,1)` (`getServerData`).

## Anti-detection & authenticity

- `pkg/device` synthesizes a **persistent per-device browser fingerprint** (`JsFingerprint`, `ConstantVersion: 11`) serialized exactly like Gameforge's client JS and encrypted via a custom "blackbox" cipher (`EncryptBlackbox`: URL-escape → running-sum → custom base64).
- Fields (`device.go`): `Timezone, OsName, BrowserName, NavigatorVendor, DeviceMemory, HardwareConcurrency, Languages, PluginsHash, WebglInfo, FontsHash, AudioCtxHash, ScreenWidth/Height, ColorDepth, VideoHash, AudioHash, MediaDevicesHash, PermissionsStatesHash, OfflineAudioCtx, WebglRenderHash, Canvas2DInfo, DateIso, XGame, CalcDeltaMs, Version, XVecB64, UserAgent, Game1DateHeader`.
- Realistic defaults: `OfflineAudioCtx` random in `[123.8, 124.9]`; `Canvas2DInfo` random in `[261334512, 1902830807]`; screen size/hardware-concurrency/color-depth/UA/WebGL chosen from large OS+browser lookup tables; `XVecB64` = 100 random chars + timestamp, `rotateXVec` advances one char when older than 1000 ms; `Game1DateHeader`/`CalcDeltaMs` are measured by actually fetching `https://gameforge.com/tra/game1.js`.
- Fingerprint persisted per device name; cookies persisted per device; the `device` cookie is removed to avoid a mobile-view flag.
- Ajax requests set `X-Requested-With: XMLHttpRequest` and `Accept-Encoding: gzip, deflate, br` (`execRequest`).
- Chat connects via socket.io websocket with session authorization (`connectChatV8`).
- **Latency humanization is manual**: `Delay` option only; no automatic randomized pacing, no activity schedule, no uptime shaping.

## Discrete mechanisms

- M01 — Mission IDs — `Relocate 0, Attack 1, GroupedAttack 2, Transport 3, Park 4, ParkInThatAlly 5, Spy 6, Colonize 7, RecycleDebrisField 8, Destroy 9, MissileAttack 10, Expedition 15, SearchForLifeforms 18` (`pkg/ogame/constants.go`).
- M02 — Fleet speeds — `TenPercent 1 … HundredPercent 10`, plus General-class halves `FivePercent 0.5 … NinetyFivePercent 9.5` (`constants.go`).
- M03 — Building IDs — `MetalMine 1, CrystalMine 2, DeuteriumSynthesizer 3, SolarPlant 4, FusionReactor 12, RoboticsFactory 14, NaniteFactory 15, Shipyard 21, MetalStorage 22, CrystalStorage 23, DeuteriumTank 24, ShieldedMetalDen 25, UndergroundCrystalDen 26, SeabedDeuteriumDen 27, ResearchLab 31, Terraformer 33, AllianceDepot 34, SpaceDock 36, LunarBase 41, SensorPhalanx 42, JumpGate 43, MissileSilo 44` (`constants.go`).
- M04 — Defense IDs — `RocketLauncher 401, LightLaser 402, HeavyLaser 403, GaussCannon 404, IonCannon 405, PlasmaTurret 406, SmallShieldDome 407, LargeShieldDome 408, AntiBallisticMissiles 502, InterplanetaryMissiles 503` (`constants.go`).
- M05 — Ship IDs — `SmallCargo 202, LargeCargo 203, LightFighter 204, HeavyFighter 205, Cruiser 206, Battleship 207, ColonyShip 208, Recycler 209, EspionageProbe 210, Bomber 211, SolarSatellite 212, Destroyer 213, Deathstar 214, Battlecruiser 215, Crawler 217, Reaper 218, Pathfinder 219` (`constants.go`).
- M06 — Research IDs — `Espionage 106, Computer 108, Weapons 109, Shielding 110, Armour 111, Energy 113, Hyperspace 114, Combustion 115, Impulse 117, HyperspaceDrive 118, Laser 120, Ion 121, Plasma 122, IRN 123, Astrophysics 124, Graviton 199` (`constants.go`).
- M07 — Lifeform IDs — Humans/Rocktal/Mechas/Kaelesh buildings `11101–14112` and techs `11201–14218` (`constants.go`); categorized lists `Humans/Rocktal/Mechas/Kaelesh Buildings/Technologies` (`objs.go`).
- M08 — Object registry — `register[T]` stores each constructed object in `Objs.m` keyed by ID; accessors `ByID(id)`, `GetShip(id)` (`pkg/ogame/objs.go`).
- M09 — ID classification — `IsFacility`, `IsResourceBuilding`, `IsLfBuilding`, `IsBuilding`, `IsTech`, `IsLfTech`, `IsDefense`, `IsShip`, `IsCivilShip`, `IsCombatShip`, `IsFlyableShip` (excludes SolarSatellite, Crawler), `IsValidIPMTarget` (`pkg/ogame/id.go`).
- M10 — Availability gating — `IsAvailable`: rejects non-planet/moon; planet excludes LunarBase/SensorPhalanx/JumpGate; moon excludes mines/solar/fusion/lab/depot/silo/nanite/terraformer/dock and all techs; `GravitonTechnology` needs `energy >= 300000`; Reaper requires General, Pathfinder requires Discoverer, Crawler requires Collector; requirement map checked via BFS queue (`pkg/ogame/base.go`).
- M11 — Price formula — `baseCost * IncreaseFactor^(level-1)`, minus lifeform cost bonus (`pkg/ogame/baseLevelable.go`).
- M12 — Building duration — `(metal+crystal)/(2500*(1+robotics)*speed*2^nanite)`; levels <5 × `2/(7-(level-1))`; floor to ≥1s (`pkg/ogame/baseBuilding.go`).
- M13 — Research duration — `(metal+crystal)/(1000*(1+lab)*speed)`; −25% technocrat; −25% discoverer; ≥1s (`pkg/ogame/baseTechnology.go`).
- M14 — Deconstruction cost — `floor(base*factor^(level-1)) * (1 - 0.04*ionTech)` (`pkg/ogame/baseBuilding.go`).
- M15 — Unit build duration — `StructuralIntegrity/(2500*(1+shipyard)*speed*2^nanite)` per unit × nbr (`pkg/ogame/baseDefender.go`).
- M16 — Combat scaling — `× (1 + 0.1*techLvl)` for weapon/shield/structure; rapidfire stored as `map[ID]int64` both directions (`pkg/ogame/baseDefender.go`).
- M17 — Cargo — base + lf bonus + `hyperspaceTech*base*multiplier`; probe 0 without `ProbeRaids`; Collector +25% cargoes, General +20% recycler (`pkg/ogame/baseShip.go`).
- M18 — Fuel — engine upgrades ×2/×3; × `GlobalDeuteriumSaveFactor`; General ÷2; floor `max(...,1)` (`pkg/ogame/baseShip.go`).
- M19 — Speed — `base*(1 + driveFactor*driveLvl)` with 0.1/0.2/0.3 factors + special base overrides + class/alliance bonuses (`pkg/ogame/baseShip.go`).
- M20 — Distance — galaxy `20000*Δ` (donut min), system `2700+95*Δ`, position `1000+5*Δ`, same `5` (`pkg/ogame/ogame.go`).
- M21 — Flight time — `((3500/speed)*sqrt(dist*10/baseSpeed)+10)/universeSpeedFleet` (`pkg/ogame/ogame.go`).
- M22 — Fuel mass — `(cons*nb*dist)/35000*(shipSpeedValue/10+1)^2`, +1, min 1; holding `+holdingCons/10` (`pkg/ogame/ogame.go`).
- M23 — Phalanx — range `lvl²-1` (0→0, 1→1); Discoverer `+round(20%)`; `+rangeBonus%`; scan cost 5000 deut (`pkg/ogame/sensorPhalanx.go`).
- M24 — Metal production — `30*speed + 30*(1+plasma/100)*speed*level*1.1^level` scaled by ratios (`pkg/ogame/metalMine.go`).
- M25 — Energy draw — `ceil(10*level*1.1^level)` (`pkg/ogame/metalMine.go`).
- M26 — Plunder — 0.5 base; 0.75 discoverer+inactive; 1.0 bandit; 0.75 starlord (`pkg/ogame/espionageReport.go`).
- M27 — Message tabs — `20 espionage, 21 combat, 22 expeditions, 23 unions/transport, 24 other, 26 marketplace purchases, 27 marketplace sales` (`pkg/wrapper/ogame.go`).
- M28 — Retry — max 10 attempts, exponential backoff (cap 60s), auto re-login on `ErrNotLogged` (`pkg/wrapper/ogame.go`).
- M29 — Task scheduling — priorities Low/Normal/Important/Critical = 1..4, single executing task (`pkg/taskRunner/taskRunner.go`).
- M30 — Fleet dispatch guards — attack block, vacation, self-target, ship availability, cargo clamp, holding clamps (expedition 1–18, park 0–32) (`pkg/wrapper/ogame.go`).
- M31 — Officer limits — type ∈ {2,3,4,5,6}, days ∈ {7,90} (`pkg/wrapper/ogame.go`).
- M32 — Highscore limits — category 1–2, type 0–11, page ≥1 (`pkg/wrapper/ogame.go`).
- M33 — Marketplace item typing — resource int 1–3, ship id, or 40-char item hash (`pkg/wrapper/ogame.go`).
- M34 — Build dispatch — ships/defense amount capped at `99999`; single `buildlistactions/scheduleEntry` mode 1 (`pkg/wrapper/ogame.go`).
- M35 — IPM target — must pass `IsValidIPMTarget` (defense, not ABM/IPM) (`pkg/wrapper/ogame.go`).
- M36 — Mission fleet speed — `GetFleetSpeedForMission(serverData, mission)` selects war/peaceful/holding speed per mission (`pkg/wrapper/ogame.go` usage).
- M37 — Fingerprint versioning — blackbox `ConstantVersion: 11`; `XVec` 100 chars + ms timestamp; `rotateXVec` advances 1 char when >1000 ms (`pkg/device/device.go`).
- M38 — Fingerprint randomness — `OfflineAudioCtx ∈ [123.8, 124.9]`; `Canvas2DInfo ∈ [261334512, 1902830807]` (`pkg/device/device.go`).
- M39 — Screen depth defaults — Android 24, iOS 32, Windows/Mac 24 (`pkg/device/device.go`).
- M40 — Server-data guard — `SpeedFleet{War,Peaceful,Holding}` clamped `max(...,1)`; `SpeedFleet` falls back to `SpeedFleetPeaceful` (`pkg/wrapper/ogame.go`).

## Notable concerns

- **Gate-1 (no static, hardcoded AI) is violated by design.** The entire object universe is hardcoded: ~150 constants in `constants.go`, one source file per object (`metalMine.go`, `lightFighter.go`, …) each with literal `BaseCost`, `IncreaseFactor`, `Requirements`, `RapidfireFrom/Against`, `StructuralIntegrity/ShieldPower/WeaponPower`, and per-object `newXxx()` constructors in `objs.go`. Adding an OGame object requires editing the module — exactly what Gate 1 forbids. The formulas (`GetPrice`, `ConstructionTime`, `IsAvailable`) are good reference implementations but must be **re-derived from host data**, not copied.
- **Versioned extractor churn**: 12 parallel HTML parsers; `pkg/simulator` is 3 years stale. The repo is maintained (v53, 268 releases) but the last commit is ~1 year old.
- **No bot layer**: scheduling, goal selection, and pacing must come from the consumer; authenticity is reduced to a browser fingerprint + optional `Delay`.
- **Regex/JSON-sniffing robustness**: logged-out detection and several fields rely on string matching and JSON-shape sniffing (`canParseEventBox`, `canParseSystemInfos`), which can silently misclassify on upstream changes.
- **Ban risk**: the codebase itself documents an account ban from an invalid Phalanx scan; `UnsafePhalanx` exists but is dangerous.

## Confidence

High for everything quoted: I read the README and raw sources (`ogame.go`, `base*.go`, `objs.go`, `constants.go`, `id.go`, `metalMine.go`, `sensorPhalanx.go`, `planet.go`, `espionageReport.go`, `wrapper/ogame.go`, `ogame_login.go`, `prioritize.go`, `fleetBuilder.go`, `wrapper.go`, `taskRunner.go`, `device.go`) directly. Two caveats: the semantic (`github_repo`) and lexical (`github_text_search`) GitHub tools were unavailable (index/error), so discovery relied on directory listings + `fetch_webpage` of raw files; and a few helper bodies were not fully read — notably the exact backoff schedule in `pkg/exponentialBackoff` (treated as "cap 60s") and the precise branch logic of `GetFleetSpeedForMission` (described as war/peaceful/holding selection). The list of public methods was taken from the README's "Available methods", cross-checked against `prioritize.go`/`ogame_public_interface.go`.