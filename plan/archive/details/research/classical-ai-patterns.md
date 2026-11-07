# Classical game AI patterns — reverse-engineering catalog

Created 15 September 2026 by [strategy mining](../specs/strategy-mining.md). This is the **third
workstream** the brief names: where [`strategy-principles.md`](strategy-principles.md) catalogues
*what* an experienced OGame player knows and
[`gameplay-algorithms.md`](../specs/gameplay-algorithms.md) writes the *how*, this file extracts the
**reusable design patterns** from deterministic game AI that became good *without* ML, LLMs or
per-personality engines. It is design learning only: no external implementation is copied, and the
GPL/closed projects below are used for architectural observation, never as a source for module code.

The purpose is the one the brief states: a **small set of powerful, composable, well-supported
patterns** that make the existing deterministic OGameX AI behave more like an experienced human —
not 1,000 rules, not a second engine, not a learning runtime.

Provenance markers: **handoff** = asserted by the 15 September 2026 research brief with a named
source file/repo (not re-read here, on the brief's own "do not rediscover" instruction) ·
**host** = verified in OGameX source · **mapped** = our own synthesis of the two. Confidence:
**A** = multi-source or mechanically derivable · **B** = one named source · **C** = synthesis/hypothesis.

## Pattern index

| id | game | pattern | generalizable? | OGameX mapping status |
| --- | --- | --- | --- | --- |
| ZH-1 | Zero Hour | Economic state modifies action cadence | yes | candidate cadence; **open** |
| ZH-2 | Zero Hour | Derived world-state signals from raw sensors | yes | `PerceptionSnapshot` derived features; **partial** |
| ZH-3 | Zero Hour | Master counter / strategic progression | maybe | `StrategicPosture` open question; **hypothesis** |
| ZH-4 | Zero Hour | Difficulty-specific cadence | yes | `AiSkillBand`; **shipped** (quality, not cheats) |
| ZH-5 | Zero Hour | Context-gated candidate generation | yes | candidate breadth; **open** |
| ZH-6 | Zero Hour | Attack-priority sets (per-mission attractiveness) | yes | target score per mission; **planned (T6)** |
| ZH-7 | Zero Hour | Sequential multi-step plans | yes | probe→evaluate→attack→recycle; **open** |
| ZH-8 | Zero Hour | Success/failure priority feedback | yes | bounded recent-outcome modifiers; **hypothesis** |
| ZH-9 | Zero Hour | Randomized SkillSets (bounded variety) | yes | `AiSkillBand` + seeded variation; **shipped** |
| ZH-10 | Zero Hour | Engine primitives + data decides behaviour | yes | gate 1 (host data + module policy); **shipped** |
| FL-1 | Freelancer | Composable behaviour blocks in a profile | yes | one engine + profile modifiers; **partial (H3)** |
| FL-2 | Freelancer | Inheritance overriding selected dimensions | yes | base + archetype + skill + temporary state; **partial** |
| FL-3 | Freelancer | Few strategic parameters, not hundreds | yes | ~10 strong profile parameters; **hypothesis (H3)** |
| FL-4 | Freelancer | Difficulty changes execution *quality* | yes | decision quality over cheats; **shipped (H9)** |
| FL-5 | Freelancer | Cheat difficulty is frustrating (negative) | yes | explicit non-goal; **recorded** |
| FL-6 | Freelancer | Fewer complete profiles improve runtime | yes | shared planners, no per-archetype matrices; **gate 2** |
| OA-1 | OpenRA | Capability modules + data-driven bot profiles | yes | shared planners consume profile data; **partial (H3)** |
| OA-2 | OpenRA | A small profile surface covers every archetype | yes | `Miner/Fleeter/Turtle/Trader/Casual` on one surface; **hypothesis** |
| CB-1 | Cobra | Per-opponent hostility modifies targeting | yes | relationship term in target score; **planned (T6)** |
| WE-1 | Wesnoth | Cache expensive analysis with tolerance | yes | estimator input-hash cache; **planned (H10)** |
| WE-2 | Wesnoth | Score composition, not individual units | yes | fleet composition / launch subset; **planned (U/F series)** |
| WE-3 | Wesnoth | Controlled randomness after rational scoring | yes | seeded variation over good candidates; **shipped** |
| WE-4 | Wesnoth | Save / normal / spend resource states | maybe | resource-state posture; **hypothesis** |

---

## Zero Hour (EA Generals / C&C Generals)

Primary engine reference: the EA-released Generals/Zero Hour source (`AI.h`, `AIPlayer.h`,
`Scripts.h`, `ScriptActions.h`, `Player.h`). Data/script artefacts: `AIData.ini`,
`SkirmishScripts.scb`, `SkirmishBuildList`, team definitions, `AttackPrioritySets`, `SkillSets`
(seed: `FreemanZY/Command_And_Conquer_INI`).

### ZH-1 — Economic state modifies action cadence
- **game:** Zero Hour · **confidence:** A (handoff, named file)
- **problem:** a fixed production cadence ignores whether the account is rich or broke.
- **vanilla:** `AIData.ini` polls structure/team production at `StructureSeconds`/`TeamSeconds`;
  `Wealthy`/`Poor` resource thresholds flip `StructuresWealthyRate`/`StructuresPoorRate` /
  `TeamsWealthyRate`/`TeamsPoorRate`.
- **mechanism:** STATE → parameter modifier → changed behaviour (wealthy → build/spend faster;
  poor → slow production and preserve resources).
- **why:** cadence tracks economic condition instead of a constant; two thresholds and four rates.
- **cost:** zero per-tick — the modifier is read, not computed.
- **failure modes:** a threshold cliff at the boundary; a new parameter per state instead of a
  shared modifier.
- **generalizable:** yes — the lesson is "a few named states modify a small parameter surface",
  never a giant strategy tree.
- **ogamex mapping:** candidate breadth and re-evaluation cadence modulated by account stage and
  resource pressure. Today `ArchetypePolicy` moves the *action type*, not the cadence — the exact
  distinction the brief calls out. Open; would need evidence before a `spend/save/normal` surface.
- **sources:** `FreemanZY/Command_And_Conquer_INI` → `AIData.ini`; EA source `AI.h`/`AIPlayer.h`.

### ZH-2 — Derived world-state signals from raw sensors
- **game:** Zero Hour · **confidence:** A (handoff)
- **problem:** reacting to a single weak enemy unit makes the AI twitchy and predictable.
- **vanilla/improved:** a script threshold sets `EnemyInBase = true` only when enough enemy force
  has entered, rather than on first contact.
- **mechanism:** aggregate raw events over a threshold into a **named boolean derived state**.
- **why:** one reaction per state transition, not per event; the state is inspectable and tunable.
- **cost:** a counter and a comparison per event; negligible.
- **failure modes:** a threshold that never fires (the AI stops reacting); a state that is set and
  never cleared.
- **generalizable:** yes, directly.
- **ogamex mapping:** `PerceptionSnapshot` should expose derived features —
  `under_sustained_threat`, `high_fleet_exposure`, `resource_overflow_pressure`,
  `likely_target_online`, `recent_hostility` — rather than forcing every planner to re-derive them
  from raw events. Partially present (`recovery_factor`); the rest are unwired.
- **sources:** WorldBuilder script guide (handoff-named).

### ZH-3 — Master counter / strategic progression
- **game:** Zero Hour · **confidence:** B (handoff)
- **problem:** behaviour needs to shift when the game stage shifts (losses, tech, time, failed
  assaults).
- **vanilla:** a counter represents the current strategic stage and changes after those events.
- **mechanism:** one bounded ordinal that gates which scripts are active.
- **why:** a single named stage is cheaper to reason about than many ad-hoc thresholds.
- **failure modes:** stage proliferation; the counter becomes a hidden state machine nobody can
  trace.
- **generalizable:** maybe — OGameX already has `AiSkillBand` and `ArchetypePolicy`; a
  `StrategicPosture` would be a *third* axis and must not duplicate them.
- **ogamex mapping:** recorded as an **open question** (brief disagreement 5): revisit only if the
  raid/fleetsave corpus repeatedly needs a temporary operating state that current intent scoring
  cannot express. Not approved.
- **sources:** WorldBuilder script guide (handoff-named).

### ZH-4 — Difficulty-specific cadence
- **game:** Zero Hour · **confidence:** A (handoff)
- **problem:** Easy/Normal/Hard need different pressure without different rules.
- **vanilla:** difficulty changes the rate teams are produced (`ResourceGatherersEasy/Normal/Hard`,
  SkillSets, SkirmishBuildLists).
- **mechanism:** difficulty is a *parameter modifier*, not new mechanics.
- **ogamex mapping:** difficulty should change **decision quality** — candidate breadth, intel
  freshness required, decision noise, reaction delay — never free resources. `AiSkillBand`
  already does this shape (variation weight, selection margin). This is hypothesis **H9**.
- **sources:** `AIData.ini` (handoff-named).

### ZH-5 — Context-gated candidate generation
- **game:** Zero Hour · **confidence:** B (handoff)
- **problem:** scoring obviously-irrelevant strategies wastes the budget and makes bad choices
  reachable.
- **vanilla:** teams are only produced when their condition is true (enemy has tanks → build the
  anti-tank response).
- **mechanism:** the candidate never exists unless its precondition holds — generation is
  context-sensitive, scoring is only comparative among the live set.
- **generalizable:** yes — but OGameX's broad-then-score design is deliberate (gate 2; the
  techniques note measured search as worse). The import is *narrower*: hard preconditions for
  legal invalidity (a moon-scanner without a moon), not a taste filter.
- **ogamex mapping:** `CandidateActionFactory` may gain legal preconditions; taste stays in the
  scorer. Open; feeds the F-series (a phalanx/recall/moon candidate cannot exist where the host
  surface does not).
- **sources:** `SkirmishScripts` (handoff-named).

### ZH-6 — Attack-priority sets (per-mission attractiveness)
- **game:** Zero Hour · **confidence:** A (handoff)
- **problem:** a target has no single universal score; its attractiveness depends on who is
  attacking it and why.
- **vanilla:** different unit/team types carry different `AttackPrioritySets`.
- **mechanism:** attractiveness is a **function of (target, fleet composition, mission,
  personality, posture, need)**, not a target constant.
- **ogamex mapping:** this is the direct ancestor of the raid target score — the **T6** block.
  `RaidPlanner` today scores profit only, so the same target is equally attractive to every
  archetype; the brief's H5 is the target feature list.
- **sources:** `AttackPrioritySets`, `AIData.ini` (handoff-named).

### ZH-7 — Sequential multi-step plans
- **game:** Zero Hour · **confidence:** A (handoff)
- **problem:** some behaviours are sequences, not isolated actions.
- **vanilla:** the script system runs sequential scripts with subroutines, counters, flags,
  timers, one-shot and repeated behaviour.
- **mechanism:** a deterministic chain — probe → wait for report → evaluate → attack → schedule
  recycler → observe outcome — expressed as a plan, not an LLM-coordinated loop.
- **ogamex mapping:** OGameX actions are currently individual intents. The recycle trip
  (attack → debris → recycle) and the crash sequence are the natural first chains. **F4** writes
  the recycle trip as such a chain. Open for the rest.
- **sources:** `SkirmishScripts.scb`, WorldBuilder script guide (handoff-named).

### ZH-8 — Success/failure priority feedback
- **game:** Zero Hour · **confidence:** B (handoff; the brief flags it as "bounded stateful
  adaptation, NOT ML")
- **problem:** the AI repeats a pattern that has just failed, and never doubles down on one that
  worked.
- **vanilla/mod:** team success/failure alters that team's priority — a successful attack pattern
  becomes more likely, a failed one loses priority.
- **mechanism:** a bounded per-pattern counter adjusted by outcomes, read back into the score.
- **why:** adapts to the *current* universe without any learning runtime; fully deterministic and
  replayable.
- **failure modes:** momentum lock-in (a temporarily-hot pattern crowds out alternatives); a
  counter that never decays.
- **generalizable:** yes, as **bounded recent-outcome modifiers** over existing memory/state.
- **ogamex mapping:** hypothesis **H8** — a recent low-risk raid success temporarily boosts the
  raiding posture; repeated losses push toward recovery. The existing `recovery_factor` and the
  decision-trace outcome records are the raw material; no new persistence layer is implied.
- **sources:** SkirmishScripts modding practice (handoff-named).

### ZH-9 — Randomized SkillSets (bounded variety)
- **game:** Zero Hour · **confidence:** A (handoff)
- **problem:** identical bots are a cohort giveaway and a boring universe.
- **vanilla:** the AI chooses among multiple `SkillSets`; each is a complete, valid behaviour
  variant.
- **mechanism:** deterministic seeded selection among **good** variants — variety over valid
  choices, never arbitrary action.
- **ogamex mapping:** already the design — `AiSkillBand` (variation weight, selection margin) plus
  seeded variation over scored candidates. The lesson is the direction of the randomness
  (over the top candidate set, after rational filtering), which is hypothesis **H-candidate-set**
  in the plan.
- **sources:** `SkillSets` (handoff-named).

### ZH-10 — Engine primitives + data decides behaviour
- **game:** Zero Hour · **confidence:** A (handoff; the brief's own headline)
- **problem:** where is the line between engine and behaviour?
- **vanilla:** the C++ engine implements generic capabilities; `AIData.ini` + scripts + teams +
  build lists + attack priorities decide *actual faction behaviour*. `AIData.ini` does not
  contain the entire AI.
- **mechanism:** primitives in code, taste in data.
- **ogamex mapping:** this is cognition **gate 1** restated: the object universe, prices and
  requirements are host data; module policy may express *taste* but never reachability. Already
  the governing rule, not new.
- **sources:** EA source + `FreemanZY` data (handoff-named).

---

## Freelancer

Reference files: `pilots_population.ini`, `npcships.ini`, pilot profiles, behaviour blocks,
inheritance, difficulty profiles.

### FL-1 — Composable behaviour blocks in a profile
- **game:** Freelancer · **confidence:** A (handoff)
- **problem:** one AI implementation per personality is unbounded work and drift.
- **vanilla:** a pilot composes independent behaviour blocks — gun, missile, evade-dodge,
  evade-break, buzz, pass-by, trail, strafe, engine kill, mine, countermeasure, damage/missile
  reaction, formation, repair, job.
- **mechanism:** ONE ENGINE + COMPOSABLE BEHAVIOUR COMPONENTS + PROFILE PARAMETERS.
- **ogamex mapping:** the brief's H3 — a small `BehaviorProfile` surface (economy / military /
  raiding / survival / research dimensions) consumed by the **shared** planners, so
  `Miner/Fleeter/Turtle` reuse one implementation. `ArchetypePolicy` is the existing seed; the
  gap is that it does not reach the tactical planners.
- **sources:** `pilots_population.ini` (handoff-named).

### FL-2 — Inheritance overriding selected dimensions
- **game:** Freelancer · **confidence:** B (handoff)
- **problem:** profiles share 90% of behaviour and differ in a few knobs.
- **vanilla:** a profile inherits another and overrides only selected behaviours (a medium pirate
  inherits the easy pirate, overriding gun accuracy and evade).
- **mechanism:** base + deltas, not N full profiles.
- **ogamex mapping:** base behaviour + archetype modifiers + skill modifiers + temporary
  situation. Do **not** build inheritance objects (the brief says so); `ArchetypePolicy` +
  `AiSkillBand` already layer this way — the work is widening what they can modify, not adding a
  profile class hierarchy.
- **sources:** `npcships.ini` / pilot profiles (handoff-named).

### FL-3 — Few strategic parameters, not hundreds
- **game:** Freelancer · **confidence:** B (handoff)
- **problem:** hundreds of magic constants are untunable and unverifiable.
- **vanilla:** JobBlock-style values control target preference, flee thresholds, formation, loot
  preference, combat drift — a small number of meaningful knobs.
- **ogamex mapping:** the brief's "10 strong parameters over 100 speculative ones"; each
  parameter needs evidence + a current decision it changes + no duplication. Candidate surface is
  exactly the handoff's profile-dimensions list. Hypothesis H3.
- **sources:** JobBlock, modding tutorials (handoff-named).

### FL-4 — Difficulty changes execution *quality*
- **game:** Freelancer · **confidence:** A (handoff)
- **problem:** making AI harder should not change the game it plays.
- **vanilla/mods:** repair behaviour — whether repair is used, which type, how quickly — is the
  lever; skill changes *how well* the same action is executed.
- **ogamex mapping:** Easy tolerates stale intel and noisier decisions; Hard requires fresher
  intel, considers more candidates, times better. Hypothesis H9; `AiSkillBand` is the home.
- **sources:** repair-behaviour mods (handoff-named).

### FL-5 — Cheat difficulty is frustrating (negative lesson)
- **game:** Freelancer · **confidence:** A (handoff; community feedback)
- **problem:** mods that made NPCs effectively repair forever were read as *unfair*, not *smart*.
- **mechanism:** strong but frustrating AI.
- **ogamex mapping:** hard AI = better decisions, not resource advantages. Recorded as an
  explicit non-goal unless a cheating difficulty is deliberately designed and named.
- **sources:** Freelancer Rebalance / community feedback (handoff-named).

### FL-6 — Fewer complete profiles improve runtime
- **game:** Freelancer · **confidence:** B (handoff)
- **problem:** many redundant AI entries → unclear control and multiplayer lag.
- **vanilla/mods:** an experienced modder replaced redundant entries with a small number of
  complete profiles: clearer control, simpler tuning, better performance.
- **ogamex mapping:** gate 2, stated as a prohibition — never `MinerEasyRaidPlanner` ×
  `FleeterHardRaidPlanner` …; shared planner + composable modifiers only. Already the design rule;
  this is the external evidence for it.
- **sources:** Freelancer modding (handoff-named).

---

## OpenRA

Reference: `mods/ra/rules/ai.yaml` and the modular AI (resource management, harvesters, base
building, production, squads, repair, expansion, support powers). Design ideas only; no code
copy (licensing).

### OA-1 — Capability modules + data-driven bot profiles
- **game:** OpenRA · **confidence:** A (handoff)
- **problem:** every personality needs the same capabilities; only the emphasis differs.
- **vanilla:** separate **modules** (economy, production, squads, expansion, …) consume a
  **YAML profile** (`rush`/`normal`/`turtle`/`naval`) with building limits/fractions/delays,
  production thresholds, unit weights, squad size, scan radii, rush intervals, power thresholds,
  expansion tolerance.
- **mechanism:** shared modules + profile data — no unique implementation per personality.
- **ogamex mapping:** H3 again — the shared tactical planners are the modules; the profile surface
  is the data. The question the brief poses (can `Miner/Fleeter/Turtle/Trader/Casual` reuse the
  same capability implementation?) is answerable *yes* given the current planner set.
- **sources:** `OpenRA/OpenRA` `mods/ra/rules/ai.yaml` (handoff-named).

### OA-2 — A small profile surface covers every archetype
- **game:** OpenRA · **confidence:** B (handoff)
- **problem:** how few knobs does variety actually need?
- **vanilla:** a handful of YAML sections produce four playable profiles.
- **ogamex mapping:** the brief's profile-dimensions list (economy: `roi_sensitivity`,
  `economic_patience`, `expansion_bias`, `liquidity_preference`; military: `aggression`,
  `risk_tolerance`; …) is the candidate surface — hypothesis, each parameter to be justified
  before it exists.
- **sources:** `ai.yaml` (handoff-named).

---

## Cobra (Warzone 2100 bot)

Reference: `KJeff01/Cobra` — dynamic build/research order, multiple personalities, difficulty,
opponent-specific hostility, state-aware resource behaviour.

### CB-1 — Per-opponent hostility modifies targeting
- **game:** Cobra · **confidence:** A (handoff; the "grudge counter" is a confirmed feature)
- **problem:** which enemy to hit when several are legal?
- **vanilla:** aggressive acts increment a per-opponent counter; Cobra preferentially attacks the
  opponent with the highest accumulated hostility/threat.
- **mechanism:** a per-opponent scalar, decayed by time, read into target preference.
- **ogamex mapping:** do **not** call it "grudge" and do **not** duplicate FAtiMA/CiF relationship
  state — existing social/memory state (hostility, attacks against us, destroyed fleet value,
  probes, betrayal) feeds a **relationship term** in the T6 target score. This is H5's
  `relationship/hostility` signal.
- **sources:** `KJeff01/Cobra` (handoff-named).

---

## Wesnoth

Reference: `src/ai/default/recruitment.cpp` — candidate-action architecture, explicit scoring,
combat simulation, cached combat analysis, composition awareness, similarity penalty, bounded
randomness, save-gold/spend-all/normal states.

### WE-1 — Cache expensive analysis with tolerance
- **game:** Wesnoth · **confidence:** A (handoff)
- **problem:** re-running identical combat/fleet simulations when nothing material changed.
- **vanilla:** cached combat analysis with a tolerance — reuse until the relevant state shifts.
- **mechanism:** cache key = (our fleet, enemy fleet, defences, combat techs, modifiers); hit
  until key changes.
- **ogamex mapping:** hypothesis H10 — `NativeRaidEstimator` / the T2 estimator caches by input
  hash and re-samples only on a key change. This is the one place the brief's cache lesson lands
  cleanly, because T2's seeded sampling is already deterministic.
- **sources:** `recruitment.cpp` (handoff-named).

### WE-2 — Score composition, not individual units
- **game:** Wesnoth · **confidence:** A (handoff)
- **problem:** "is Cruiser good?" is the wrong question; "what does the existing fleet need?" is
  the right one.
- **vanilla:** scoring considers the **existing army composition** and applies a similarity
  penalty, so the AI diversifies rather than spamming the best single unit.
- **ogamex mapping:** the split the brief insists on — **fleet production** (what to own) vs
  **fleet launch** (what to send at *this* target) — both composition-aware. This is the
  FLE/U-series composition work (deferred to the next increment) plus the T6 launch subset.
- **sources:** `recruitment.cpp` (handoff-named).

### WE-3 — Controlled randomness after rational scoring
- **game:** Wesnoth · **confidence:** A (handoff)
- **problem:** every AI picking the identical top candidate is a giveaway.
- **vanilla:** random variation applied **over good candidates**, after scoring — never among bad
  actions.
- **ogamex mapping:** already shipped — `UtilityScorer` + `AiSkillBand` seeded variation over the
  scored set. The pattern is confirmed, not new.
- **sources:** `recruitment.cpp` (handoff-named).

### WE-4 — Save / normal / spend resource states
- **game:** Wesnoth · **confidence:** B (handoff)
- **problem:** whether to hoard or spend is contextual, not constant.
- **vanilla:** save-gold / spend-all / normal states gate recruitment spending.
- **ogamex mapping:** hypothesis only — do not implement until OGame strategy justifies a
  `save/normal/spend` posture (ZH-1 covers the adjacent idea). Open question.
- **sources:** `recruitment.cpp` (handoff-named).

---

## Research questions answered

### Zero Hour (§5.7)

1. **Hardcoded in C++?** generic capabilities only — pathing, combat, the script *interpreter*,
   the polling loops that read `AIData.ini`.
2. **Data-driven?** cadence, thresholds, build lists, attack-priority sets, SkillSets
   (`AIData.ini` + `SkirmishBuildList` + team definitions).
3. **Script-driven?** conditions → actions, one-shot/repeated/delayed/sequential behaviour,
   counters, flags, timers, team- and difficulty-specific scripts (`SkirmishScripts.scb`).
4. **Changes by difficulty?** production cadence and which SkillSet/build list is selected.
5. **Changes by faction?** the build lists, team definitions and attack priorities the data layer
   selects per faction.
6. **Changes by state?** cadence via Wealthy/Poor; activation via script conditions and the
   master counter.
7. **Attack priorities?** per-team `AttackPrioritySets` + a distance modifier
   (`AttackPriorityDistanceModifier`).
8. **Economic states?** named thresholds (Wealthy/Poor) that switch a rate set.
9. **Temporary emergency states?** script-set booleans (e.g. `EnemyInBase`) with thresholds.
10. **Success/failure?** team-priority adjustment (ZH-8), bounded and deterministic.
11. **Bounded randomness?** SkillSet choice and guard chase durations.
12. **Script evaluation frequency?** the polling cadence in `AIData.ini` — fixed intervals, not
    per-tick continuous evaluation.
13. **What the mod changes that vanilla gets wrong?** predictable tactics, mediocre bases, idle
    cash, weak tactical awareness, slow expansion, weak economy, contextual composition, target
    priority, specialist/ability use (Advanced AI Mod changelog, handoff-summarised).
14. **Genuine intelligence vs cheats?** better build orders, composition, target priority and
    ability use are intelligence; the rest are tuning. The brief classifies the diff categories
    (priority/threshold/timer/state-detection/resource-management/counter-strategy/build-order/
    unit-composition/target-selection/ability-usage/retreat/support/aggression/expansion/
    difficulty/randomness/reaction-delay/success-failure-feedback).
15. **Performance problems?** excessive AI unit production causes lag (user report) — "harder"
    must mean better decisions, not more entities. OGameX prefers better over more decisions,
    consistent with event-driven `next_action_at`.

### Freelancer (§6.7)

1. **Independent behaviour blocks?** gun/missile/evade/approach/formation/repair/job — each a
   composable component.
2. **Inheritance?** profiles inherit and override selected behaviours.
3. **What difficulty changes?** which repair type and how quickly it is used — execution quality.
4. **Which parameters most alter perceived intelligence?** repair/target/flee behaviour.
5. **Mods that changed AI significantly?** the repair-enabling rebalances (FL-4/FL-5).
6. **Cheats vs behaviour?** unlimited repair = frustrating cheat, not intelligence (FL-5).
7. **Profile simplification?** fewer, complete profiles improved clarity and reduced lag (FL-6).
8. **Archetype correspondence?** the JobBlock-style strategic profile (FL-3).
9. **Skill correspondence?** the inherited/overridden knobs (gun accuracy, evade).
10. **Temporary state correspondence?** scene toughness / damage-reaction thresholds.
11. **Which map to OGameX?** FL-1/FL-3 → H3 profile surface; FL-4 → H9; FL-5 → non-goal; FL-6 →
    gate 2.

---

## What must NOT be imported

- **RTS spatial/micro concerns** (positioning, pathing, micro-reaction) — no OGame analogue and no
  lesson beyond "cheap reactions are still reactions".
- **Cheat difficulty** (FL-5) — hidden resource/recovery advantages unless deliberately named.
- **Per-archetype planner matrices** (FL-6) — shared planners + profile modifiers only.
- **A rule/script interpreter** — Zero Hour's script VM is the *machinery*; the lesson is the
  state→parameter→behaviour shape, not a scripting runtime (gate 2, and
  [`decision-techniques.md`](decision-techniques.md) already rejected rule engines).
- **ML/bandits anywhere** — ZH-8's feedback is bounded counters, not a learner.

## Three takeaways that survive the mapping

1. **State → parameter modifier → behaviour** is the single reusable shape (ZH-1/4, OA-1/2,
   FL-3): a few named states or profile dimensions modify a small parameter surface that the
   *shared* planners consume. It does not require a new class hierarchy (FL-2's delta is already
   `ArchetypePolicy` + `AiSkillBand` layered over one engine).
2. **Difficulty = decision quality** (ZH-4, FL-4) and **variety = seeded choice over good
   candidates** (ZH-9, WE-3) are both already in the design; the work is reaching the tactical
   planners with them, not inventing them.
3. **The genuinely missing OGameX mechanisms are the ones the strategy catalog already named** —
   per-mission target attractiveness (ZH-6 → T6), cached deterministic simulation (WE-1 → T2/H10),
   composition-aware production/launch (WE-2 → FLE/U), bounded outcome feedback (ZH-8 → H8), and
   derived threat features (ZH-2 → `PerceptionSnapshot`). The classical corpus adds *confidence*
   to those, not new ones.
