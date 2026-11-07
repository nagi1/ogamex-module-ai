Research complete. The repository is a tiny, self-contained PHP 5-era formula library — 11 PHP files plus a README, no build system, no tests, no config. Here is the full report.

---

## Overview

`jstar88/Ogame-algorithms` is a single-branch (`master`), single-author repository (11 stars, 2 forks, no releases, no CI). It describes itself in `README.md` (verbatim) as:

> "A set of high quality, well documented Ogame algorithms"

…with an "About" blurb "Ogame hight quality algoritms" (typos preserved). The last meaningful commit is **13 years old** (`80646c6`, "fix param", ~2013). Content is 100% PHP. It is a **pure calculation library**: every public method is a `public static` function taking primitive numeric parameters and returning a number or array. There is no game loop, no planner, no AI, no persistence, no HTTP, no scheduler — nothing but formulas ported from the long-dead OGame "formula thread v3" (`board.ogame.org/.../576831-formula-thread-v3/`, referenced in comments in `Cost.php`, `Espionage.php`, `Plunder.php`).

Complete file inventory:

| Path | Class | Purpose |
|---|---|---|
| `Cost.php` | `Cost` | Build cost / cumulative cost / deconstruction cost |
| `Espionage.php` | `Espionage` | Espionage report level + counter-espionage probability |
| `Plunder.php` | `Plunder` | Cargo plunder splitting (solo + ACS) |
| `mines/Mine.php` | `Mine` | Base class: storage capacity + construction time + zero-default getters |
| `mines/MetalMine.php` | `MetalMine` | Metal production + energy consumption |
| `mines/CrystalMine.php` | `CrystalMine` | Crystal production + energy consumption |
| `mines/DeuteriumSynthesizer.php` | `DeuteriumSynthesizer` | Deuterium production + energy consumption |
| `mines/SolarPlant.php` | `SolarPlant` | Energy production |
| `mines/SolarSatellites.php` | `SolarSatellites` | Energy production from satellites |
| `mines/FusionReactor.php` | `FusionReactor` | Energy production + deuterium consumption |
| `mines/PlasmaTecnology.php` | `PlasmaTechnology` | Plasma-tech bonus to metal/crystal output (filename has a typo) |

---

## Architecture & entry points

- **No `composer.json`, no `vendor/`, no autoloader, no namespace declarations, no PSR-4, no `.gitignore`, no LICENSE file, no tests, no CI config.** This predates Composer-era PHP. Consumers must `require` the files manually.
- The only "dependency" mechanism is relative `require('Mine.php')` inside each `mines/*.php` subclass (e.g. `MetalMine.php` line 5). Note: `require` (not `require_once`) — loading a subclass twice under an autoloader fatal-errors with "cannot redeclare class".
- Every class is effectively a **static utility class**. `Mine` is a plain class (not `abstract`, no `interface`) whose six production/consumption getters hard-return `0` and are overridden per subclass.
- Entry points are the 14 static methods across 11 classes:
  - `Cost::getCumulativeCost`, `Cost::getCost`, `Cost::getDeconstructionCost`
  - `Espionage::getSpyLevel`, `Espionage::getCounterEspionage`
  - `Plunder::splitResources`, `Plunder::splitResourcesAcs`
  - `Mine::getStorageCapacity`, `Mine::constructionTime` (+ 6 zero-default getters)
  - Per-subclass production/consumption getters (see *Discrete mechanisms*).
- No state, no instances, no DI, no configuration passed as globals or constants.

---

## Scheduling & loop model

**None.** There is no loop, no scheduler, no cron, no daemon, no `sleep`/`usleep`, no queue, no event system, and no notion of time progression. The library computes instantaneous values on demand. Time appears only inside one formula as an output unit (per-hour production) and never as an input or driver.

---

## Decision engine & algorithms

There is **no decision engine** — no "choose next build/attack/fleet-save" logic of any kind. The "algorithms" are three stateless formula groups:

1. **Costs** (`Cost.php`), sourced from the formula thread v3 `#costs` section:
   ```php
   public static function getCumulativeCost($baseCost, $costIncreaseFactor, $level)
   {
       return floor($baseCost * (1 - pow($costIncreaseFactor, $level)) / (1 - $costIncreaseFactor));
   }
   public static function getCost($baseCost, $costIncreaseFactor, $level)
   {
       return floor($baseCost * pow($costIncreaseFactor, ($level - 1)));
   }
   public static function getDeconstructionCost($baseCost, $costIncreaseFactor, $levelIonTechnology, $level)
   {
       return floor($baseCost * pow($costIncreaseFactor, ($level - 1)) * (1 - 0.04 * $levelIonTechnology));
   }
   ```
   Costs are **parameterized** by `$baseCost` and `$costIncreaseFactor` — the library does **not** hardcode any object's base price; the caller supplies it.

2. **Plunder** (`Plunder.php`), implementing the documented 5-step OGame plunder algorithm (see *Discrete mechanisms* M08). `splitResourcesAcs` pre-scales the planet's resources by `capacity / totalCapacity` before delegating to `splitResources`.

3. **Espionage** (`Espionage.php`): `getSpyLevel` maps (probes, tech diff) to an integer "what-you-can-see" tier; `getCounterEspionage` returns a probabilistic discovery value.

---

## Data model & persistence

**None.** No database, no schema, no migrations, no models, no files on disk, no session. The only "type" is the PHP `array` returned by `Plunder::splitResources*`:

```php
$steal = array('metal' => 0, 'crystal' => 0, 'deuterium' => 0);
```

Everything else is raw `int`/`float` in, scalar out. There is no input validation of any kind anywhere in the codebase.

---

## Config surface

**None.** There is no config file, no environment variables, no constants class, no global settings, no universe-speed multiplier, no economy-speed factor. Every threshold and coefficient is inlined in the formulas (e.g. `1.1`, `2500`, `0.5`, `0.04`, `140`, `6`, `1.44`, `0.004`). Callers must reimplement any universe/economy scaling themselves.

---

## Edge cases & failure handling

There is **no error handling, no validation, and no documented edge-case policy**. Specific latent defects:

- **Division by zero in `Cost::getCumulativeCost`** — the denominator `(1 - $costIncreaseFactor)` is zero when `$costIncreaseFactor == 1.0`, producing `INF`/division-by-zero warning. No guard.
- **Negative deconstruction cost** — `Cost::getDeconstructionCost` multiplies by `(1 - 0.04 * $levelIonTechnology)` with **no floor at 0**. The docblock says "free at Ion Technology 25", but at level 25 the multiplier is exactly `0`, and **above 25 it goes negative** (level 26 → multiplier `-0.04`). No clamp.
- **`Espionage::getCounterEspionage` doc/code mismatch** — docblock claims the return is "between 0 and 1", but the code divides by **100**:
  ```php
  $theoric = $probes * $ships / (pow(100, ($yourEspionageTechnology - $opponentEspionageTechnology))) + 2;
  return mt_rand(0, floor($theoric)) / 100;
  ```
  For `diff < 0` the denominator `100^diff` is a fraction, so `$theoric` can explode; for `diff > 0` it collapses toward `2`. `mt_rand(0, floor($theoric))` is called with a float second arg and yields `0` whenever `$theoric < 1`. The scale (`/100` vs `/1`) is almost certainly a bug.
- **`Espionage::getSpyLevel` returns non-integers** — `$probes + pow($diff, 2)` / `$probes - pow($diff, 2)` are not floored, so the advertised integer "tier" (≥2/≥3/≥5/≥7) can be fractional; with a large negative tech diff the result can even be **negative**.
- **`Plunder::splitResources` final two fills** follow the original thread exactly but only ever refill metal then crystal — leftover capacity after step 5 is discarded and deuterium is never revisited, even if deuterium remains and metal/crystal are exhausted. Also, the initial `* $percentage` scaling can produce fractional `$metal/$crystal/$deuterium` (no floor/round).
- **`Mine::constructionTime` speed floor** — `max(4 - $level / 2, 1)`: for buildings above level 6 the factor clamps at `1`, i.e. no further slowdown factor from the level term (matches the classic formula's intent).
- **PHP 8 incompatibilities** — PHP 5-era code: no type hints, no `declare(strict_types=1)`, array syntax `array()` instead of `[]`, and `mt_rand` (deprecated in PHP 8.3). No `private`/visibility beyond `public static`.

---

## Anti-detection & authenticity

**None, and not intended.** The single stochastic element is `mt_rand` inside `Espionage::getCounterEspionage`, which models the game's own random discovery chance — not anti-detection. There is no latency shaping, no uptime pattern, no timing jitter, no action-sequence variation, no fleet-save simulation, no social behavior. The library has zero opinion about *when* or *whether* to act; it only answers "what does the formula say given these numbers."

---

## Discrete mechanisms

- **M01 — Metal production** — `MetalMine::getMetalPerHour($level)` returns `30 * $level * pow(1.1, $level)` (`mines/MetalMine.php`).
- **M02 — Crystal production** — `CrystalMine::getCrystalPerHour($level)` returns `20 * $level * pow(1.1, $level)` (`mines/CrystalMine.php`).
- **M03 — Deuterium production** — `DeuteriumSynthesizer::getDeuteriumPerHour($level, $maxTemperature)` returns `10 * $level * pow(1.1, $level) * (1.44 - 0.004 * $maxTemperature)` (`mines/DeuteriumSynthesizer.php`).
- **M04 — Solar plant energy** — `SolarPlant::getEnergyPerHour($level)` returns `20 * $level * pow(1.1, $level)` (`mines/SolarPlant.php`).
- **M05 — Solar satellite energy** — `SolarSatellites::getEnergyPerHour($count, $maxTemperature)` returns `$count * floor(($maxTemperature + 140) / 6)` (`mines/SolarSatellites.php`).
- **M06 — Fusion reactor energy** — `FusionReactor::getEnergyPerHour($fusionReactorLevel, $energyTechnologyLevel)` returns `30 * $fusionReactorLevel * pow((1.05 + $energyTechnologyLevel * 0.01), $fusionReactorLevel)` (`mines/FusionReactor.php`).
- **M07 — Energy consumption** — `MetalMine`/`CrystalMine::getEnergyConsumptions($level)` returns `10 * $level * pow(1.1, $level)`; `DeuteriumSynthesizer::getEnergyConsumptions($level)` returns `20 * $level * pow(1.1, $level)` (`mines/MetalMine.php`, `mines/CrystalMine.php`, `mines/DeuteriumSynthesizer.php`).
- **M08 — Deuterium consumption (fusion)** — `FusionReactor::getDeuteriumConsumptions($level)` returns `10 * $level * pow(1.1, $level)` (`mines/FusionReactor.php`).
- **M09 — Plasma tech metal bonus** — `PlasmaTechnology::getMetalPerHour($level, $metalMineProduction)` returns `$metalMineProduction * 0.01 * $level` (`mines/PlasmaTecnology.php`).
- **M10 — Plasma tech crystal bonus** — `PlasmaTechnology::getCrystalPerHour($level, $baseCrystalProduction)` returns `$baseCrystalProduction * 0.0066 * $level` (`mines/PlasmaTecnology.php`).
- **M11 — Storage capacity** — `Mine::getStorageCapacity($level)` returns `floor(2.5 * exp(20 * $level / 33)) * 5000` (`mines/Mine.php`).
- **M12 — Construction time** — `Mine::constructionTime($metal, $crystal, $level, $roboticsFactoryLevel, $naniteFactoryLevel)` returns `($metal + $crystal) / (2500 * max(4 - $level / 2, 1) * (1 + $roboticsFactoryLevel) * pow(2, $naniteFactoryLevel))` (`mines/Mine.php`).
- **M13 — Cost at level** — `Cost::getCost($baseCost, $costIncreaseFactor, $level)` returns `floor($baseCost * pow($costIncreaseFactor, $level - 1))` (`Cost.php`).
- **M14 — Cumulative cost** — `Cost::getCumulativeCost($baseCost, $costIncreaseFactor, $level)` returns `floor($baseCost * (1 - pow($costIncreaseFactor, $level)) / (1 - $costIncreaseFactor))` (`Cost.php`).
- **M15 — Deconstruction cost** — `Cost::getDeconstructionCost($baseCost, $costIncreaseFactor, $levelIonTechnology, $level)` returns `floor($baseCost * pow($costIncreaseFactor, $level - 1) * (1 - 0.04 * $levelIonTechnology))` (`Cost.php`). Docblock: Terraformers and Lunar Bases cannot be deconstructed; free at Ion ≥ 25; ion discount does not affect deconstruct time.
- **M16 — Espionage visibility level** — `Espionage::getSpyLevel($probes, $yourEspionageTechnology, $opponentEspionageTechnology)` sets `$diff = your - opponent`; returns `$probes + pow($diff, 2)` if `$diff > 0`, else `$probes - pow($diff, 2)`. Docblock tiers: ≥2 resources/activity/ships; ≥3 + defense; ≥5 + buildings; ≥7 + research (`Espionage.php`).
- **M17 — Counter-espionage probability** — `Espionage::getCounterEspionage($probes, $ships, $yourEspionageTechnology, $opponentEspionageTechnology)` computes `$theoric = $probes * $ships / pow(100, $your - $opponent) + 2` and returns `mt_rand(0, floor($theoric)) / 100` (`Espionage.php`).
- **M18 — Plunder split (solo)** — `Plunder::splitResources($capacity, $metal, $crystal, $deuterium, $percentage = 0.5)` scales each resource by `$percentage`, then fills in five steps: (1) `min($capacity/3, $metal)` metal; (2) `min($capacity/2, $crystal)` crystal; (3) `min($capacity, $deuterium)` deuterium; (4) `min($capacity/2, $metal)` metal; (5) `min($capacity, $crystal)` crystal — subtracting each from `$capacity` and the resource pool (`Plunder.php`).
- **M19 — Plunder split (ACS)** — `Plunder::splitResourcesAcs($totalCapacity, $capacity, $metal, $crystal, $deuterium, $percentage = 0.5)` multiplies each resource by `$capacity / $totalCapacity`, then delegates to `splitResources` (`Plunder.php`).
- **M20 — Base-class zero defaults** — `Mine::getMetalPerHour`, `getCrystalPerHour`, `getDeuteriumPerHour`, `getEnergyPerHour`, `getEnergyConsumptions`, `getDeuteriumConsumptions` all return `0` (`mines/Mine.php`).

---

## Notable concerns

- **Age & provenance** — 13+ years old, frozen at the OGame formula thread v3 (`board.ogame.org` links are dead). OGame formulas have since diverged (newer versions, universe/economy speed, redesigned classes like lifeforms). Do not treat these constants as current-game truth without re-verification.
- **No license file** — default "all rights reserved" under copyright law. Copying code verbatim into OGameX is legally unsafe until the author grants a license.
- **Modern PHP incompatibility** — `mt_rand` (deprecated 8.3), no `strict_types`, `array()` syntax, `require` without `_once`, global-namespace class names (`Mine`, `Cost`, `Plunder`, `Espionage`) that can collide with any Composer autoloaded class of the same name.
- **Filename typo** — `mines/PlasmaTecnology.php` (missing "h") vs class `PlasmaTechnology`; harmless for case-sensitive autoloaders that map by class name, but a PSR-4 autoloader would never find the file under the class name.
- **`PlasmaTechnology extends Mine` is semantically odd** — it is a *technology*, not a mine, and inherits storage/construction methods that make no sense for it. Harmless but misleading.

### Gate-1 flag (no static, hardcoded AI)
This library is the **opposite** of the static-id sin: it hardcodes **no object ids, no machine names, no base prices, and no requirements**. Costs (`Cost.php`) are fully parameterized (`$baseCost`, `$costIncreaseFactor`). What it *does* hardcode are **per-object production/consumption coefficients** (`30`, `20`, `10`, `1.1`, `1.44`, `0.004`, `1.05`, `0.01`, `0.0066`, `2.5`, `5000`, `2500`, `0.04`, `140`, `6`) inside subclass methods — if OGameX adopted these classes as-is, each new host object would still need a new module class. Reuse the *formula shapes* (parameterized), not the hardcoded coefficient subclasses.

### Gate-2 flag (over-engineering)
**None.** If anything it is under-engineered — no abstraction beyond the one base class, and even that base class is arguably unnecessary (its only non-trivial methods are storage and construction time). Minor redundancy: `MetalMine` and `CrystalMine` share identical `getEnergyConsumptions` bodies, and `SolarPlant`/`CrystalMine` share the `20 * level * pow(1.1, level)` shape. The parameterized `Cost`/`Plunder`/`Espionage` design is a good template for OGameX's formula layer.

### Gate-3 flag (human-plausible behavior)
**Not applicable.** There is no behavior here — no decisions, no timing, no scheduling, so nothing can violate human plausibility. The only stochastic output (`Espionage::getCounterEspionage` via `mt_rand`) mirrors the game's own mechanic and would be indistinguishable from a human's result. The one caution: any OGameX AI that fed raw `getSpyLevel`/`getCounterEspionage` outputs through an action pipeline would need to remember the `/100` scale bug (M17) before relying on it as a probability.

---

## Confidence

**High (~95%).** The repository is fully enumerated (11 PHP files + README, confirmed via both the GitHub file listing and a `language:php` lexical search returning the same 11 files) and every file's complete source was read from raw URLs, not just the README. No additional branches/tags were reported beyond `master`. Residual uncertainty is limited to (a) whether any historical commit ever contained files later deleted from `master`, and (b) the exact README wording, which was captured verbatim from the rendered page. All formulas, method names, defaults, and docblock thresholds quoted above were taken directly from source.