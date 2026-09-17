# XBot (`janeczkins/xbot`) — Research Document

**Repository:** https://github.com/janeczkins/xbot — 2 contributors, 0 stars/forks, 49 releases (latest **v1.5.27**), first public commit ~4 months ago (May 2026). XBot itself is **proprietary closed-source**; the main branch commits only documentation. The engine (`xengine`) is a bundled binary built from the MIT-licensed [`alaingilbert/ogame`](https://github.com/alaingilbert/ogame) and its [`0xE232FE/ogame.mod`](https://github.com/0xE232FE/ogame.mod) fork. Feature design is credited as inspired by [`ogame-tbot/TBot`](https://github.com/ogame-tbot/TBot).

Committed files in the main repo (verified via repo tree): `README.md`, `CHANGELOG.md`, `LICENSE`, `THIRD-PARTY-NOTICES.md`, `.gitignore`, and `docs/` containing `FEATURES.md`, `FAQ.md`, `TERMS_OF_USE.md`. **No `.cs`, `.csproj`, `.json` config, or source code is committed** — the actual code ships only inside the release binaries (`.zip` with `XBot` + `xengine`). The bulk of the factual detail below comes from the project **Wiki** (a separate git repo: `janeczkins/xbot.wiki.git`), which is the authoritative documentation.

---

## Overview

XBot is a **.NET 9 automation bot for OGame** (lobby.ogame.gameforge.com), Windows/Linux/macOS, self-hosted on PC or VPS. It talks directly to game servers (no browser/client needed). Multi-account (multiple "instances" in one process), each with its own per-account JSON config. Ships with a WebUI (SignalR live logs), Telegram and Discord control/alerting, and a proprietary license system (3-day auto-trial).

Feature set (per README + `docs/FEATURES.md` + Wiki "Home"):
- **Defender** — incoming-attack detection; fleet/resource save, alarm, attacker spying, attacker messaging, Telegram/Discord alert.
- **Expeditions** — auto expedition fleets to position 16, automatic or manual fleet composition, multi-origin.
- **Brain (economy suite)** — `AutoMine` (ROI-based planet/moon builds), `AutoResearch`, `LifeformAutoMine`, `LifeformAutoResearch`, `AutoCargo`, `AutoDefence`, `AutoRepatriate`, `AutoFleepJumpGate` (jump-gate transfers), `BuyOfferOfTheDay` (daily Trader).
- **AutoFarm** — galaxy-range scanning, spying inactive players, raiding profitable targets.
- **AutoHarvest** — recyclers for own/deep-space debris fields.
- **AutoColonize** — colony ships to configured coordinates, with auto-abandon of poor planets.
- **AutoDiscovery** — Pathfinder/lifeform discovery missions.
- **Fleet Analyzer** — scan + simulated battle ranking of attack targets (WebUI target list).
- **Sleep Mode** — nightly pause + `AutoFleetSave` (deploy-with-recall).
- **Production tracking / Stats** — hourly/daily production logs, 30-day farming/expedition charts (added in "Unreleased" changelog).
- **WebUI, Telegram, Discord, proxy (HTTP/SOCKS5), captcha handling.**

---

## Architecture & entry points

- **Two processes:** the `XBot` executable (proprietary .NET 9 host: orchestration, workers, WebUI, messengers, license) and **`xengine`** (`xengine.exe` on Windows), a bundled daemon built from `ogame.mod` that actually talks to OGame. `xengine` must sit next to `XBot` (`README.md`).
- **Per-instance engine daemon:** each instance binds its own `General.Host` / `General.Port` (default `0.0.0.0:8080`). XBot talks to the engine over HTTP on that port; the README's WebUI `maxLogs`/manual captcha endpoints imply an HTTP API (`/bot/captcha`, `/bot/expedition-messages` documented in `CHANGELOG.md`).
- **WebUI** is ASP.NET Core Razor + SignalR (changelog references `XBot.WebUI/Controllers/SettingsController.cs`, `Views/Settings/Index.cshtml`, `wwwroot/js/settings.js`, `wwwroot/css/settings.css`, `DiscordController`, `SetupController.ApplyPayload()`).
- **Core service surface** (named in `CHANGELOG.md` "Techniczne"): `IOgameService` / `OgameService` with `GetExpeditionMessages()`; `IStatsService` with `RecordExpeditionAt`, `AccumulateExpeditionShips`, `GetLastExpeditionMsgId`/`SetLastExpeditionMsgId`; a `StatsService` persisting to `expedition_state.json`; an `ExpeditionsWorker` calling `ProcessExpeditionMessages()` each cycle; models like `ExpeditionMessage`.
- **NuGet dependencies** (`THIRD-PARTY-NOTICES.md`): `Newtonsoft.Json`, `RestSharp`, `Telegram.Bot` (+`Extensions.Polling`), `Discord.Net`, `Serilog` (+sinks), `Polly`/`Polly.Extensions.Http`, `SixLabors.ImageSharp` (3.x — split license; commercial caveat noted).
- **Entry point / config split** (`Configuration-Overview`): `settings.json` (global: license, WebUI, Telegram/Discord, instance list) and `instance_settings.json` (per-account: credentials, engine port, proxy, all feature toggles). Both sit next to the executable.

---

## Scheduling & loop model

Every feature is a **worker** run on a randomized polling interval — a uniform random delay in `[CheckIntervalMin, CheckIntervalMax]` seconds, with both bounds configurable per feature. There is no single fixed tick; each worker sleeps a random amount between cycles.

- Common timing idiom (Wiki, "Reference Brain"): `CheckIntervalMin` = lower bound, `CheckIntervalMax` = upper bound of the random wait between runs.
- Representative defaults: **Defender 1–22 s**, **AutoMine/AutoResearch 10–20 s**, **Expeditions launch delay 5–15 s** and **round delay 30–300 s**, **AutoFarm/AutoHarvest/AutoDiscovery 30–60 s**, **AutoCargo/AutoDefence 60–240 s**, **LifeformAutoMine 30–60 s**, **LifeformAutoResearch 60–120 s**, **FleetAnalyzer 300–600 s**, **AutoColonize 900–1200 s**, **AutoRepatriate 3000–6000 s**, **BuyOfferOfTheDay 240–360 s**, **AutoFleepJumpGate 5–10 s**.
- **Hot-reload:** a file watcher on `instance_settings.json` restarts only affected workers, no process restart. Editing the top-level `settings.json` instance list is "beta".
- **Sleep Mode** gates the whole loop: between `GoToSleep` and `WakeUp` all outgoing automation pauses (except `Expeditions.IgnoreSleep` if set, and Defender optionally).
- **Slot arbitration:** a global `SlotsToLeaveFree` (default `1`) and per-feature slot limits; when slots are scarce, `SlotPriorityLevel` (lower = higher priority) decides who goes first — Expeditions `1`, Brain `2`, AutoFarm `3`, AutoColonize `4`, AutoDiscovery `10`, AutoHarvest `99`.

---

## Decision engine & algorithms

All decision logic is inside the proprietary binaries; only behavior contracts are documented. Named mechanisms:

- **Brain safety lock:** all Brain sub-features share a single lock so they never issue conflicting build/research orders simultaneously (Wiki + `FAQ.md`).
- **AutoMine ROI:** "calculates the most cost-efficient mine to upgrade next" (`FEATURES.md`); `MaxDaysOfInvestmentReturn` (default **7 days**) is the ROI ceiling — only build an upgrade if it pays for itself within that many days of extra production. `OptimizeForStart` (default true) prioritizes cheapest high-impact upgrades early. `DepositHours` (default **6**) sizes storage as hours-of-production it should hold.
- **AutoResearch:** follows a priority list + boolean priority flags (`PrioritizeAstrophysics`, `PrioritizePlasmaTechnology`, `PrioritizeEnergyTechnology`, `PrioritizeIntergalacticResearchNetwork`), queues next research immediately after current finishes, respects prerequisites/resource availability. `EnsureExpoSlots` keeps expo/fleet slot tech growing.
- **Lifeform auto-detection:** `LifeformAutoMine` "detects the active Lifeform automatically"; `PreventIfMoreExpensiveThanNextMine` (default true) skips an LF build if it costs more than the next regular mine upgrade.
- **AutoFarm scoring:** targets filtered by `MinimumResources` (loot), `MinimumPlayerRank` (≤ rank 500), `MaxFlightTime`, and `MinLootFuelRatio` (loot-to-fuel ≥ **0.5**). Blacklist skips defended/empty targets. `TargetsProbedBeforeAttack` (30) probes a batch before attacking.
- **Fleet Analyzer:** "simulates battles" and ranks targets by profitability using a hypothetical `AttackerFleet`, `AttackerTechs` (weapons/shield/armour), and a `MinPowerRatio` (**1.5**) attacker:defender power gate (`FEATURES.md`, `Reference-Fleet-Features`).
- **Defender weak-attack logic:** `WeakAttackRatio` (default **3**) — ignore an attack if your defending power exceeds attacker power by ≥ this ratio (only when `IgnoreWeakAttack` true). `IgnoreProbes` ignores probe-only "attacks".
- **Expeditions fleet calc:** "optimal fleet calculation" automatic, or `ManualShips` override with exact ship counts; `PrimaryShip`/`SecondaryShip` + `SecondaryToPrimaryRatio` mixing.

---

## Data model & persistence

- **No database.** State is JSON files + cookies, per the docs:
  - `settings.json`, `instance_settings.json` — configuration (excluded from git via `.gitignore` because they contain passwords/tokens — `CHANGELOG.md`).
  - `xbot.log` — log file (named in `FAQ.md`, `Troubleshooting`).
  - **Cookies files** — lobby session cookies; same lobby account ⇒ shareable cookies file, different lobby accounts ⇒ separate files (`README.md`, `Configuration-Overview`).
  - `expedition_state.json` — stats persistence: `{LastMsgIds, ShipTotals}` with backward-compat migration from an old flat format (`CHANGELOG.md`).
- Models visible from changelog: `ExpeditionMessage`; stat aggregation (`RecordExpeditionAt`, `AccumulateExpeditionShips`).
- Coordinate object used throughout config: `{ "Galaxy": g, "System": s, "Position": p, "Type": "Planet" | "Moon" }`.

---

## Config surface

**`settings.json`** (global, defaults in parentheses):
- `License.Key` (`""`) — `XXXXXX-…-V3`; empty ⇒ auto 3-day trial.
- `TelegramMessenger`: `Active` (false), `API`, `ChatId`, `Logging` (true), `TelegramAutoPing.Active` (true) / `EveryHours` (1).
- `DiscordMessenger`: `Active` (false), `BotToken`, `GuildId`, `ChannelId`, `AlertUserId`.
- `WebUI`: `Enable` (true), `Urls` (`http://0.0.0.0:9999`), `MaxLogsToShow` (2000), `Username` (`admin`), `Password` (`xbot`).
- `Instances[]`: `Settings` (path to per-account file), `Alias`.

**`instance_settings.json`** sections:

`Credentials`: `Universe`, `Email`, `Password`, `Language` (en/de/fr/pl/es…), `LobbyPioneers` (false), `BasicAuth` (Username/Password), `DeviceConf` (fingerprint: `Name`, `System`, `Browser`, `UserAgent`, `Memory`, `Concurrency`, `Color`, `Width`, `Height`, `Timezone`, `Lang`).

`General`: `Host` (`0.0.0.0`), `Port` (`8080`, unique per instance), `CaptchaAPIKey` (`""`, Ninja Captcha), `CustomTitle`, `SlotsToLeaveFree` (1), `Proxy` (`Enabled` false, `Address`, `Type` socks5|http, `Username`, `Password`, `LoginOnly` true), `SlotPriorityLevel` (Brain 2, Expeditions 1, AutoFarm 3, AutoColonize 4, AutoDiscovery 10, AutoHarvest 99).

`SleepMode`: `Active` (false), `GoToSleep` (`23:15`), `WakeUp` (`07:05`), `PreventIfThereAreFleets` (true), `TelegramMessenger.Active` (false), `AutoFleetSave` (below).

`Defender`, `Brain`, and fleet features: full field-by-field detail is in the **Discrete mechanisms** section below.

---

## Edge cases & failure handling

- **Trial expiry ⇒ demo mode** (~15 min/session then exit). **Paid lapse ⇒ 3-day grace**, then blocked. **Offline license ⇒ signed offline cache, up to 3 days** (`License-and-Trial`).
- **Captcha loops:** manual solve at `http://HOST:PORT/bot/captcha`, or auto via Ninja Captcha `CaptchaAPIKey` (`Troubleshooting`).
- **Port collisions:** each `General.Port` and the WebUI port must be unique; duplicate ⇒ won't start.
- **`xengine` missing ⇒ "xengine not found" / immediate exit.**
- **Sleep Mode mid-operation:** `PreventIfThereAreFleets` delays going to sleep while fleets are in flight.
- **Defender `IgnoreAttackIfIHave`:** don't fleet-save when value at risk is trivial (`MinResourcesToSave` 2M / `MinFleetToSave` 20M points).
- **Slot starvation:** `SlotsToLeaveFree` + per-feature `MaxSlots` + `SlotPriorityLevel`; troubleshooting notes "fleets stopped silently" ⇒ check slots/sleep.
- **AutoFarm blacklist** auto-expires (`ResetAfterHours` 20h) and exempts rich targets (`MinimumResourcesToNotBlacklist` 1M).
- **AutoDiscovery `MaxFailures`** (5) stops after consecutive failures.
- **AutoHarvest profitability gate** before sending recyclers.
- **OGame patch incompatibility:** "stop the bot and wait for an XBot update" — no auto-safe-degrade documented.
- **Data-loss-relevant:** `SkipIfIncomingTransport` guards (AutoCargo/AutoRepatriate) prevent duplicate transports; `CheckMoonOrPlanetFirst`, `DoMultipleTransportIsNotEnoughShipButSamePosition`, `RoundResources` control transport correctness.
- **Changelog production bug:** a `pkill -9 XBot` deploy step killed both processes; replaced with `fuser -k <file>` (`CHANGELOG.md`).

---

## Anti-detection & authenticity

XBot is explicitly a bot-detection-avoidance product — the opposite of the authenticity goal. Documented mechanisms:

- **`DeviceConf` device/browser fingerprint** (system, browser, UA, memory, cores, color depth, resolution, timezone, language) sent to Gameforge "so the login looks like a consistent real browser"; docs advise keeping values **stable across runs** to reduce verification prompts.
- **Randomized intervals everywhere** — explicitly described as "looks human": Defender scan `[1,22]s` "a random value… used each cycle to look human"; `RandomOrder` (AutoMine/AutoCargo/AutoDefence), `RandomizeOrder` (Expeditions), `RandomPosition` (AutoColonize).
- **Defender `RandomActivity`** (true): "performs small random in-game actions to mimic a human presence."
- **Proxy routing** (HTTP/SOCKS5) to avoid rate limiting; `LoginOnly` masks only the lobby handshake; docs warn changing IPs too often triggers extra verification.
- **Captcha solving** (manual endpoint + Ninja Captcha auto API).
- **Human-shaped life patterns:** `SleepMode` window, `AutoFleetSave` deploy-with-recall, `MessageAttacker.Messages` pool ("i'm online" examples) to look like an active player.

---

## Discrete mechanisms

Config keys quoted from the Wiki reference pages (`Reference-Brain`, `Reference-SleepMode-and-Defender`, `Reference-Fleet-Features`, `Reference-Credentials-and-General`). Defaults in parentheses.

- **M01 — Defender polling** — every cycle waits a random `[CheckIntervalMin, CheckIntervalMax]` = `[1, 22]` s before scanning for attacks.
- **M02 — Defender IgnoreProbes** — `IgnoreProbes: true` ignores espionage-probe-only "attacks".
- **M03 — Defender missiles** — `DefendFromMissiles: true` also reacts to incoming interplanetary missiles.
- **M04 — Defender weak-attack filter** — `IgnoreWeakAttack: false` + `WeakAttackRatio: 3`: ignore an attack only if your defending power exceeds attacker power by ≥ 3×.
- **M05 — Defender value gate** — `IgnoreAttackIfIHave` (`MinResourcesToSave: 2000000`, `MinFleetToSave: 20000000`): skip the fleet-save reaction when risk below thresholds.
- **M06 — Defender fleet save** — `Autofleet` (`Active: true`, `ExtraReturnDelaySec: 1800`): on attack, save fleet with +1800 s extra return delay so it doesn't land while the attacker is present.
- **M07 — Defender attacker intel** — `SpyAttacker` (`Active: true`, `Probes: 20`): send 20 probes at the attacker.
- **M08 — Defender messaging** — `MessageAttacker` (`Active: false`, `Messages: ["hey","hello","i'm online'"]`): send one pooled message.
- **M09 — Defender whitelist** — `WhiteList: [0, 100000, 100003, 100004]`: player IDs treated as friendly; attacks from them never trigger a reaction.
- **M10 — Defender alarm/notify** — `Alarm.Active: true` (audible), `TelegramMessenger.Active: false`.
- **M11 — SleepMode window** — `GoToSleep: "23:15"` / `WakeUp: "07:05"` (account timezone): all outgoing automation pauses in-window.
- **M12 — SleepMode fleet gate** — `PreventIfThereAreFleets: true`: delay sleep while fleets are in flight.
- **M13 — SleepMode AutoFleetSave** — `Active: true`, `OnlyMoons: true`, `DeutToLeave: 200000`, `Recall: true`, `DefaultMission: "Deploy"`: before sleep, deploy fleet away; `Recall` brings it back near wake-up. (**Deploy-recall**.)
- **M14 — Brain master switch** — `Brain.Active: true`; false disables every sub-feature.
- **M15 — Brain safety lock** — all Brain sub-features share one lock so build/research orders never conflict.
- **M16 — Transports** — `CargoType: SmallCargo`, `DeutToLeaveOnMoons: 1000000`, `RoundResources: true`, `SendToTheMoonIfPossible: false`, `MaxSlots: 10`, `Origin` (coordinate), `CheckMoonOrPlanetFirst: false`, `DoMultipleTransportIsNotEnoughShipButSamePosition: false`.
- **M17 — Transports MultipleOrigins** — `Active: false`, `OnlyFromMoons: true`, `MinimumResourcesToSend: 10000000` (skip source below 10M), `PriorityToProximityOverQuantity: false`, `Exclude[]`.
- **M18 — AutoMine ROI** — `MaxDaysOfInvestmentReturn: 7`: build an upgrade only if it repays within 7 days of extra production; `OptimizeForStart: true` picks cheapest high-impact upgrades first.
- **M19 — AutoMine per-building caps** — `MaxMetalMine: 40`, `MaxCrystalMine: 35`, `MaxDeuteriumSynthetizer: 37`, `MaxSolarPlant: 20`, `MaxFusionReactor: 20`, `MaxMetalStorage: 13`, `MaxCrystalStorage: 12`, `MaxDeuteriumTank: 11`, `MaxRoboticsFactory: 10`, `MaxShipyard: 12`, `MaxResearchLab: 12`, `MaxMissileSilo: 0`, `MaxNaniteFactory: 7`, `MaxTerraformer: 8`, `MaxSpaceDock: 7`, `MaxLunarBase: 8`, `MaxLunarShipyard: 0`, `MaxLunarRoboticsFactory: 8`, `MaxSensorPhalanx: 6`, `MaxJumpGate: 1` (0 = never build).
- **M20 — AutoMine options** — `RandomOrder: true`, `PrioritizeRobotsAndNanites: false`, `BuildDepositIfFull: false`, `BuildSolarSatellites: true`, `BuildCrawlers: true`, `DepositHours: 6`, `DeutToLeaveOnMoons: 1000000`, `CheckIntervalMin/Max: 10/20` s, `Transports.Active: true` (ship resources to afford the next upgrade).
- **M21 — LifeformAutoMine** — `StartFromCrystalMineLvl: 20` (only after crystal 20), `MaxBasePopulationBuilding: 60`, `MaxBaseFoodBuilding: 62`, `MaxBaseTechBuilding: 6`, `MaxT2Building: 10`, `MaxT3Building: 8`, `MaxBuilding6…12: 5`, `PreventIfMoreExpensiveThanNextMine: true`, `CheckIntervalMin/Max: 30/60` s.
- **M22 — LifeformAutoResearch** — `MaxResearchLevel: 15`, per-slot caps `MaxTechs11…36` (11–16 = 1st lifeform, 21–26 = 2nd, 31–36 = 3rd; 0 disables), `CheckIntervalMin/Max: 60/120` s.
- **M23 — AutoResearch** — research caps: Energy 20, Laser 12, Ion 5, Hyperspace 20, Plasma 20, Combustion 19, Impulse 17, Hyperspace Drive 15, Espionage 8, Computer 20, Astrophysics 23, IRN 12, Weapons 25, Shielding 25, Armour 25; `OptimizeForStart: true`, `EnsureExpoSlots: true`, `PrioritizeAstrophysics/Plasma/Energy/IRN: true`, `ForceResearchWhateverTheLabLevel: false`, `CheckIntervalMin/Max: 10/20` s.
- **M24 — AutoCargo** — `ExcludeMoons: true`, `CargoType: LargeCargo`, `RandomOrder: true`, `MaxCargosToBuild: 50`, `MaxCargosToKeep: 50`, `LimitToCapacity: true`, `SkipIfIncomingTransport: true`, `CheckIntervalMin/Max: 60/240` s.
- **M25 — AutoDefence** — `DefenceToReach`: RocketLauncher 100000, HeavyLaser 10000, GaussCannon 1000, PlasmaTurret 1000, SmallShieldDome true, LargeShieldDome true; `CheckIntervalMin/Max: 60/240` s.
- **M26 — AutoRepatriate** — `ExcludeMoons: false`, `MinimumResources: 1000000` (only collect a source ≥ 1M), `LeaveDeut` (`OnlyOnMoons: true`, `DeutToLeave: 1000000`), `Target[]` (drop celestial), `TargetAssociateMoon: false`, `CargoType: LargeCargo`, `RandomOrder: false`, `SkipIfIncomingTransport: true`, `CheckIntervalMin/Max: 3000/6000` s (infrequent).
- **M27 — BuyOfferOfTheDay** — `Active: false`, `CheckIntervalMin/Max: 240/360` s; buys the daily Trader/Merchant item. (Note: `FEATURES.md` claims "configurable minimum profit threshold" and "buy if exchange rate is profitable", but the documented config has **no** profit-threshold field.)
- **M28 — AutoFleepJumpGate** — (key misspelled in config) `Active: false`, `CheckIntervalMin/Max: 5/10` s, `Target[]` (destination moons), `MinimumAmountOfShipsToBeLeftOnMoon` (per ship type, e.g. `Deathstar: 100000` keeps all), `MinimumAmountOfShipPointsToTeleport: 500000` (don't jump unless moving fleet ≥ 500k points).
- **M29 — Expeditions** — `IgnoreSleep: false`, `MinWaitNextFleet/MaxWaitNextFleet: 5/15` s, `PrimaryShip: LargeCargo`, `MinPrimaryToSend: 1000`, `PrimaryToKeep: 10000`, `SecondaryShip: Null`, `SecondaryToPrimaryRatio: 2`, `WaitForAllExpeditions: false`, `WaitForMajorityOfExpeditions: false`, `MinWaitNextRound/MaxWaitNextRound: 30/300` s, `SplitExpeditionsBetweenSystems` (`Active: false`, `Range: 1`), `RandomizeOrder: true`, `FuelToCarry: 20000`, `MaxExpeditionsPerOrigin: 1`, `Origin[]`.
- **M30 — AutoFarm** — `ExcludeMoons: true`, `ScanRange[]`, `KeepReportFor: 1440` min (re-spy after 24h), `NumProbes: 52`, `TargetsProbedBeforeAttack: 30`, `CargoType: LargeCargo`, `FleetSpeed: 100` (%), `MinCargosToKeep: 0`, `MinCargosToSend: 25`, `CargoSurplusPercentage: 10`, `BuildCargos/BuildProbes: false`, `MinimumResources: 1000000`, `MinimumPlayerRank: 500`, `MaxFlightTime: 7200` s, `MaxWaitTime: 7200` s, `MinLootFuelRatio: 0.5`, `PreferedResource: ""` (metal|crystal|deuterium), `MaxSlots: 18`, `SlotsToLeaveFree: 1`, `CheckIntervalMin/Max: 30/60` s, `StopAfterFullScan: true`.
- **M31 — AutoFarm blacklist** — `Active: true`, `ResetAfterHours: 20`, `MinimumResourcesToNotBlacklist: 1000000`, `ProcessAllReports: true`.
- **M32 — AutoHarvest** — `HarvestOwnDF: true`, `HarvestDeepSpace: false`, `MinimumResourcesOwnDF: 300`, `MinimumResourcesDeepSpace: 50000`, `MaxSlots: 5`, `CheckIntervalMin/Max: 30/60` s.
- **M33 — AutoColonize** — `Origin` (single coordinate), `SlotsToLeaveFree: 0`, `RandomPosition: false`, `CheckIntervalMin/Max: 900/1200` s; `Targets[]` (`Galaxy`, `StartSystem/EndSystem`, `StartPosition/EndPosition` 1–15, `MaxPlanets`).
- **M34 — AutoColonize Abandon** — `Active: true`, `MinFields: 280`, `MinTemperatureAcceptable: -130` °C, `MaxTemperatureAcceptable: 260` °C (abandon + recolonize if outside).
- **M35 — AutoColonize IntensiveResearch** — `Active: false`, `MaxSlots: 10`, `MinWaitNextFleet/MaxWaitNextFleet: 25/55` s.
- **M36 — AutoDiscovery** — `Origin[]`, `MaxSlots: 5`, `MaxFailures: 5`, `RandomizeDestination: false`, `CheckIntervalMin/Max: 30/60` s.
- **M37 — FleetAnalyzer** — `ScanRange[]`, `NumProbes: 5`, `WaitAfterProbesSeconds: 120`, `ScanActivePlayers/ScanInactivePlayers: true`, `MinimumResources: 1000000`, `AttackerFleet` map, `AttackerTechs` (weapons/shield/armour = 10), `MinPowerRatio: 1.5`, `MaxSlots: 10`, `ProbeCacheHours: 2`, `CheckIntervalMin/Max: 300/600` s.
- **M38 — Slot priority** — `SlotsToLeaveFree: 1` global reserve; `SlotPriorityLevel` {Expeditions 1, Brain 2, AutoFarm 3, AutoColonize 4, AutoDiscovery 10, AutoHarvest 99}.
- **M39 — Proxy** — `Enabled: false`, `Address`, `Type: socks5|http`, `LoginOnly: true` (only lobby handshake proxied).
- **M40 — Captcha** — manual at `/bot/captcha`; auto with `General.CaptchaAPIKey` (Ninja Captcha).
- **M41 — License** — no key ⇒ auto 3-day trial; trial expired ⇒ 15-min demo mode; paid lapse ⇒ 3-day grace; offline cache 3 days; background periodic re-validation.
- **M42 — Telegram heartbeat** — `TelegramAutoPing.Active: true`, `EveryHours: 1`.

---

## Notable concerns

- **Gate 1 (no static, hardcoded AI) — heavily violated by design.** The object universe is encoded in config/code: building names & level caps (`MaxMetalMine`, `MaxNaniteFactory`, `MaxLunarBase`…), tech names & caps (`MaxEnergyTechnology`…`MaxArmourTechnology`), defence types (`RocketLauncher`, `HeavyLaser`, `GaussCannon`, `PlasmaTurret`), ship-type enum keys (15 hardcoded ship strings), and lifeform tech slots (`MaxTechs11…36`). Adding a host object requires a config/version edit — exactly what OGameX Gate 1 forbids. Also `AutoResearch`/`AutoMine` carry policy ("prioritize astrophysics/plasma") as hardcoded booleans.
- **Gate 2 (over-engineering)** — a very large config surface (~40 mechanisms, hundreds of fields) and a full battle simulator (FleetAnalyzer) duplicating game combat math; a stats dashboard with per-instance charts; a 4-step setup wizard. It is a mature commercial product, not a "smallest mechanism" design — but note this is reference material, not OGameX code.
- **Gate 3 (human behaviour)** — designed for 24/7 unattended operation with sub-minute polling; authenticity here is *anti-detection camouflage* (random jitter, random order, `RandomActivity`, device fingerprint, proxies, captcha solving), not genuine human-likeness. `SleepMode` is the only authentic human-shaped pattern. The `RandomActivity` + fingerprint + proxy features are explicit bot-masking, the inverse of the OGameX authenticity goal.
- **`BuyOfferOfTheDay` doc/code mismatch:** FEATURES.md promises a profitability threshold; the config reference shows none.
- **`AutoFleepJumpGate` typo** is the real config key (documented as intentional).
- **Naming/consistency:** "AutoFleetJumpGate" (feature name) vs `AutoFleepJumpGate` (key); `PreferedResource` (sic).
- **No source to audit:** all algorithms (ROI math, battle sim, fleet-save timing, expedition "optimal fleet calculation") are binary-only; thresholds above are the *documented* defaults, which may drift from shipped behavior.

---

## Confidence

- **High** — repository inventory (only markdown/docs committed, no source), config field names/defaults, feature list, license/trial mechanics, and the discrete thresholds quoted above, because they are stated verbatim across the README, `docs/*.md`, and the Wiki reference pages (each "3 revisions", last edited Jun–Jul 2026).
- **Medium** — scheduling internals (worker architecture is inferred from hot-reload + "worker restart" + randomized intervals language) and exact algorithm formulas (ROI, loot scoring, battle simulation) — documented as *behavior*, not code; no `.cs` exists to confirm.
- **Low/unknown** — actual ship/build constants used at runtime, exact HTTP endpoints of the engine beyond `/bot/captcha` and `/bot/expedition-messages`, and any undocumented safety rails inside the proprietary binaries.
- **Explicitly absent (written as "none"):** committed source code, tests, CI config, database schema, and any public `.cs`/`.json` configuration — none exist in the repo.

---

*Sources: `README.md`, `CHANGELOG.md`, `THIRD-PARTY-NOTICES.md`, `docs/FEATURES.md`, `docs/FAQ.md`, `docs/TERMS_OF_USE.md`, and Wiki pages `Home`, `Configuration-Overview`, `Reference-settings-json`, `Reference-Credentials-and-General`, `Reference-SleepMode-and-Defender`, `Reference-Brain`, `Reference-Fleet-Features`, `Feature-Guides`, `WebUI-Guide`, `Captcha-and-Proxy`, `License-and-Trial`, `Troubleshooting`.*