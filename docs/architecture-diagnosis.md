# Why the OGame AI still plays badly, and how classic games built theirs

Written 3 October 2026 from a read of `nagi1/ogamex-module-ai` (main, 598 commits) and `nagi1/ogamex-next`.
Nothing was run; every claim below cites the code it comes from. Where something is inferred rather than read,
it says so.

---

## 1. The verdict in one paragraph

The AI isn't stupid because it lacks an LLM, a cognition sidecar or another planner. The **spine is wrong**.
Each login picks **one errand** from a list whose scores are mostly **constants per action type**. There is no
strategy that lasts from one login to the next, no doctrine of what to build, no world model, and no multi-step
login. Every visible failure since then (naked colonies, no fights, no war fleet, no transfers) has been
patched with a special case bolted onto the side of that one-errand selector, usually to move a cohort
counter. Classic game AIs (C&C Generals/Zero Hour, Freelancer, Age of Empires II, StarCraft) do the opposite.
They are **knowledge-rich and mechanism-poor**: hand-written doctrine (build orders, team compositions,
personality numbers), a few **managers that each act every tick**, a shared world model, and simple
deterministic arbitration. The project has inverted that: it is mechanism-rich (sidecars, ledgers, harnesses,
proofs, gates) and knowledge-poor. The project's own Gate 1 even forbids writing the knowledge down.

---

## 2. What the code actually does today

```
ProcessAiWork (queue job)
 └─ RunAiSessionAction
     ├─ conversation cycle, alliance life           (social: Fatima / PsychSim / AgentOS here)
     ├─ SessionDecisionService::run
     │    ├─ PlayerPerceptionBuilder  → thin snapshot: per-planet resources + booleans + report ids
     │    ├─ DecisionEngine::decide
     │    │    ├─ CandidateActionFactory → ~15 candidate types, each gated by its own planner
     │    │    ├─ UtilityScorer           → constant feature table × 6 weights + seeded noise
     │    │    ├─ situationalErrand / idleOverride
     │    │    └─ ONE selected candidate
     │    └─ schedule next session
     └─ ScheduleAiIntentAction  → turns the one errand into work items,
                                  PLUS ~5 side channels that run regardless of the choice
 later: ProcessAiWork → ExecuteAiIntentAction → re-plans again → host action
```

### 2.1 One errand per login, scored by constants

- `DecisionEngine.php:46` says it outright: *"A login's one errand."* `UtilityScorer::select` returns a single
  `ScoredCandidate`.
- The features that the "utility" scores are **hard-coded per action type**, not computed from the situation:
  `CandidateActionFactory.php:451-488`. A transfer is always `[0.9, 0.3, 0, 0]`, a recycle always
  `[1.0, 0.3, 0, 0]`, a fleetsave always `[0, 1.0, 0, 0]`. Only the raid gets two real numbers (report confidence
  and travel cost). The loot, the fleet at risk, the time to impact and the payback are all absent.
- So the score is effectively `constant(type) + archetype weight + noise`. That is a **fixed priority list with
  jitter**, presented as utility AI.
- The constants are tuned until a counter moves. `CandidateActionFactory.php:458-459` says *"at 0.4 it never
  outranked an expedition and the cohort never moved a resource"*, so the constant was raised to 0.9. That is
  curve-fitting a metric, not modelling a decision.

A worked example from the code, for the raider archetype (weights in `UtilityScorer.php:16-26` and
`resources/behavior/archetype-preferences.yaml`):

| Candidate | need×30 | safety×50 | archetype×25 | approx. total |
| --- | --- | --- | --- | --- |
| Raid (conf 0.8, travel 0.3) | 30 | 5 | 25 | 60 + 24 − 7.5 ≈ **77** |
| Proactive fleetsave | 0 | 50 | 22.5 | ≈ **72** |
| Build (no scarcity) | 30 | 10 | 11 | ≈ **51** |

Whether this login raids or parks the fleet comes down to the report-confidence number and the noise. A human
does **both** in the same login: send the raids, then save what is left before logging off.

### 2.2 The side channels show the spine has failed

`ScheduleAiIntentAction::handle` (`ScheduleAiIntentAction.php:168-307`) now does all of this **whatever the
engine chose**:

- a wall order for a bare planet (`:205`),
- a wall for every other bare sibling (`:214`),
- every threat response (`:228`),
- refilling every build queue and the lab (`:250`),
- a "capital fleet" order from surplus (`:294`).

Each one carries a comment explaining that the selected errand almost never produced it ("the wall
candidate is one of a dozen and the economy's habit outranks it in most logins", "160 QueueUnits orders in half
an hour and no hull above the median"). That is the code itself saying that one decision per login can't
express how a player behaves. The real decision-making has moved into a 781-line scheduler full of exceptions.

### 2.3 No strategy and no memory of intent

- Nothing persists a goal like "I'm saving for a colony ship", "I'm building 300 light fighters to farm
  system 1:240" or "I'm turtling until plasma 8". Every login re-decides from scratch.
- The only notion of game phase is four lines inside the raid planner (`RaidPlanner.php:548-570`, astrophysics 23
  = late game). The economy, research, fleet and defence planners have no phase.
- `ResearchQueue` choices come from payback and a "facility chain" over the requirement graph. There is no
  research **path** (drives for cargo, espionage level for intel, computer for fleet slots, astrophysics for
  colonies, weapons/shields/armour for the fleet).

### 2.4 Gate 1 forbids the knowledge that classic AIs are made of

`plan/details/specs/cognition-gates.md:8-35` forbids *"a list of the technologies we research"*, *"an enum of the
buildings we may build"*, or any object named as a source of truth. That means:

- no opening build order (every OGame guide has one, and so does every RTS AI ever shipped),
- no fleet doctrine. The combat hull is "the hull with the best attack per metal-equivalent cost"
  (`QueueableUnitPlanner.php:36-40`), which ignores shields, structure, rapid fire, speed, fuel and cargo, so
  the fleet compositions a veteran recognises (LF/HF swarms, cruisers against LF, battlecruisers, bombers
  against defence) can't be expressed,
- no defence doctrine beyond derived ratios.

The motive (mods may add objects) is sound, but the remedy is wrong. Classic games handle mods with **data
files**, not by refusing to name units: a mod ships its own entry in the AI's data. The right rule is "doctrine
may name objects as data, and an unknown object falls back to the generic derived rule". Section 6 covers this.

### 2.5 No world model. Every planner re-reads the host, and some read what a player can't see

- The perception snapshot is thin: per-planet resources, report ids, and booleans such as `fleetsaveEligible`
  and `colonizeEligible` (`PlayerPerceptionBuilder.php:22-41`). The managers' real inputs (buildings, fleets,
  defence, intel) aren't in it.
- Each of the ~16 planners rebuilds `PlayerService` from the database on its own (29 calls to
  `playerServiceFactory->make(` in `app/`). The same planner then runs **three times** per action: once to
  decide whether to offer the candidate (`CandidateActionFactory.php:360`), once to schedule it, and once to
  execute it (`ExecuteAiIntentAction.php:212-585`).
- **The raid planner reads the live target planet, not the espionage report.** `RaidPlanner::target`
  (`:537`) loads the real planet. `launchUnits` (`:253`) and `defencelessTarget` (`:459`) read its live ships
  and defence. `maximumLoot` (`:488`) reads its live resources. `NativeRaidEstimator::screen` builds the
  defender from the live planet with `DefenderFleet::fromPlanet($target)`. The comment in
  `CandidateActionFactory.php:333-334` claims *"the scorer never receives unseen defender information"*, but the
  planner that gates it does. The AI is omniscient about its targets, so it is never surprised and never caught
  by a ninja or a rebuilt defence. Those surprises are where real fights come from.

### 2.6 A login is an instant, not a sequence

A human login takes 5 to 20 minutes: check the events, send probes to 10 or 20 farms, wait 1 to 3 minutes for
the reports, send 5 to 8 raid waves, fill queues, fleetsave, log off. Here a session is one decision at one
instant. The spy planner probes **one** target per errand (`QueueableSpyPlanner::plan` returns one `QueueableSpy`). The raid can only
come at a later login (34 to 56 minutes on, per `RunAiSessionAction.php:59`), and only if it wins that login's
single slot. The raid pipeline is throttled to a trickle by the architecture itself.

### 2.7 Why LIFE_FIGHTS sits at 9% (read from code; the live split is inferred)

1. A raid must win the single errand slot against fleetsave, expedition, transfer, spy and so on (2.1).
2. A raid on a defended target must pass all of these: survival floor (`RaidPlanner.php:184`), a
   **20th-percentile** net profit after losses above zero (`:192`), a loot-to-fuel tier (`:218`), and a launch
   ladder that only flies if a single draw wins outright (`:277`). Mostly only defenceless farms pass, and a
   defenceless battle has no rounds.
3. Early-phase accounts may only hit inactives (`RaidPlanner.php:567`).
4. The targets are mostly other AI accounts, and NAKED_BESIDE_WALLED says many of their planets have no
   defence. Even a "real" attack on them produces no rounds. Part of this invariant measures the universe's
   seeding, not the attacker.
5. `CandidateActionFactory.php:382-395` hides farms whenever a defended target also passes. That's a local
   patch aimed at the counter, and it rarely fires because of point 2.

A veteran crashes a defended inactive because loot plus 30% debris (recycled) beats the losses, and accepts a
bad tail now and then. The current filter is tuned for "never lose", which a real raider doesn't play for.

### 2.8 Where the cognition sidecars actually touch play

You asked for evidence, not a silent redesign, so here it is.

| Sidecar | Where it reaches a gameplay decision | What it contributes |
| --- | --- | --- |
| FAtiMA | `AppraiseObservedBattleReportAction.php:66` → anger/fear → `UtilityScorer` affect term | ±10 points on **two** action types (raid +, fleetsave −), default weight 10 (`AiRuntimeSettings.php:144`), against totals around 50 to 80. The native fallback that does the same job is 44 lines (`NativeAffectEngine.php`). |
| CBRKit | `EconomyUpgrades.php:535-571` | The query's only feature is `object_id`, and only cases with similarity ≥ 1.0 count. That is `AVG(utility) WHERE object_id = ?`, capped at ±20% of payback. |
| AgentOS | `EvaluateAiSocialExchangeAction.php:154` | Social exchanges only. Never touches play. |
| PsychSim | `PsychSimSocialCognition` via `SocialCognition` | Social exchanges only. Never touches play. |

Meanwhile the cost shows up across the latest work: sim speed stuck at ×6 with FAtiMA as the hotspot, cache
determinism A/B runs, per-server locks, and a Rust FFI port of CBRKit's similarity, which led into the segfault
hunt (HANDOFF.md, commits of 3 Oct). On gameplay specifically, the sidecars **add cost and fragility for a
nudge a 40-line function already gives**. They are not why the AI is stupid, but they absorb most of the
engineering time that should go into play. They are worth keeping where they matter, in social, diplomacy,
dialogue and long-memory of other players, as long as the gameplay loop never waits on them (section 7,
step 6). That is your call because of the hard rule. My recommendation is "use them, asynchronously" rather
than "remove them".

### 2.9 The process is optimising itself, not the player

- `app/` holds 39k lines of PHP, with 36k lines of tests, 53k lines of plan markdown and 1,592 research files.
  `tasks.db` has 434 rows. On 3 October alone there were 220 commits, mostly simulator speed, sidecar caches,
  FFI segfaults, ledger hygiene and handoff notes.
- In `app/Domain/Decision`, comments are 2,365 lines against 5,148 lines of code. 65 comments say
  "measured", and many explain why a constant was nudged to satisfy a cohort invariant.
- The acceptance signal is a set of **aggregate invariants** (LIFE_FIGHTS ≥ 20%, AUTH_UPTIME < 18 h, and so on).
  Agents are rewarded for flipping a counter, and the cheapest way to flip one is a local special case. That is
  Goodhart's law, and it is exactly how 2.2 came about.
- The decision-technique study (`plan/details/specs/decision-techniques.md`) explicitly chose "utility
  scoring is the spine" and **rejected** HTN/GOAP as *"shipped game plans are one or two actions deep"*. That's
  true of F.E.A.R.'s **tactical** squad AI. It is not how strategic AIs in RTS or 4X games are built. That one
  misreading set the architecture.

---

## 3. How classic deterministic game AIs were actually built

None of these used a model or ran on more than late-90s or 2000s hardware. They share one shape.

| Game | Strategic brain | Operational layer | Personality / tuning | Adaptation |
| --- | --- | --- | --- | --- |
| **C&C Generals / Zero Hour** | Per-faction, per-general skirmish scripts (condition → action, sequential scripts) and AI data: build lists (base layout plus order), resource-gathering targets, base defence | **Teams**: templates with a unit composition, a production priority, a behaviour (guard, attack, hunt) and triggers (timers, "enemy seen", "base attacked") | Each Zero Hour general (Tank, Air, Superweapon, Stealth…) is a different script and team set over the same engine | Team templates carry *priority increase on success* and *priority decrease on failure*: a counter, not learning |
| **Freelancer** | Faction relationship matrix and encounter tables per zone (which factions spawn, with what jobs and densities) | NPC "jobs" (fighter, trader, police, pirate) driven by a state graph of weighted transitions | **Pilot parameter blocks** in data files: gun accuracy, evade/dodge, buzz-pass, strafe, missile use, formation. A personality is a vector of numbers | Reputation changes faction behaviour toward you |
| **Age of Empires II** | `.per` scripts: an expert system of `defrule` (facts) ⇒ (actions), evaluated every pass, **many rules firing per pass** | Build orders, goals, timers, attack groups | **Strategic numbers** (`sn-*`): percentages for gatherers, attack group sizes, defence | Facts about the enemy (e.g. what they build) switch rule sets |
| **StarCraft (BW)** | `aiscript` per race/difficulty: an opening build, then expansions | Attack waves (`attack_add` / `attack_prepare`), `defensebuild` per threat type | Different scripts per AI "personality" | Mostly none: deterministic and still convincing |
| **Civilization IV/V** | Grand strategy and victory focus per leader | Per-city and per-unit evaluators (utility) | **Leader flavours**: numeric weights (military, expansion, wonder, gold…) | Diplomacy memory ("you attacked my friend") as counters |
| **Supreme Commander (Sorian AI)**, **Total Annihilation** | Build templates and platoon definitions | Platoons with roles; influence maps for threat and expansion | AI variants (rush, turtle, tech) as different template sets | Threat-map updates |

The common pattern:

1. **Doctrine is data, and it names things.** Build orders, unit mixes and research paths are written out by
   people who know the game. The engine executes them and stays generic.
2. **Several managers act every tick.** Economy, production, military, defence and scouting each issue their
   own orders. Arbitration covers only shared resources (money, units, slots), usually with fixed priorities or
   budget percentages. Nobody picks "one thing to do this tick".
3. **Layers with different clocks.** Strategy (rarely: phase, posture, target), operations (each tick: what to
   build, which team to form) and tactics (reactive interrupts: "base attacked" overrides everything).
4. **A shared world model / blackboard.** Known enemy positions, last-seen times, threat and influence maps.
   It holds what the AI has seen, not the ground truth (good AIs only cheat on difficulty settings).
5. **Personality is a parameter vector over the same machinery**, not separate code.
6. **Adaptation is counters and hysteresis**: team priority up on success and down on failure, plus
   commitment to a plan until it clearly fails.
7. **Humanising is a separate layer**: reaction delay, imperfect micro, idle moments. It never changes *what*
   the AI wants, only how fast and how cleanly it does it.

OGame is a slow, economic 4X/RTS hybrid. It maps onto this pattern almost one to one.

---

## 4. The recommended architecture for OGame

```
                ┌───────────────────────────── STRATEGIC (daily / on milestone) ─────────────────────────────┐
                │  Persona = archetype doctrine (YAML) + personality vector (aggression, risk, greed,        │
                │  diligence, sociability, defence ratio, eco/fleet/research split)                         │
                │  Phase machine: Opening → Expansion → Development → Mature (per account, from host state)  │
                │  Active goals (persisted, with hysteresis): e.g. "colonies to 6", "300 LF + 50 SC farm      │
                │  fleet", "plasma 8", "moon on main"                                                        │
                │  Budget envelopes: % of income per manager (AoE strategic numbers / C&C team priorities)   │
                └──────────────────────────────────────────────┬─────────────────────────────────────────────┘
                                                               ▼
   ┌──────────────────────── BLACKBOARD (built once per login, persisted where it must outlive it) ─────────────────────┐
   │ EmpireSnapshot (own planets, queues, fleets, missions, resources, energy)   IntelBook (from reports & galaxy view   │
   │ only: target, last report, est. resources now, defence, activity, outcome history)   GalaxyMap (threat/opportunity │
   │ per system, influence-map style)   Reservations (resources, ships, fleet slots claimed this login)               │
   └──────────────────────────────────────────────┬─────────────────────────────────────────────────────────────────────┘
                                                  ▼
   ┌──────────────────────────── OPERATIONAL MANAGERS (every login, each may emit many orders) ─────────────────────────┐
   │ Safety      Economy     Research    Colony      Intel        Military       Defence      Logistics   Expedition   │
   │ (interrupt) (build      (tech path  (count &    (farm list,  (fleet mix,    (ratio per   (ferry to   (slots,      │
   │             order →     per         placement   probe        raid waves,    planet value main, deut   composition)│
   │             payback)    doctrine)   via map)    batches)     recyclers)     + threat)    for saves)                │
   │                                                       Social (alliances, chat, diplomacy ← FAtiMA/PsychSim/AgentOS)│
   └──────────────────────────────────────────────┬─────────────────────────────────────────────────────────────────────┘
                                                  ▼
   ┌──────────── ARBITER: fixed priority + envelopes (Safety > queue refills > Military/Defence by envelope > rest) ─────┐
                                                  ▼
   ┌──────────── LOGIN SCRIPT (tactical, multi-step with waits) ─────────────────────────────────────────────────────────┐
   │ 1 read events → 2 safety → 3 read new reports & send raid waves → 4 send probe batch → 5 queues → 6 shipyard →    │
   │ 7 transfers/expeditions → (wait 2–4 min for probes) → 8 raid the fresh reports → 9 logout fleetsave/ressave        │
   └──────────────────────────────────────────────┬─────────────────────────────────────────────────────────────────────┘
                                                  ▼
   HUMANISER (keep what exists: SessionPlanner routines, heavy-tailed gaps, reaction latency, idle moments, save failures)
                                                  ▼
   EXECUTORS (keep: ExecuteAiIntentAction → host services, idempotent work items, leases)
```

### 4.1 Strategic layer: doctrine, personality, phase, goals

- **Doctrine files** (`resources/doctrine/<archetype>.yaml`) are written the way a veteran writes a guide.
  They name objects:
  - an **opening build order** (e.g. M1 M2 S1 M3 C1 … robotics 2, shipyard 2, lab 1, combustion drive …),
    run as an ordered list with "skip if done / wait if unaffordable" semantics like an RTS build list. After
    it ends, the payback rule in `EconomyUpgrades` takes over (keep that code).
  - a **research path** per phase (espionage 4 early for raiders, computer for slots, astrophysics for
    colonies, energy → hyperspace for higher drives…),
  - **fleet templates** (C&C "teams"): e.g. `farm_fleet: {light_fighter: 60%, small_cargo: cargo-fit}`,
    `crash_fleet: {cruiser: …, battleship: …}`, `bomber_wing` against heavy defence, each with a counter table
    against defence and fleet mixes,
  - a **defence template** (rocket launchers + light lasers fodder, gauss/plasma later) and a target defence
    value as a fraction of the planet's mine value,
  - **budget envelopes per phase** (e.g. raider Opening: 70% economy, 20% fleet, 10% research).
- **Mod compatibility (the Gate 1 concern)**: every manager keeps the current derived rule as the fallback. An
  object the doctrine doesn't name is still reachable through payback, requirement chains and
  attack/defence-per-cost ranking. A mod can add a doctrine entry, just like modded RTS games ship AI data.
- **Personality vector**: extend `PersonaTaste` / archetype preferences into the numbers that modulate doctrine:
  aggression (raid risk quantile), greed (loot tier), caution (fleetsave thresholds), diligence (sessions,
  queue tidiness), defence ratio, sociability. One machine, many players (Freelancer pilot blocks, Civ flavours).
- **Phase machine**: computed from host state (planets, mine levels, astrophysics, fleet points). It selects the
  doctrine section, the envelopes and the raid target classes.
- **Goals with commitment**: a small `ai_goals` table (`account, goal, target, started_at, progress,
  abandon_after`). A goal holds until it is met or clearly failing (hysteresis). This turns "save up for a colony
  ship" or "build the crash fleet" into something that lasts across logins, which is what makes behaviour look
  intentional.

### 4.2 Blackboard / world model

- **EmpireSnapshot**: built once at login start from the host (`PlayerService` once, passed to every manager).
  This replaces 16 separate rebuilds and the three-times-per-action re-planning.
- **IntelBook** (persisted): one row per known foreign planet, filled **only** from espionage reports, galaxy
  view and battle reports: last-seen resources, defence, fleet, activity marker, estimated resources now (last
  report plus estimated production since), yield history, a C&C-style priority that rises on profitable raids
  and falls on bad ones. This is the human's "farm spreadsheet". The raid planner and estimator simulate
  against the **report's** units and resources, not the live planet. That fixes the omniscience in 2.5.
- **GalaxyMap** (influence map, per account or shared): per solar system, a threat score (active players with
  fleets in range, attacks received, phalanx sightings) and an opportunity score (inactive density, debris,
  free colony slots in the preferred positions). Colony placement, fleetsave destinations, probe batches and
  raid targeting all read it.
- **Reservations**: within a login, a manager claims resources, ships and fleet slots on the blackboard, so the
  ordering hacks ("written before the building steps so the balance is not spent", `ScheduleAiIntentAction.php`
  comments at `:180-205`) become one explicit mechanism.

### 4.3 Operational managers: each one acts every login

| Manager | Owns | Replaces / absorbs |
| --- | --- | --- |
| Safety (interrupt) | Inbound hostile → save fleet and resources, defend, or accept. Logout save before long absence | `QueueableFleetSavePlanner`, `ThreatResponsePlanner`, `SaveFailurePolicy`, recall |
| Economy | Opening build order, then payback; energy interlock; storage; mine % | `QueueableBuildingPlanner`, `EconomyUpgrades` (keep), `FacilityChain` (keep), `QueueableMinePercentPlanner` |
| Research | Doctrine path per phase, payback dump as fallback | the research part of the building planner |
| Colony | Target colony count from astrophysics and doctrine; placement via GalaxyMap | `QueueableColonyPlanner`, relocation |
| Intel | Keeps the IntelBook fresh: probe **batches** (5 to 20 targets) ranked by expected yield, plus galaxy scans | `QueueableSpyPlanner`, phalanx |
| Military | Fleet template to build toward; raid **waves** up to the free slots; recyclers to debris; crash attacks when expected loot + debris − losses > 0 at the personality's risk quantile | `RaidPlanner` (keep the simulator use), `WaveFarmPlanner`, unit planner's capital/escort roles, recycle |
| Defence | Per-planet target defence from planet value × doctrine ratio × GalaxyMap threat, spread across all planets | `DefenseNeedEvaluator`, `DefenseCompositionPlanner`, the wall side channels |
| Logistics | Ferry to main / build planet, deuterium for saves, merchant trades | `QueueableTransferPlanner`, `QueueableTradePlanner`, jump gate |
| Expedition | Keeps N expedition slots busy for discoverer-style personas | `QueueableExpeditionPlanner` |
| Social | Alliances, buddy requests, chat, diplomacy, grudges | Existing social stack. **This is where FAtiMA, PsychSim and AgentOS belong.** |

Arbitration stays deliberately dumb: Safety first, then queue refills (they use separate host queues, so
there's no conflict), then Military/Defence/Colony orders funded from their envelopes, then discretionary
spending of overflow. No utility soup is needed. The utility math lives **inside** a manager, where it compares
like with like (two raids, two mines, two colony slots).

### 4.4 The login script

A login becomes a short script of steps with small human delays, executed as a few work items (the existing
`AiWorkItem` + `due_at` machinery already supports this):

1. Read the event list (inbound, own fleets back).
2. Safety interrupts.
3. Read reports that arrived since the last login → Military sends raid waves (several per login).
4. Intel sends a probe batch.
5. Economy, Research and Defence fill queues; shipyard orders from envelopes.
6. Logistics and Expedition.
7. **Continuation**: if probes are in flight and the session window allows, schedule step 8 in 2 to 4 minutes.
8. Raid the fresh reports.
9. Before logging off: Safety's logout save if the absence is long and the fleet is exposed.

Raiding goes from about one target per hour to what a raider actually does, and "probe → raid" happens inside
one login, the way humans do it.

### 4.5 Adaptation without models

- Per-target priority with success/failure deltas (C&C team priorities) in the IntelBook.
- Per-template outcome counters: e.g. "crash fleet lost money 3 times → shift envelope 10% to economy" (the
  doctrine names the knob, the personality bounds it).
- Grudges and alliances as counters in the social layer (Civ diplomacy memory).
- The existing blacklist (`RaidPlanner::blacklisted`) is already this shape. Generalise it.

### 4.6 Humaniser: keep it, it's the part that works

`SessionPlanner`, `RoutineProfile`, heavy-tailed gaps, the reaction wake, idle overrides and save failures are
sound ideas and match the research on human activity. Keep them as a layer that decides **when** and **how
cleanly**, never **what**.

---

## 5. What not to do

- Don't add GOAP, MCTS, an RL agent or an LLM to the play loop. Not one classic game needed them for this.
- Don't add sidecars, abstraction layers or proof machinery before the managers exist.
- Don't fix an aggregate invariant with a local special case. If a counter is wrong, find the manager that
  should own the behaviour.
- Don't let the AI read hidden host state about other players (live planets, live fleets). It makes the AI
  both cheating and timid.

---

## 6. Rules to change in the project's own contract

1. **Gate 1 → "doctrine may name objects, as data, with a derived fallback."** Doctrine YAML can name buildings,
   ships, defences and research. Each manager keeps a generic rule for objects the doctrine doesn't name, so
   mods still work.
2. **Gate 2 → judge simplicity on the whole system, not per slice.** One-class-per-slice minimalism has
   produced a 781-line scheduler of exceptions. Six managers with clear ownership are simpler than 16 planners
   coordinated through comments.
3. **Gate 3 stays**, but becomes the acceptance test: **a veteran (you) reads a day of one account's log in
   human words** ("07:12 logged in, 2 inbound probes, sent 4 raids on 1:240:8, 1:241:4…, queued metal 14,
   saved fleet to moon") and says whether it reads like a player. Aggregate invariants become dashboards, not
   targets.
4. **Freeze the meta-work.** No new ledger rows, harness features or sidecar tuning until the managers land.
   One owner (one agent at a time) does the restructure end to end. Parallel task-ledger agents can't do this
   kind of change.

---

## 7. Migration path from the current code

Each step leaves the game playable and can go to main on its own. The order matters more than the size.

**Step 1: one login, one snapshot.**
Build `EmpireSnapshot` once in `RunAiSessionAction` and pass it to every planner (signature change only).
Delete the re-plan in `CandidateActionFactory` → `ScheduleAiIntentAction` → `ExecuteAiIntentAction`: plan once,
carry the plan in the payload, and let the host gate refuse if it's stale (the executor already does that for
most kinds). This makes the code faster and simpler, with no behaviour change.

**Step 2: IntelBook, and no live reads of foreign planets.**
New `ai_intel` table filled from `EspionageReport` / galaxy / battle reports. `RaidPlanner` and
`NativeRaidEstimator` build the defender from the report's units and the loot from the report's resources plus
estimated production. Remove `RaidPlanner::target()`'s live read. This brings back real surprises, ninja losses
and fights.

**Step 3: managers replace the single errand.**
Turn `DecisionEngine` + `ScheduleAiIntentAction` into a `LoginOrchestrator` that calls the managers in the
priority order in 4.3, each returning a list of orders, with reservations on the blackboard. Most planner code
moves without rewriting: each `Queueable*Planner` becomes a manager's inner function. The five side channels in
`ScheduleAiIntentAction` disappear because they're now ordinary manager output. `UtilityScorer`'s cross-type
constants are deleted. `situationalErrand` and the archetype `allows`/`preference` become envelope percentages.

**Step 4: doctrine files and goals.**
Add `resources/doctrine/{miner,raider,turtle,fleeter,hybrid}.yaml` with opening order, research path, fleet
and defence templates and envelopes per phase. Add the phase machine and the `ai_goals` table. Amend Gate 1 as
in section 6. Economy runs the opening list, then falls through to `EconomyUpgrades` payback. Military builds
toward its template instead of "best attack per cost".

**Step 5: multi-step login and raid waves.**
Add the continuation work item (probe batch → wait → raid wave). Intel probes batches from the IntelBook.
Military sends up to the free slots each login. Relax the raid gate to the personality's risk quantile
(e.g. p20 for cautious, p50 for aggressive) and count recyclable debris for any account that owns or can build
recyclers. Add the crash-attack template for defended inactives. LIFE_FIGHTS should move here on its own.

**Step 6: sidecars off the critical path (your decision).**
Battle appraisals go to FAtiMA from a background job, and the resulting mood is stored on the profile. The
login reads the stored value and never waits on HTTP. Use CBRKit for real similarity over multi-feature cases
(e.g. raid outcomes by target class, fleet ratio and defence mix), or drop it from the economy path where it's
an exact-match average. Keep AgentOS and PsychSim in the social lane, and make the social lane a real manager.
The AI still uses all four. The sim's speed then no longer depends on them.

**Step 7: GalaxyMap and adaptation.**
Add the threat/opportunity map, colony placement and save destinations from it, and per-target and
per-template success counters.

**Kept as is**: `ProcessAiWork` leasing and idempotency, `SessionPlanner`/routines, `EconomyUpgrades` payback,
`FacilityChain`, the battle-sim estimator (fed from reports), executors and host gates, the social stack.

**Deleted along the way**: `UtilityScorer`'s cross-type weights, `CandidateActionFactory`'s constant feature
table, `situationalErrand`/`idleOverride` (idle moments move to the humaniser), the side channels in
`ScheduleAiIntentAction`, and the per-invariant special cases they carried.

---

## 8. What you'd see change, in order

1. After step 3: every login does several things (queues, shipyard, raids, save), and planets stop sitting
   naked, because Defence owns all planets every login instead of competing for a slot.
2. After step 4: openings look like a guide, and each archetype's fleet looks like that archetype's fleet.
3. After step 5: raiders probe 10 to 20 targets and send several waves per login, crash defended inactives,
   and recycle the debris. Fights and moons appear.
4. After steps 2 and 7: the AI is sometimes surprised and loses ships, holds grudges, and colonises sensibly
   instead of greedily.
