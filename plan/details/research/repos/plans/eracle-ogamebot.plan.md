# Enhancement plan — eracle/ogamebot

## Verdict summary (one line)
A 2015 Java + Selenium stub (login + DOM resource read + read-only construction-plan parse, no loop, no decision engine, no persistence) whose every real capability is either already host-read in our module or a gate-1/gate-2/gate-3 violation — so the only honest outcome is **nothing to adopt, nothing to enhance**.

## ADOPT-IDEA

None. The repo's only production behaviour is DOM scraping of a browser session; its one non-code idea ("behave as a human being so the provider can't tell it's a bot") is already gate 3's own requirement, which we ship natively in `SessionPlanner`/`NextDueTimeCalculator`/`SaveFailurePolicy` — not as a Selenium evasion hack.

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| — | — | — | — |

## ENHANCE

None. Every mechanism eracle shares with us is strictly worse: hardcoded OGame CSS selectors, naive single-locale `Integer.parseInt` over live DOM text, a buildability check that reads the "Improve" button label, and an empty construction-queue stub that always returns `true`. There is no row where copying a refinement would improve a host-read, human-named implementation.

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| — | — | — | — |

## ALREADY-DONE (confirmed by grep)

- Host-integrated module, no browser driver, no Selenium, no scraping — `grep -i "selenium|webdriver|firefox|browser|scrap" app/` matches only the sidecar driver `baseUrl` configs (`FatimaClient.php`, `CbrKitClient.php`, `AgentOsClient.php`), never a browser. Host services are called directly.
- Read planet resources (metal/crystal/deuterium/energy) from the host, not `resources_metal` DOM ids — `$planet->hasResources(...)`, `$planet->energy()->get()` in `app/Domain/Decision/QueueableBuildingPlanner.php`, `app/Domain/Decision/EnergyCapacity.php`.
- Buildability predicate (the repo's `canBuildMetalMine`, plus its test-only `metal >= required` check) — host-read in `QueueableBuildingPlanner::isQueueable()`: `objectValidPlanetType` + `objectRequirementsMetWithQueue` + `planet->hasResources(...)`.
- Construction-queue space is the host's answer, not a hardcoded `return true` — `QueueableBuildingPlanner.php` comment: "`BuildingQueueService::start()` cancels a queue"; queue availability is enforced host-side.
- Building name / level / price read from the host, not `.supply1/2/3` + `li.tooltip` selectors — `ObjectService::getObjectById/getObjectPrice/getRecursiveRequirements` in `FacilityChain.php`, `EconomyUpgrades.php`, `EnergyCapacity.php`, `QueueableBuildingPlanner.php`.
- Energy-per-level (the repo's `energy_needed` parse) — `EnergyCapacity::energyGainOfNextLevel()` uses the host's own production calculation.
- Human cadence instead of "click → click → parse" instant determinism — `app/Domain/Routine/SessionPlanner.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php`, `app/Domain/Decision/SaveFailurePolicy.php`.

## REFUSE

- M01 login via Selenium (click `#loginBtn`, fill `#usernameLogin`, select universe) — browser scraping; we run host-side, no HTTP browser session exists.
- M02 read resources from `resources_metal/crystal/deuterium/energy` DOM text — obsolete scraping; host `PlanetService` already provides.
- M03 server-URL normalization (`http://` prepend) — irrelevant to a host-integrated module.
- M04 `canBuildMetalMine` = wait for `a.build-it > span` and compare text to `"Improve"` — DOM-label scraping with a documented false-positive; host `isQueueable()`.
- M05 `buildMetalMine()` empty stub, M06 `isEmptyConstructionQueue()` hardcoded `true` — nothing to adopt.
- M07 lazy singleton `ConstructionPlanManager` — a cache of scraped data with no measurement; gate 2.
- M08/M09/M10 DOM navigation `.supply1/.supply2/.supply3`, `menuTable/li[2]` — hardcoded OGame CSS selectors; gate 1.
- M11 parse `#buildDuration` by stripping `"s"` (breaks on any build > 59s) — a real bug, and build time is the host's business, not a scrape.
- M12–M17 parse energy/cost/level/name from `li.tooltip`/`span.level`/`h2` selectors with naive locale `Integer.parseInt` — hardcoded universe + fragile; `ObjectService` already answers.
- M18 test-only buildability predicate — already host-read; its literal form is a gate-1 constant check.
- M19 `driver.quit()` — N/A.
- M20 hardcoded base-cost assertions (`60/15`, `48/24`, `337/112`, "Metal Mine", "Crystal Mine", "Deuterium Synthesizer") — hardcoded object universe in tests; gate 1 (our tests may name today's catalogue, but never as the source of truth).
- "Anti-detection = use Selenium instead of raw HTTP" — an evasion heuristic, not human behaviour; gate 3 forbids machine-shaped play, and our authenticity surface (latency, uptime, self-similarity) is built from host signals instead.
- Three empty `MetalMine/CrystalMine/DeuteriumMine` subclasses — single-implementation abstraction; gate 2.
- Committed live credentials (`cocorito/cocorito`, universe `Ganimed`) — secrets in source, security.
- Selenium 2.46.0 / JDK 1.5 language level — 11 years abandoned; no upgrade path.

## Priority recommendation

Make **no code change**. The repo is a dead stub whose only production behaviour (login, DOM resource read, construction-plan parse) is either already host-read in `QueueableBuildingPlanner`/`FacilityChain`/`EconomyUpgrades`/`EnergyCapacity` or forbidden (Selenium scraping, hardcoded universe). The highest-value action is to close this plan as "adopt nothing": record the negative example in the corpus and move on, rather than spending a slice re-confirming what a grep already proved.

## Open questions / risks

- Build duration: confirm no slice needs a building's *construction time* as a planning input. If one ever does, it must come from the host (queue service / object model), never from a parsed `#buildDuration` string — and never from eracle's broken `strip("s")` pattern.
- Policy exposure: per `gameforge-policy.md`, do not port even the naive `Integer.parseInt`/selector patterns — studying this repo for "what not to do" is fine, "reworking" its code is not.
- The one idea this repo names (human-like behaviour) is already gate 3 and already shipped; any attempt to import its "Selenium = undetectable" framing would be a step backward in authenticity, not forward.
- If a second pass is requested, it will find only more gate-1/gate-3 violations — the repo has 13 files total, all enumerated and read in full.
