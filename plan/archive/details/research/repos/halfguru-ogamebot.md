I have enough to write the complete document. Here it is.

---

# `halfguru/ogamebot` — Exhaustive Research Report

## Overview

`halfguru/ogamebot` ("OGameX Bot") is an open-source **Go** automation bot targeting **OGameX** (`lanedirt/OGameX`), the open-source OGame clone — not the official OGame. MIT license, 0 stars / 1 watcher / 2 forks, single author (`halfguru`). Last commit: "docs: add detailed How It Works section" (`9b722e8`, ~4 months before 2026-09-17). Languages: Go 82.6%, TypeScript 10.4%, CSS 4.8%, HTML 2.2%. No releases published.

Declared features (README):
- **Fleet Safety** — detects incoming attacks, auto-saves fleet with "phalanx-safe" deploy + recall
- **Auto-Build** — ROI-based upgrades, max-level caps
- **Auto-Farm** — galaxy scan → espionage → attack inactives
- **Auto-Colonize** — scan for empty positions, dispatch colony ships
- **Web Dashboard** — SolidJS SPA with REST + WebSocket
- **Anti-Detection** — randomized intervals, jitter, configurable rate limiting (see concerns — rate limiting is not actually wired)
- **Zero-Ops** — SQLite, single Go binary

It targets OGameX specifically because (per `.planning/PROJECT.md`) "OGameX has no anti-bot protections, making it an ideal automation target." Auth is Laravel Fortify session + CSRF; game actions go through AJAX POST endpoints.

## Architecture & entry points

Entry point `cmd/bot/main.go` — startup sequence (comment: "config → logger → DB → rate limiter → client → login → state manager → wait for shutdown", though the rate limiter step no longer exists):
1. `slog` bootstrap → `config.Load("config.yaml")` → charmbracelet/log handler
2. `os.MkdirAll("data", 0755)`; SQLite at `data/bot.db` via `state.OpenDB`
3. `ogamex.NewClient(cfg.OGameX.URL, email, password)` → `Login(ctx)`; exits 1 on failure
4. `state.NewManager(db, client, log)` → `go stateMgr.Run(ctx)`
5. Conditionally starts workers as goroutines: `builder` (if `AutoBuild.Enabled`), `defender`, `farmer`, `colonizer`, and the `dashboard` server; each gets a `Broadcaster`
6. Blocks on `SIGINT`/`SIGTERM`, then `cancel()`

Packages:
- `internal/ogamex/` — HTTP client (26-method `ClientInterface` in `client.go`): `session.go` (login/CSRF), `transport.go` (doGet/doPost/doAJAX, retry, re-auth), `parser.go` (goquery HTML), `planets.go`, `fleet.go`, `fleet_dispatch.go`, `build.go`, `galaxy.go`, `espionage.go`, `global.go`
- `internal/state/` — SQLite cache (`manager.go`, `db.go`, `migrations/`)
- `internal/defender/` — fleet-save worker (`defender.go`) + escape-route calc (`escape.go`)
- `internal/builder/` — auto-build worker (`builder.go`) + pure-math ROI engine (`roi.go`)
- `internal/farmer/` — farming worker (`farmer.go`, `types.go`)
- `internal/colonizer/` — colonizing worker (`colonizer.go`)
- `internal/config/`, `internal/constants/`, `internal/model/`, `internal/dashboard/` (REST + WS hub + embedded static SolidJS)
- `packages/dashboard/` (SolidJS SPA), `packages/shared/` (TS constants + schemas)

Key boundary (from `.planning/research/ARCHITECTURE.md`): workers read state from `StateReader` (SQLite) but call `ClientInterface` directly for actions (`SendFleet`, `BuildBuilding`) and for time-sensitive reads (`GetAttacks`, `GetShips`, `GetSlots`). `state.Manager` is the only component that polls data on a 60s cycle.

## Scheduling & loop model

- **State manager**: `const defaultRefreshInterval = 60 * time.Second` (`state/manager.go`); `Run` does an initial `refresh()` then a `time.Ticker` loop; on refresh failure it logs and keeps the cached data. `refresh()` fetches planets, server speed (cached once), fleets, research, then per-planet resources/buildings/facilities, then upserts. Workers can force a synchronous refresh via `RefreshNow(ctx)`.
- **Every worker** (`defender.go`, `builder.go`, `farmer.go`, `colonizer.go`) uses the same `Run` loop:
  ```go
  jitter := time.Duration(rand.Intn(int(interval.Milliseconds()/2)+1)) * time.Millisecond
  waitTime := interval + jitter
  ```
  i.e. sleep `interval + rand[0, interval/2)` between `poll()` calls, bounded by `ctx.Done()`.
- `interval` comes from `cfg.PollIntervalMs`. Example config: defender 30000, autoBuild 30000, autoFarm 300000, colonizer 120000.
- **No default poll interval in code**: `FeatureConfig.PollIntervalMs int` has no fallback; `AutoBuildDefaults()`/`AutoFarmDefaults()`/`ColonizerDefaults()`/`DefenderDefaults()` set everything *except* `PollIntervalMs`. Validation only errors when `PollIntervalMs > 0 && < min`. A user enabling a feature with `pollIntervalMs` unset gets `interval == 0` → `jitter == 0` → an **unbounded busy loop** hammering OGameX. This is a real edge-case bug.

## Decision engine & algorithms

### Builder (`internal/builder/builder.go` + `roi.go`)

`poll()` refreshes state, then runs tiers in fixed order, returning after the first success and trying `tryResearch` after each:
1. **Energy** (`tryEnergy`) — if any planet has `Energy < 0`, candidates are Solar Plant / Fusion Reactor (sorted by ROI)
2. **Mines** (`tryMines`) — ROI over Metal Mine, Crystal Mine, Deuterium Synthesizer across all planets; highest ROI wins
3. **Infrastructure** (`tryInfrastructure`) — fixed order Research Lab → Robotics Factory → Shipyard → Nanite Factory (skips if maxed/prereq not met)
4. **Research** (`tryResearch`) — first research in `researchOrder` whose prerequisites and max-level are satisfied, started on the planet with the highest-level Research Lab
5. **Storage** (`tryStorage`) — build storage when a resource is ≥ 80% of capacity

ROI core (`roi.go`, pure functions):
- `BuildingCost`: `baseCost * factor^(level-1)` (verified against OGameX `baseLevelable.go`)
- `CalculateROI`: `totalCostValue = metal + 1.5*crystal + 2.0*deuterium`; `roiScore = productionIncrease / totalCostValue`; trade ratios metal=1, crystal=1.5, deuterium=2.0
- Production formulas (hourly): `MetalProduction = 30*(1+plasma/100)*speed*level*1.1^level + 30*speed`; `CrystalProduction = 20*speed*(1+plasma*0.0066)*level*1.1^level + 15*speed`; `DeuteriumProduction = 10*(1+plasma*0.0033)*level*1.1^level*(-0.004*avgTemp+1.36)*speed` (avgTemp = `(TempMin+TempMax)/2`)
- `ConstructionTime` hours = `(metal+crystal)/(2500*(1+robotics)*speed*2^nanite)`, floor, min 1s
- Energy: mines `ceil(10*level*1.1^level)`, deut `ceil(20*level*1.1^level)`, solar `floor(20*level*1.1^level)`, fusion `round(30*level*(1.05+0.01*energyTech)^level)`
- **Non-production ROI magic numbers** (`productionIncrease`): solar/fusion energy valued at `0.5` metal-eq/kWh, storage `0.1`, robotics `0.3`, shipyard `0.2`, research lab `0.25`, nanite `0.4`
- Storage capacity (`tryStorage`): `5000 * 2^level` (base 10000 when `level<=0`)
- `BuildingDefs` map hardcodes base costs + factors per building; `ResearchDefs` hardcodes base costs + factors per research (all factor `2.0` except Astrophysics `1.75`)

Spend-guards: builder fetches **live** resources before executing (`client.GetResources`), tracks a `spent` map across tiers in one poll, and applies cooldowns (`cooldown` map): **10 minutes after a failed build**, **2 minutes after a successful one**.

### Defender (`internal/defender/defender.go` + `escape.go`)

`poll()` → `RefreshNow`, `GetAttacks`; if none, return; else `handleAttacks` + `processRecalls`.

- `identifyEndangered`: dangerous missions `{1 Attack, 2 ACS Attack, 9 Moon Destruction, 10 Missile Attack}`; groups by destination coords, resolves against own planets; skips when `timeUntilAttack < safetyMarginMs + minReactionDelayS` (logs "Attack too close to react safely")
- `savePlanet` (in a goroutine): duplicate-active check → `calcReactionDelay` → sleep → slot check (`InUse >= Total` aborts) → resources/ships/research → `CalcEscapeRoutes` → build `SendFleetRequest` (mission `Deploy`=3, coordType planet=1/moon=3) → `SendFleet` → record event
- `calcReactionDelay`: `maxAllowedDelay = timeUntilAttack - safetyMargin`; if `< minDelay` return 0 (no save); else random in `[minReactionDelayS, maxAllowedDelay)`
- Recall: `recallAt = attackArrival + 30s`, **only** if return flight `<= maxReturnFlightS` (default 600s); `processRecalls` runs `CancelFleet` on events where `recall_at <= now`

Escape-route engine (`escape.go`), pure math:
- `CalcDistance`: same system `abs(pos1-pos2)`; same galaxy `5*abs(sys1-sys2)+abs(pos1-pos2)`; cross-galaxy `20000*abs(gal1-gal2)`
- `flightDuration` = `round((3500/speed * sqrt(distance*10/slowestSpeed) + 10) / universeSpeed)` seconds, with `universeSpeed` **hardcoded = 1** (`const universeSpeed = 1 // Will be configurable later`); distance 0 → 10s
- `fuelConsumption` = Σ `baseFuel * count * (distance/35000) * ((speed/10 + 1)/2)^2`
- `effectiveSpeed`: combustion `*(1+0.1*level)`, impulse `*(1+0.2*level)`, hyperspace `*(1+0.3*level)`; special upgrades: SmallCargo→impulse@5 (10000), Recycler→impulse@17 (4000), Bomber→hyperspace@8 (6000)
- `CalcEscapeRoutes`: for each own planet ≠ origin, for speed 10→1, skip if fuel > available deut; `SafetyScore` (lower=safer): `+1000` dest under attack, `+500` planet / `-100` moon, `+distance/50`, `+fuel/10000`; sorted ascending; `routes[0]` chosen
- `shipDB` hardcodes base speed/fuel/drive/cargo per ship (202–215); SolarSatellite 212 excluded

### Farmer (`internal/farmer/farmer.go`)

Cycle: `poll()` = slots → `scanGalaxies` → `spyTargets` → `evaluateReports` → `attackTargets`.

- `isInactiveTarget`: `pos.Name != "" && pos.Inactive && !pos.Vacation && !pos.Banned`
- `spyTargets`: caps **10 probes per cycle**; sends `MaxProbesPerTarget` (default 5) espionage probes (ship 210) at speed 10 from the closest own planet
- `evaluateReport`: plunder ratio `0.5` on each resource; `totalValue = metal + 1.5*crystal + 2.0*deut`; `netProfit = totalValue - estimatedFuel`; viable iff `netProfit >= MinProfitThreshold` (default 10000); `skipDefended` drops reports with any defense
- `attackTargets`: `maxAttacks = min(MaxAttacksPerCycle, availableSlots-2)` (reserves 2 slots for defender); only Small Cargo; `cargoNeeded = ceil(totalLoot/5000)`; mission Attack(1), speed 10, no resource loading

### Colonizer (`internal/colonizer/colonizer.go`)

- Stops if `len(planets) >= TargetPlanetCount` (default 8) or `len(planets) >= maxColonies` where `maxColonies = 1 + research.Astrophysics`
- Finds a planet with `ColonyShip > 0`; scans `±scanRadius` systems around home for empty positions 1–15; score = `len(preferPositions) - index` (default preferPositions `[4,5,6,7,8]`); sorts by score desc then distance asc; sends up to `MaxAttempts` (default 3) colony ships (mission Colonize=7, speed 10)

## Data model & persistence

SQLite via `modernc.org/sqlite` (pure Go, no CGo). `state/db.go` opens with `journal_mode=WAL` + `foreign_keys=1`, and **`SetMaxOpenConns(1)`** (single writer). Migrations embedded (`//go:embed migrations/*.sql`) and applied via a `schema_migrations` tracking table.

Tables (from migrations 001–007):
- `planets` — id, name, galaxy/system/position, is_moon, diameter, fields_used/total, temperature_min/max
- `resources` — planet_id PK, metal/crystal/deuterium/energy (+ storage + per-hour production columns added in 006)
- `buildings` — metal_mine, crystal_mine, deuterium_synthesizer, solar_plant, fusion_reactor, metal_storage, crystal_storage, deuterium_tank
- `facilities` — robotics_factory, shipyard, research_lab, alliance_depot, missile_silo, nanite_factory, terraformer, space_dock
- `research` — singleton row `id=1` with all 16 tech columns
- `fleets` — id, mission, return_flight, origin/dest coords, metal/crystal/deuterium, arrival_time (full delete+reinsert each refresh)
- `fleet_save_events` (002) — planet_id, fleet_id, dest_planet_id, attack_id, sent_at, recall_at, completed, recalled; partial index `idx_fleet_save_planet_active`
- `build_events` (003) + build_time column (005)
- `farm_targets` + `farm_attacks` (004)
- `colonize_events` (007)

## Config surface

`config.yaml` (YAML, `${ENV_VAR}` interpolation; `config.example.yaml`). Code defaults in `internal/config/config.go`:

| Key | Default | Notes |
|---|---|---|
| `ogamex.url` / `email` / `password` | env or required | `Validate` errors if empty |
| `features.defender.enabled` | false | |
| `features.defender.safetyMarginMs` | 120000 | validated ≥ 10000 |
| `features.defender.recallEnabled` | true | pointer-bool, `IsRecallEnabled()` |
| `features.defender.maxReturnFlightS` | 600 | recall only if return flight ≤ this |
| `features.defender.minReactionDelayS` / `maxReactionDelayS` | 30 / 120 | min ≥ 5s; max ≥ min |
| `features.autoBuild.enabled` | false | poll ≥ 10000 if set |
| `features.autoBuild.maxLevels` | see below | validated [1,100] |
| `features.autoBuild.planetOverrides` | nil | per-planet-name overrides |
| `features.autoBuild.researchOrder` | see below | 15-tech default list |
| `features.autoFarm.enabled` | false | poll ≥ 60000; requires ≥1 `galaxyRanges` |
| `features.autoFarm.galaxyRanges` | nil | `{galaxy, systemStart, systemEnd}` |
| `features.autoFarm.minProfitThreshold` | 10000 | metal-equivalent |
| `features.autoFarm.maxProbesPerTarget` | 5 | |
| `features.autoFarm.maxAttacksPerCycle` | 3 | |
| `features.autoFarm.skipDefended` | false | |
| `features.colonizer.enabled` / `targetPlanetCount` | false / 8 | count validated [2,18] |
| `features.colonizer.preferPositions` | `[4,5,6,7,8]` | |
| `features.colonizer.scanRadius` | 50 | validated ≥ 10 |
| `features.colonizer.maxAttempts` | 3 | |
| `rateLimit.defaultMinDelayMs` / `defaultMaxDelayMs` | 2000 / 5000 | min ≥ 500; max ≥ min |
| `rateLimit.endpointOverrides` | {} | per-endpoint min/max |
| `dashboard.enabled` / `port` / `corsOrigins` | true / 3000 / `["*"]` (code) | example shows `localhost:5173` |
| `logLevel` | `"info"` | |

Default `maxLevels` (code): MetalMine 30, CrystalMine 28, DeuteriumSynthesizer 26, SolarPlant 26, FusionReactor 20, RoboticsFactory 10, Shipyard 12, ResearchLab 12, NaniteFactory 5, Metal/Crystal/DeuteriumStorage 15, EnergyTechnology/CombustionDrive/ImpulseDrive/HyperspaceTechnology/HyperspaceDrive/PlasmaTechnology/EspionageTechnology/ComputerTechnology/Astrophysics/WeaponTechnology/ShieldingTechnology/ArmourTechnology 20, Laser/IonTechnology 15, IntergalacticResearchNetwork 10.

Default `researchOrder`: EnergyTechnology, CombustionDrive, ImpulseDrive, ComputerTechnology, HyperspaceTechnology, HyperspaceDrive, PlasmaTechnology, EspionageTechnology, Astrophysics, LaserTechnology, IonTechnology, WeaponTechnology, ShieldingTechnology, ArmourTechnology, IntergalacticResearchNetwork.

## Edge cases & failure handling

- **HTTP retry/backoff** (`transport.go`): `maxRetries = 3`, `retryBaseDelay = 1s`, `retryMaxDelay = 10s`, exponential `1<<attempt`; retryable statuses 500/502/503; permanent errors (request build, body read) abort immediately
- **Re-auth**: GET redirect-to-`/login` → `Login()` + one retry; POST 401/login URL → re-login + retry; POST **419 or 423 (Locked)** → re-login + retry once
- **CSRF rotation**: `tryRefreshToken` picks `newAjaxToken` from every JSON response; token access is mutex-guarded (`mu sync.Mutex`); POST retries once with a fresh token on mismatch
- **State refresh failures**: logged, cached data kept ("stateless-safe, restartable"); per-planet errors don't abort the whole refresh
- **Builder**: full planet (`FieldsUsed >= FieldsTotal`) skipped; active-construction planets skipped when a free slot is required; insufficient live resources → skip (not error); cooldowns 10 min (fail) / 2 min (success)
- **Defender**: too-late attack → no save (fleet exposed); no slots → abort; no ships → abort; **no safe destination → logs `NO SAFE DESTINATION FOUND` and does nothing**
- **Farmer**: no slots → skip cycle; no inactives → skip; skips defended targets if configured; reserves 2 slots; caps probes at 10/cycle
- **Colonizer**: no colony ship → skip; no empty positions → skip
- **`SendFleet` always returns `(0, nil)`** on success (`fleet_dispatch.go` ends `return 0, nil`; `sendFleetResponse` has no fleet-ID field). This breaks fleet-ID tracking downstream (see concerns).

## Anti-detection & authenticity

Minimal, and by design (planning docs state OGameX has no anti-bot protections):

- Poll jitter `rand[0, interval/2)` on all four workers (Go `math/rand`, not crypto)
- Defender reaction delay `[30, 120]s` default, capped by remaining safety margin — arguably the only human-fidelity feature
- Builder `antiDetectPct = 0.07`: with 7% probability picks the **2nd-best** ROI candidate instead of the best ("Anti-detection: picking 2nd-best ROI candidate")
- **Rate limiting is claimed but not implemented**: `RateLimitConfig` exists and is validated (min delay ≥ 500ms), but `transport.go` (`doGet`/`doPost`) applies **no inter-request delay**, and `main.go` creates no rate limiter. The `.planning/research/ARCHITECTURE.md` Phase 7/8 explicitly say "Remove rate limiter creation from main.go" and "Remove rate limiter code (not needed for OGameX)". So the README's "default 2-5 seconds" and `endpointOverrides` are dead config.
- No proxies, no fingerprinting, no captcha handling, no sleep-time/day-shape modeling, no social masking.

## Discrete mechanisms

- **M01** — State manager refreshes every **60s** (`defaultRefreshInterval = 60*time.Second`; `state/manager.go`)
- **M02** — Every worker sleeps `interval + rand[0, interval/2)` per cycle (`Run` loops in defender/builder/farmer/colonizer)
- **M03** — Defender treats missions **{1 Attack, 2 ACS Attack, 9 Moon Destruction, 10 Missile Attack}** as dangerous (`identifyEndangered`, `defender.go`)
- **M04** — Defender skips an attack if `timeUntilAttack < safetyMarginMs + minReactionDelayS` (`minTimeRequired` check)
- **M05** — Reaction delay = random in `[minReactionDelayS, timeUntilAttack - safetyMarginMs)`; returns **0 = no save** if too late (`calcReactionDelay`)
- **M06** — Fleet-save recall time = `attackArrival + 30s`, only if return flight `<= maxReturnFlightS` (default 600s) (`savePlanet`)
- **M07** — Escape-route safety score (lower better) = `+1000` dest-under-attack, `+500` planet / `-100` moon, `+distance/50`, `+fuel/10000`; pick `routes[0]` (`escape.go`)
- **M08** — Escape speeds tried **10→1**; a speed is skipped if `fuel > availableDeuterium`; loads all Metal/Crystal and `deut - fuel` (`CalcEscapeRoutes`)
- **M09** — OGame distance: same system `|Δpos|`; same galaxy `5*|Δsys| + |Δpos|`; cross-galaxy `20000*|Δgal|` (`CalcDistance`)
- **M10** — Flight time = `round((3500/speed * sqrt(distance*10/slowestSpeed) + 10)/universeSpeed)` s; `universeSpeed` hardcoded **1**; distance 0 → 10s (`flightDuration`)
- **M11** — Fuel = Σ `baseFuel * count * (distance/35000) * ((speed/10 + 1)/2)^2` (`fuelConsumption`)
- **M12** — Ship speed bonus: combustion `×(1+0.1·lvl)`, impulse `×(1+0.2·lvl)`, hyperspace `×(1+0.3·lvl)`; SmallCargo→impulse@5 (10000), Recycler→impulse@17 (4000), Bomber→hyperspace@8 (6000) (`getShipEffectiveStats`)
- **M13** — Builder tier order: **energy → mines → infrastructure → research → storage** (`poll`)
- **M14** — `roiScore = productionIncrease / (metal + 1.5·crystal + 2.0·deuterium)` (`CalculateROI`)
- **M15** — Metal prod = `30·(1+plasma/100)·speed·lvl·1.1^lvl + 30·speed`; Crystal = `20·speed·(1+plasma·0.0066)·lvl·1.1^lvl + 15·speed`; Deut = `10·(1+plasma·0.0033)·lvl·1.1^lvl·(−0.004·avgTemp+1.36)·speed` (`roi.go`)
- **M16** — Building/research cost = `base · factor^(level−1)` (`BuildingCost`)
- **M17** — Build time (hours) = `(metal+crystal)/(2500·(1+robotics)·speed·2^nanite)`, floor, min 1s (`ConstructionTime`)
- **M18** — Energy: mines `ceil(10·lvl·1.1^lvl)`, deut `ceil(20·lvl·1.1^lvl)`, solar `floor(20·lvl·1.1^lvl)`, fusion `round(30·lvl·(1.05+0.01·energyTech)^lvl)`
- **M19** — Non-mine "production" heuristics for ROI: solar/fusion `0.5`/kWh, storage `0.1`, robotics `0.3`, shipyard `0.2`, research lab `0.25`, nanite `0.4` (`productionIncrease`)
- **M20** — Build storage when a resource `>= 0.8 · capacity`, capacity = `5000 · 2^level` (base 10000) (`tryStorage`/`storageCapacity`)
- **M21** — Builder anti-detect: **7%** chance to pick 2nd-best ROI candidate (`antiDetectPct = 0.07`)
- **M22** — Builder cooldowns: **10 min** after failed build, **2 min** after successful build (`executeBuild`)
- **M23** — Research starts the first eligible tech in `researchOrder` on the highest-Research-Lab planet (`tryResearch`)
- **M24** — Farm target = `name != "" && inactive && !vacation && !banned` (`isInactiveTarget`)
- **M25** — Farmer sends ≤ **10** probe fleets/cycle, `maxProbesPerTarget` (default 5) probes each, speed 10, from closest planet (`spyTargets`)
- **M26** — Plunder ratio **0.5**; loot value = `metal + 1.5·crystal + 2.0·deut` (`evaluateReport`)
- **M27** — Attack iff `netProfit (= totalValue − fuel) >= minProfitThreshold` (default 10000)
- **M28** — Max attacks = `min(maxAttacksPerCycle, availableSlots − 2)` (reserve 2 slots) (`attackTargets`)
- **M29** — Cargo = `ceil(totalLoot / 5000)`, Small Cargo only (`cargoNeeded`)
- **M30** — Farmer fuel estimate = `10 · count · (distance/35000) · ((speed/10 + 1)/2)^2`, speed 10 (`estimateFuelCost`)
- **M31** — Colonizer stops at `maxColonies = 1 + Astrophysics` or `targetPlanetCount` (default 8) (`colonizer.poll`)
- **M32** — Colonizer position score = `len(preferPositions) − index`; sort score desc → distance asc; send ≤ `maxAttempts` (3) colony ships, mission 7, speed 10
- **M33** — CSRF from `<meta name="csrf-token">`; refreshed from `newAjaxToken` in every JSON response; mutex-guarded (`session.go`/`transport.go`)
- **M34** — Retry: 3 attempts, exponential 1s→10s cap; retryable 500/502/503; re-login on 401/login-redirect/419/423 (`transport.go`)
- **M35** — Fleet dispatch is two-step: `check-target` (mission must be in `orders`) then `send-fleet` (`fleet_dispatch.go`)

## Notable concerns

- **`SendFleet` returns `0` as the fleet ID** (`return 0, nil` in `fleet_dispatch.go`; no ID field parsed from the response). Consequently `fleet_save_events.fleet_id`, `farm_attacks.fleet_id`, and the log lines all record `0`. `processRecalls` → `findFleetByID(fleets, 0)` can never match a real fleet, so **deploy-recall completion/mark-recalled likely never works correctly** — this undermines the flagship "phalanx-safe deploy + recall" feature.
- **Gate-1 flag (hardcoded object universe as source of truth)**: the entire OGameX object model — building/ship/mission IDs (`internal/constants/*.go`), base costs + growth factors (`BuildingDefs`, `ResearchDefs` in `roi.go`), research/building prerequisites (`researchPrerequisites`, `buildingPrerequisites`), production/energy/build-time formulas, ship stats (`shipDB` in `escape.go`), endpoint ID maps (`resourceBuildingIDs`, `researchIDs` in `build.go`), and default `maxLevels`/`researchOrder` — is hardcoded in Go (and duplicated in `packages/shared/src/constants/*.ts`). Adding a building/ship/tech to OGameX requires editing this code; nothing is read from the host at planning time. This is exactly what the AI module's Gate 1 forbids.
- **Dead rate-limit config**: `rateLimit` settings + validation exist but no delay is applied anywhere; README's "2–5 second" claim is inaccurate for the shipped client.
- **`universeSpeed` hardcoded to 1** in `escape.go` flight-time math, even though the state manager caches the real server speed — fleet-save flight-time/recal estimates are wrong on non-1× universes.
- **No default poll interval** → busy-loop risk if a feature is enabled without `pollIntervalMs` (validation only checks `> 0 && < min`).
- **Farmer fuel/cargo estimate bug**: `evaluateReport` computes `cargoNeeded(totalValue, false)` using the *metal-equivalent* value (deuterium weighted 2×) against 5000 capacity, over-estimating cargo count and therefore fuel — inflating the `netProfit` gating. `attackTargets` correctly uses raw `totalLoot` for the actual cargo count.
- **Gate-3 flags (not what a careful human would do)**: farmer probes 10 inactives at speed 10 then immediately attacks every profitable one in a single cycle; attacks only with Small Cargo; no ship-building; colonizer fires up to 3 colony ships in one poll; builder builds at precise 30s cadence with tiny jitter; no fleet-save failure is ever deliberately simulated. On the positive side, energy-first, prerequisites-before-unlock, and the reaction-delay jitter align with Gate 3.
- **Gate-2 flags**: the 26-method `ClientInterface` and the `0.5/0.3/0.2/0.25/0.4` ROI heuristics for non-production buildings are the main "machinery" smells, plus the duplicated Go/TypeScript constant sets. Overall the architecture is reasonably lean for what it does.

## Confidence

**High** on: overall architecture, entry point, loop/jitter model, config defaults + validation, the full ROI/escape/farming/colonizing formulas and thresholds, the hardcoded ID/price/prerequisite tables, SQLite schema, and the CSRF/retry/re-auth flow — all read directly from source (`cmd/bot/main.go`, `internal/config/config.go`, `internal/builder/{builder,roi}.go`, `internal/defender/{defender,escape}.go`, `internal/farmer/farmer.go`, `internal/colonizer/colonizer.go`, `internal/state/{db,manager}.go` + migrations, `internal/ogamex/{client,transport,session,build,fleet_dispatch}.go`, `internal/constants/*.go`).

**High confidence with one caveat** on two negative findings: (1) `SendFleet` returning `0` — confirmed from the source text (`return 0, nil`, no ID field in `sendFleetResponse`); (2) rate limiting not implemented — inferred from the absence of any delay in `transport.go`/`main.go` plus the planning docs' explicit "remove rate limiter" cleanup steps; I did not find a rate-limiter package anywhere in the file tree.

**Medium** on: exact OGameX-side formula fidelity (the code comments claim formulas were "verified from OGameX source", which I did not independently re-derive); and whether `storageCapacity`'s `5000*2^level` matches OGameX's actual storage formula. I did not read the SolidJS dashboard source or the `.planning/*` docs in full — findings there are from the README and semantic excerpts only.