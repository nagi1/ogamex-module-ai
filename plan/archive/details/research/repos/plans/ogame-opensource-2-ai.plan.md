# Enhancement plan — ogame-opensource-2 (built-in AI)

## Verdict summary (one line)
This repo's "AI" is the floor the brief named — an `eval()`-driven graph interpreter with no shipped strategy, no decision logic in PHP, and a wiki capability list ("evolve to small cargo then hibernate") that exists only as prose; our module already ships a typed, host-read, session-shaped decision stack that far exceeds it, so almost every mechanism is ALREADY-DONE or REFUSE and exactly one operational idea (bot liveness / purge exemption) is worth adopting.

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Bot purge exemption (M29, and the inverse bug of M02/M10) | The repo exempts accounts with active AI queue rows from the 35-day inactivity purge — and reveals the failure mode: a bot whose chain ends loses its derived identity (`IsBot()` checks for AI rows) and loses purge protection too | `app/Domain/Scheduling/SessionDecisionService.php` + `app/Domain/Routine/SessionPlanner.php`; one bounded floor under the next-due time, sourced from `config/population.php` | The only operational lesson worth keeping: an AI account must keep a host-visible liveness signal so that even a long, persona-shaped absence can never trip host inactivity cleanup and silently delete the account. We take the *inverse* of the repo's bug — explicit `AiProfile` identity (never derived), plus a floor under `nextDueAt`, no host-side daemon |

## ENHANCE — table

None. Every mechanism the repo shares with us is strictly worse: raw `eval` for decisions and actions, hardcoded duration constants (`PROD_BUILDING_DURATION_FACTOR=2500`, `PROD_SHIPYARD_DURATION_FACTOR=2500`, `PROD_RESEARCH_DURATION_FACTOR=1000`), a fixed production-percentage surface our host does not even have, and constant virtual presence with zero reaction latency. There is no row where copying a refinement would improve a host-read, human-named implementation.

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| — | — | — | — |

## ALREADY-DONE (confirmed by grep)

- **Typed decision engine + scored candidates replaces the graph interpreter (M08/M09/M11/M12/M13/M15)** — `app/Domain/Decision/DecisionEngine.php`, `CandidateActionFactory.php`, `CandidateGeneration.php`, `UtilityScorer.php`; no JSON graph, no `eval`, no GoJS editor.
- **Host-read build legality + action (M19/M20)** — `app/Actions/QueueAiBuildingAction.php` delegates to `OGame\Services\BuildingQueueService::add()` after `PlayerGameStateService::advance()`; legality is the host's `ObjectService` predicate, never restated.
- **Mine ordering by payback, not a fixed ladder** — `app/Domain/Decision/EconomyUpgrades.php` ranks host-reported producing objects by metal-equivalent payback hours; an added mine is a candidate with no module edit (gate 1).
- **Energy planning (M22 `BotEnergyAbove`)** — `app/Domain/Decision/EnergyCapacity.php` plans energy when the host's production factor stalls.
- **Prerequisite facilities before what they unlock (the "facilities unlock the rest" rule)** — `app/Domain/Decision/FacilityChain.php`, derived from `ObjectService::getRecursiveRequirements()`.
- **Research check + action (M24/M25)** — `app/Domain/Decision/QueueableResearch.php` + `app/Actions/QueueAiResearchAction.php`.
- **Fleet/defense build by best attack-per-cost hull (M23 `BotBuildFleet`)** — `app/Domain/Decision/QueueableUnitPlanner.php` + `app/Actions/QueueAiUnitsAction.php` (host cargo/attack as sortable properties, no named ship).
- **Fleetsave — real save, ranked destinations, deliberate skip** — `app/Domain/Decision/QueueableFleetSavePlanner.php`, `SaveFailurePolicy.php`, `app/Actions/QueueAiFleetSaveAction.php` + `QueueAiRecallAction.php` (the repo's fleetsave is wiki prose only).
- **Probabilistic non-determinism (M14 `N%` jump) — done better** — `SaveFailurePolicy.php` (a save that can fail) + Weibull waits in `app/Domain/Routine/SessionPlanner.php` / `app/Domain/Scheduling/NextDueTimeCalculator.php`.
- **Session/uptime shaping, waits, absences (M16 `BotIdle`, M10 "hibernate")** — `app/Domain/Routine/SessionPlanner.php` models the waking-day window, Weibull session waits and absences; hibernation is an absence, not an empty queue.
- **Account creation through the host's real registration path (M01)** — `app/Actions/SeedAiTestUniverseAction.php` uses `OGame\Actions\Fortify\CreateNewUser`; a seeded account is an ordinary account with planets, tech and welcome message.
- **Actions light activity stars by travelling the engine, never by faking records** — every `QueueAi*Action` calls a host service (`BuildingQueueService`, shipyard, research, mission dispatch), so host-side activity side-effects are the engine's own.
- **Reaction wake on inbound hostile, inside the reaction window** — `app/Domain/Perception/PlayerObservationService.php` + `app/Domain/Scheduling/SessionDecisionService.php` (host inbound fleet, not a page-hit sweep).
- **A real worker instead of "advances only when someone clicks a page" (M06/M07)** — `app/Jobs/ProcessAiWork.php`, `app/Enums/AiQueueName.php`, `app/Providers/HorizonServiceProvider.php`; the repo's CRON-less page-hit queue is a defect, not a design to copy.
- **Gate-1 host-read universe end to end** — `EconomyUpgrades.php`, `QueueableUnitPlanner.php`, `FacilityChain.php` all derive candidates from `OGame\Services\ObjectService`; no object id/machine name is a module source of truth.

## REFUSE — list with one-line reason

- **`eval()` decision and action engine (M13/M15)** — arbitrary PHP per block in global scope is an RCE-equivalent, untestable, unsandboxable; our `DecisionEngine` is typed code.
- **GoJS visual strategy editor + JSON-compile-to-queue (M08/M12/M27, `admin_botedit.php`)** — a graphical programming environment is gate-2 over-engineering for an account whose whole job is a session's sorted candidates.
- **No shipped strategy / empty `backup` seed (M28)** — the repo's own AI does not run out of the box; adopting its mechanism adopts its empty floor.
- **Bot identity = presence of AI queue rows (`IsBot()`, M02/M10)** — derived identity makes a finished bot invisible and loses purge protection; we carry explicit `AiProfile` state.
- **`QUEUE_PRIO_BOT = 1000` preempting every other event (M07)** — bot actions running before all human events is machine-shaped; ours run on a dedicated Horizon lane.
- **Per-bot key/value `botvars` scratchpad (M18)** — superseded by the structured Memory domain (`app/Actions/FindCurrentAiMemoryFactsAction.php`, `RecordAiMemoryFactAction.php`).
- **`BotResourceSettings` writing `planets.prod1..6` (M21)** — our host has no per-resource production-percentage surface (only the read-only energy-driven `PlanetService::getResourceProductionFactor()`); porting needs a host feature we must not add.
- **Parallel strategies via `BotExec` (M17)** — concurrent strategy chains are machine-shaped; our session is a single sequential decision.
- **`BotBuild` returning exact duration and waking that second (M20)** — waking the instant a build completes is a latency tell; our waits are Weibull-distributed.
- **Hardcoded duration constants (`PROD_*_DURATION_FACTOR`)** — hardcoded universe maths; host `TechDuration`-equivalent owns timings.
- **Wiki-only capabilities via strategy eval (fleetsave, IPM, phalanx, rename, vacation, login simulation)** — not shipped code; where we need them we use host actions (fleetsave), and IPM/phalanx/rename are not named ordinary play for this account (gate 3).
- **`Queue_CleanPlayers_End` purge exemption as host logic (M29)** — the mechanism lives in host code we cannot reach from the module; we keep the *idea* as the liveness floor above instead.

## Priority recommendation — single highest-value change

Add a bounded liveness floor to the session scheduler so a long, persona-shaped absence can never let host inactivity cleanup delete an AI account. It is one small change in `SessionDecisionService`/`SessionPlanner`: clamp `nextDueAt` to a configurable maximum idleness from `config/population.php`, keyed off explicit `AiProfile` identity rather than any derived "is active" signal. Nothing else in this repo is worth a line of module code.

## Open questions / risks

- **Host inactivity cleanup**: confirm whether OGameX actually purges idle accounts (and at what threshold) before wiring the liveness floor; if there is no purge, the one ADOPT-IDEA row becomes YAGNI and this plan is zero-adopt.
- **Absence ceiling vs. gate 3**: a hard floor under `nextDueAt` must stay wide enough that an ordinary long absence is untouched — only trip it beyond the host's purge horizon, never inside normal play.
- **Production percentages**: verified the host exposes only the read-only energy-driven factor, not player-set mine ratios; if a future host adds per-resource sliders, `BotResourceSettings` (M21) should be re-evaluated then, not faked now.
- **The repo is 116 commits behind upstream `ogamespec/ogame-opensource`**: upstream parity was not re-verified file-by-file, but the built-in AI surface (`botapi.php` = 14 `Bot*` functions, no `_start`/small-cargo/hibernate code) was read end-to-end; nothing upstream could add would change "eval interpreter with no shipped strategy".
