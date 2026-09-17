# Research Report: `eracle/ogamebot`

## Overview

`eracle/ogamebot` is a **Java + Selenium WebDriver** OGame bot, self-described as *"Java - Ogame - Selenium - Singularity Bot."* Its stated ambition in `README.md` is large: *"Develop an artificial intelligence that can play with the Ogame browser game and defeat all the human players"*, cooperating with *"other instances of himself through the use of alliances"*, while behaving *"as a human being"* so the provider *"will never be able to track and recognize a singularity robot."*

The reality is a **stub**: a Maven/JUnit/Selenium skeleton from **2015** (last commit `374ece8`, "Construction plans for deuterium and crystal implemented", ~11 years old), with **0 stars, 0 forks, no releases**, GPL-2.0 license. It has:

- **Implemented:** basic login via Selenium, reading metal/crystal/deuterium/energy from the DOM, checking whether a metal mine can be built, and lazily parsing metal/crystal/deuterium **construction plans** (name, level, cost, energy, duration) into in-memory model objects.
- **Not implemented:** anything in the README "Todo" lists — config-file login, building construction, fleet handling, fake messages/social behaviour, and *all* "Advanced Features" (SVM / Neural Network / Ensemble AI, inter-instance cooperation).

There is **no main class, no game loop, no scheduler, no decision engine, no persistence, and no config surface**. It is exactly "login + resource read + read-only plan parse."

## Architecture & entry points

- **Build:** Maven (`pom.xml`), `groupId=it.eracle.ogamebot`, `artifactId=ogamebot`, packaging `jar`, version `1.0-SNAPSHOT`.
- **Dependencies** (`pom.xml`): `junit:junit:4.11`, `org.seleniumhq.selenium:selenium-java:2.46.0`. Nothing else.
- **IDE metadata:** `ogamebot.iml` (IntelliJ), `LANGUAGE_LEVEL="JDK_1_5"`.
- **No entry point.** A lexical search for `public static void main` returns nothing. The project cannot be run; it is exercised only through JUnit tests that drive a real Firefox browser.
- **Production code — 6 classes, 2 of which have behavior:**

```
src/main/java/it/eracle/ogamebot/
  OgameConnection.java                    (5,442 B)  — Selenium wrapper + login + resource reads
  ConstructionPlanManager.java            (6,284 B)  — parses construction plans via DOM
  constructionplans/
    ResourceConstructionPlan.java         (2,778 B)  — abstract model (fields + getters/setters)
    MetalMineConstructionPlan.java        (329 B)    — empty subclass
    CrystalMineConstructionPlan.java      (322 B)    — empty subclass
    DeuteriumMineConstructionPlan.java    (320 B)    — empty subclass
```

- **Tests — 2 files:** `OgameConnectionTest.java` and `ConstructionPlanManagerTest.java`, both hitting the live OGame site with hardcoded credentials.
- **Design paradigm claimed in comments:** MVC. `OgameConnection` = connection/controller, `ConstructionPlanManager` = *"In MVC this is a control class"*, the `*ConstructionPlan` classes = *"Model"*.

`OgameConnection` is the only entry point; its constructor both connects and logs in:

```java
public OgameConnection(String serverUrl, String universe, String username, String password) {
    this.driver = new FirefoxDriver();
    this.constructionPlanManager = null;
    if(!serverUrl.equals("") && !serverUrl.startsWith("http://"))
        serverUrl="http://"+serverUrl;
    driver.get(serverUrl);
    driver.findElement(By.id("loginBtn")).click();
    driver.findElement(By.id("usernameLogin")).sendKeys(username);
    driver.findElement(By.id("passwordLogin")).sendKeys(password);
    new Select(driver.findElement(By.id("serverLogin"))).selectByVisibleText(universe);
    driver.findElement(By.id("loginSubmit")).click();
}
```

## Scheduling & loop model

**None.** There is no `while`, no `Thread`, no `Timer`, no scheduled executor, and no cron-like mechanism anywhere in the repo. A lexical search for `fleet|attack|espionage|galaxy|save|Thread|sleep|Timer|Schedule` across the repo returns nothing relevant. Every action is a single synchronous method call triggered manually from a test. The "bot" never runs autonomously and never repeats an action on its own.

## Decision engine & algorithms

There is exactly **one** decision, and it is a DOM text comparison — not a calculation:

`OgameConnection.canBuildMetalMine()`:
```java
driver.findElement(By.linkText("Resources")).click();
driver.findElement(By.id("details")).click();
WebElement myDynamicElement = (new WebDriverWait(driver, 10))
    .until(ExpectedConditions.presenceOfElementLocated(By.cssSelector("a.build-it > span")));
String buildStr = myDynamicElement.getText().trim();
return buildStr.equals("Improve");
```

Everything else that looks like a decision is a stub or test-only:

- `OgameConnection.buildMetalMine()` — **empty body**, comment `//TODO: finish the doc and implementation`.
- `OgameConnection.isEmptyConstructionQueue()` — **hardcoded `return true;`**, comment `//TODO: implementation and docs`.
- The only actual cost-comparison logic exists **inside the test**, not in production code (`ConstructionPlanManagerTest.testGetMetalMine`):
  ```java
  boolean canBuildmm = (
      connection.getMetal()>=mm.getMetal_required() &&
      connection.getCrystal()>=mm.getCrystal_required() &&
      connection.getDeuterium()>=mm.getDeuterium_required() &&
      connection.isEmptyConstructionQueue()
  );
  ```
- No fleet, research, defence, espionage, planet selection, building prioritization, or ML. The README's "Advanced Features → Todo" lists SVM / Neural Network / Ensemble techniques as future work — never started.

## Data model & persistence

- `ResourceConstructionPlan` (abstract) holds, per building, all `int` fields:

| Field | Meaning | Parsed from |
|---|---|---|
| `building_name` | display name | `#content > h2:nth-child(1)` |
| `building_level` | current level | `span.level:nth-child(3)` (split on space, take `[1]`) |
| `metal_required` | metal cost | `li.tooltip:nth-child(1) > div:nth-child(2)` |
| `crystal_required` | crystal cost | `li.tooltip:nth-child(2) > div:nth-child(2)` |
| `deuterium_required` | deut cost | `li.tooltip:nth-child(3) > div:nth-child(2)` |
| `production_duration` | build time in **seconds** | `#buildDuration`, strip trailing `"s"` |
| `energy_needed` | energy for production | `.production_info > li:nth-child(2) > span:nth-child(1)` |

- The three subclasses (`MetalMineConstructionPlan`, `CrystalMineConstructionPlan`, `DeuteriumMineConstructionPlan`) are **completely empty** — constructor calls `super()` and nothing else. They add no behavior, only type identity.
- **Persistence: none.** No database, no files, no serialization, no config load/save. Parsed values live only in the JVM heap, cached once per `ConstructionPlanManager` instance (lazy singleton held by `OgameConnection`). Restart = everything lost.
- Resource reads are also DOM-only, transient: `getMetal()` / `getCrystal()` / `getDeuterium()` / `getEnergy()` return `Integer.parseInt(driver.findElement(By.id("resources_metal")).getText().replace(".",""))` (thousand separators stripped; same pattern for the other three ids).

## Config surface

**None.** There is no `.properties`, `.xml`, `.yml`, or env-var reading. The README's own Todo confirms it: *"Login: By using username and password read from a config file."* is listed as **not done**.

Credentials/servers are instead **hardcoded in the test files**:

- `OgameConnectionTest`: `serverUrl="http://en.ogame.gameforge.com/"`, and later `username="cocorito"; password="cocorito"; url="en.ogame.gameforge.com"; universe="Ganimed"`.
- `ConstructionPlanManagerTest.setUp()`: identical `cocorito` / `cocorito` / `en.ogame.gameforge.com` / `Ganimed`.

The only "configuration" is the `OgameConnection` constructor parameters (`serverUrl`, `universe`, `username`, `password`), with a single normalization rule: prepend `http://` when the URL is non-empty and doesn't already start with it.

## Edge cases & failure handling

- **`canBuildMetalMine()` has a known false-positive**, acknowledged in-code: `//TODO: the method return true also if the mine is not buildable`.
- **Duration parsing is fundamentally broken beyond 59 seconds.** `fillConstructionPlan` does `Integer.parseInt(...getText().replace("s",""))` on `#buildDuration`, with a TODO noting it should parse *"weeks,days,hours,minutes,seconds"*. Any duration like `1m 30s` (or `1h 20m`) will throw `NumberFormatException` — uncaught.
- **Cost parsing is defensive only against duplicate matches:** for metal/crystal/deuterium, `0` elements → `0`; `1` element → parse; `>1` elements → `throw new IllegalArgumentException("two or more ... elements selected by css selector")`. This silently treats "not found" and "costs 0" identically.
- **Resource reads are unguarded:** `Integer.parseInt` with no try/catch → `NumberFormatException` on empty/odd DOM text (e.g. before the page loads or during a layout the bot didn't expect). Login success is not awaited, so reads can race page load.
- **Old password-wrong check is commented out** — a deleted `try/catch` that looked for `div.icon` text `"Your username or password is wrong!"`. Login failure is currently not detected at all.
- **Empty `status` enum is commented out** (`DISCONNECTED, CONNECTED`), so connection state is never tracked.
- Tests rely on the live site: `testconstructor_login_correct` asserts `getMetal() > 0`, etc. — no mocks, no fixtures, fully dependent on a real account and OGame's then-current DOM.

## Anti-detection & authenticity

The **only** anti-detection measure, and the repo's single "Technical Detail" claim, is the choice of **Selenium WebDriver (`FirefoxDriver`) instead of raw HTTP**: *"In order to not being tracked Selenium Web Driver is used."*

There is **nothing else**:

- No randomized delays, no think-time modeling, no mouse-movement simulation, no idle/uptime shaping.
- No user-agent, fingerprint, or profile customization on the `FirefoxDriver` (plain `new FirefoxDriver()`).
- Every interaction is instantaneous and deterministic — click → click → parse.
- No session persistence, so no continuity of a believable "person" across sessions.
- Social/fake-message behavior is explicitly listed as Todo ("Fake Messages and Fake Social Behaviours") and was never written.

By the OGameX gate-3 lens ("what a good professional human player does, measurably"), this codebase implements **zero** of that surface: it has no reaction latency, no uptime curve, no action-sequence self-similarity — it cannot even run on its own.

## Discrete mechanisms

- **M01 — Login via Selenium** — open `serverUrl` (prepending `http://` if missing), click `#loginBtn`, fill `#usernameLogin` and `#passwordLogin`, select universe by visible text in `#serverLogin`, submit `#loginSubmit`. (`OgameConnection` constructor)
- **M02 — Read metal** — `Integer.parseInt(getText("resources_metal").replace(".",""))`. Same pattern for crystal (`resources_crystal`), deuterium (`resources_deuterium`), energy (`resources_energy`). (`OgameConnection.getMetal/getCrystal/getDeuterium/getEnergy`)
- **M03 — Server URL normalization** — if non-empty and not starting with `http://`, prepend `http://`. (`OgameConnection` constructor)
- **M04 — `canBuildMetalMine`** — click `Resources` link, click `#details`, wait ≤10s for `a.build-it > span`, return `text.trim().equals("Improve")`. (`OgameConnection.canBuildMetalMine`)
- **M05 — `buildMetalMine`** — empty stub, TODO. (`OgameConnection.buildMetalMine`)
- **M06 — `isEmptyConstructionQueue`** — always `true`, TODO. (`OgameConnection.isEmptyConstructionQueue`)
- **M07 — Lazy singleton `ConstructionPlanManager`** — created on first `getConstructionPlanManager()` call, cached on the connection, keyed to the shared driver. (`OgameConnection.getConstructionPlanManager`)
- **M08 — Load metal mine plan** — nav `//ul[@id='menuTable']/li[2]/a/span` → wait `.selected > span:nth-child(1)` → click `.supply1 > div:nth-child(1) > a:nth-child(2)` → construct + fill `MetalMineConstructionPlan`. (`ConstructionPlanManager.getMetalMine`)
- **M09 — Load crystal mine plan** — same nav, selector `.supply2 > div:nth-child(1) > a:nth-child(2)`. (`ConstructionPlanManager.getCrystalMine`)
- **M10 — Load deuterium mine plan** — same nav, selector `.supply3 > div:nth-child(1) > a:nth-child(2)`. (`ConstructionPlanManager.getDeuteriumMine`)
- **M11 — Parse build duration** — read `#buildDuration`, strip trailing `"s"`, `Integer.parseInt` into `production_duration` (seconds). TODO notes it should parse weeks/days/hours/min/sec; currently breaks on composite durations. (`ConstructionPlanManager.fillConstructionPlan`)
- **M12 — Parse energy** — `Integer.parseInt` of `.production_info > li:nth-child(2) > span:nth-child(1)`. (`fillConstructionPlan`)
- **M13 — Parse metal cost** — `li.tooltip:nth-child(1) > div:nth-child(2)`; 0 elements → `0`, 1 element → parse, else throw `IllegalArgumentException`. (`fillConstructionPlan`)
- **M14 — Parse crystal cost** — same rule on `li.tooltip:nth-child(2) > div:nth-child(2)`. (`fillConstructionPlan`)
- **M15 — Parse deuterium cost** — same rule on `li.tooltip:nth-child(3) > div:nth-child(2)`. (`fillConstructionPlan`)
- **M16 — Parse building level** — `span.level:nth-child(3)`, split on space, `Integer.parseInt` of `[1]`. (`fillConstructionPlan`)
- **M17 — Parse building name** — `#content > h2:nth-child(1)`. (`fillConstructionPlan`)
- **M18 — Test-only buildability predicate** — `metal>=metal_required && crystal>=crystal_required && deuterium>=deuterium_required && isEmptyConstructionQueue()`. Lives only in `ConstructionPlanManagerTest.testGetMetalMine`, not in production code.
- **M19 — Close connection** — `driver.quit()`. (`OgameConnection.close`)
- **M20 — Hardcoded base-cost assertions (tests)** — level-0 Metal Mine `60 metal / 15 crystal / 0 deut / 11 energy / 7s`; level-0 Crystal Mine `48 / 24 / 0 / 11 / 7s`; level-1 Deuterium Synthesizer `337 / 112 / 0 / 27 / 54s`. These are hardcoded game constants in `ConstructionPlanManagerTest.java`.

## Notable concerns

- **Gate 1 (no static, hardcoded AI) — heavily violated.** The tests encode OGame object prices/names as literals (`60/15`, `48/24`, `337/112`, `"Metal Mine"`, `"Crystal Mine"`, `"Deuterium Synthesizer"`). The DOM navigation is hardcoded to OGame's exact CSS: `.supply1/.supply2/.supply3`, `menuTable/li[2]`, `a.build-it > span`, `resources_metal`, etc. Adding an object to the host would require editing these selectors — no generic discovery.
- **Gate 2 (simple, not over-engineered) — mildly violated.** The three empty `*MineConstructionPlan` subclasses are an abstraction with a single trivial implementation each; they add zero behavior over `ResourceConstructionPlan` itself. The commented-out `status` enum and MVC layering are scaffolding without a use.
- **Gate 3 (human-like play) — absent.** No timing model, no randomness, no save/uptime shape, no social behavior. The only nod toward authenticity is "use Selenium instead of HTTP," which is an evasion heuristic, not a human-behaviour model.
- **Naive integer parsing** on resource/DOM text assumes a single locale format; no error recovery, so the bot dies on unexpected page states rather than degrading.
- **Duration parse defect** is a real bug: any build >59s (i.e. nearly all real builds) breaks `fillConstructionPlan`.
- **Credentials committed** (`cocorito/cocorito`, universe `Ganimed`) — a live test account baked into source, against a now-defunct OGame URL (`en.ogame.gameforge.com`).
- **Abandoned and obsolete:** Selenium 2.46.0 (2015-era) and JDK 1.5 language level; OGame's DOM and login flow have changed substantially since.

## Confidence

**High.** The complete file tree was enumerated via the GitHub API (`git/trees/master?recursive=1`, `truncated: false`) — 13 files total: `README.md`, `LICENSE`, `pom.xml`, `ogamebot.iml`, `.gitignore`, 6 main-source Java files, and 2 test Java files. All 6 production files and both test files were read in full from raw content; the only files not read in full are `LICENSE` (GPL-2.0, standard), `.gitignore`, and `ogamebot.iml` (IDE metadata, content confirmed via search). There is no hidden source, no `main()`, and no unexamined branch. The repo is a ~11-year-old abandoned stub: login + resource read + read-only construction-plan parsing, with no loop, no decision engine, no persistence, and no config — nothing the OGameX AI module can reuse beyond confirming what *not* to do.