# Persona decomposition — one label per question

Written 24 Sep 2026. Owner direction: `AiArchetype` today means *strategy + activity + aggression +
defence + class affinity all at once*, so a cohort of 300 players collapses onto a handful of
overlaps and the differences that should matter (how much this account is around, what it believes
about defence, what it does with accumulated resources) are not representable. This spec decomposes
the single archetype label into the few persisted dimensions that cannot be expressed by the already
shipped continuous traits (`persona-taste.md`, `DEF-028`/`DEF-029`), and rewires each consumer so the
archetype means exactly one thing.

## The correction

`AiArchetype` means exactly one thing: **how does this account primarily expect to grow.**

| Archetype | Primary growth loop |
| --- | --- |
| `Miner` | mine ROI, expansion, production |
| `Turtle` | production + strategic static defence |
| `Raider` | many small/medium profitable raids |
| `Fleeter` | fleet hunting, crashes, timing, PvP |
| `Hybrid` | meaningful economy + meaningful active fleet |

`Expeditioner` (expeditions as a major income source) is **deliberately not added yet**: a personality
label that cannot affect behaviour is worse than leaving it out, and expedition behaviour is not yet a
major income source (`WP-009` composes and dispatches expeditions, but nothing makes them a growth
loop). It joins when expedition behaviour lands.

## The target profile

```text
AiProfile
├── archetype            what is my primary growth loop?   (enum, 5 cases)
├── skill_band           how well do I reason?              (existing AiSkillBand)
├── activity_band        how much am I around?              (enum, 4 cases)
├── defense_doctrine     what do I believe about static defence?  (enum, 8 cases)
├── stockpile_strategy   what do I do with accumulated resources? (enum, 6 cases)
├── economic_role        how do I interact with the resource market? (enum, 5 cases)
├── random_seed
└── persona_version      bumped when generation distributions change
```

Plus the existing derived continuous traits (already shipped, kept as modifiers):

```text
PersonaTaste { float diligence; float aggression; float sociability; }
```

And the host-owned `CharacterClass` (an actual game mechanic, selected by the persona, kept stable
after selection).

That is the whole schema. No fleet doctrine, research doctrine, colonisation doctrine, targeting
doctrine or fleetsave doctrine — those are useful eventual dimensions, but an enum is dead machinery
(gate 2) until a planner genuinely consumes it.

## The enums

Values are module policy, not host object data: none is an object id, machine name or requirement, so
they do not touch gate 1.

- `AiArchetype`: `Miner = 1`, `Turtle = 2`, `Fleeter = 3`, `Raider = 6`, `Hybrid = 7`.
  The current `Trader = 4` and `Casual = 5` are **never silently reinterpreted** — persisted enum
  values are retained as migration-only cases or migrated explicitly (`PERS-008`), never renumbered.
- `AiActivityBand`: `Casual = 1`, `Regular = 2`, `Active = 3`, `Hardcore = 4`.
- `AiDefenseDoctrine`: `Minimalist = 1`, `ProductionShell = 2`, `FodderHeavy = 3`, `RocketPlasma = 4`,
  `BalancedMixed = 5`, `HeavyMixed = 6`, `Bunker = 7`, `Adaptive = 8`.
- `AiStockpileStrategy`: `ImmediateSpender = 1`, `ScheduledSpender = 2`, `GoalSaver = 3`,
  `FleetSaveBanker = 4`, `BunkerBanker = 5`, `CarelessHoarder = 6`.
- `AiEconomicRole`: `SelfSufficient = 1`, `DeutSeller = 2`, `DeutBuyer = 3`, `ActiveTrader = 4`,
  `AllianceSupplier = 5`. `ActiveTrader` is an identity field today, not a strategic input — there is
  no trade planner yet, so it must not pretend to have strategic significance.

`AiSkillBand` keeps its cases and its noise semantics, and additionally controls *reasoning
sophistication* (`PERS-009`).

## What the continuous traits already cover

`PersonaTaste` (diligence, aggression, sociability) is the right representation for variation along a
dimension, and it stays. The modifiers from the direction:

```php
$minimumProfit  *= lerp(1.30, 0.75, $taste->aggression);
$reactionDelay  *= lerp(1.40, 0.70, $taste->diligence);
$socialInitiationProbability = f($taste->sociability);
```

`patience` is the only candidate additional trait (saving for Astro, waiting for better targets,
long research goals) and is **deferred** until a system needs it.

## Generation — never independent draws

The fields must be generated together, not independently, so the persona is coherent and stays
coherent across distribution edits. One factory, one distribution table:

```php
final class AiPersonaFactory
{
    public function create(int $seed, RandomSource $random): AiPersona { ... }
}
```

Conditional distributions: the archetype selects the defence-doctrine distribution (e.g. Miner:
ProductionShell 40%, Minimalist 20%, BalancedMixed 15%, RocketPlasma 10%, Adaptive 8%, FodderHeavy
5%, Bunker 2%; Turtle: Bunker 30%, RocketPlasma 20%, BalancedMixed 20%, HeavyMixed 10%, FodderHeavy
10%, Adaptive 10%; Fleeter: Minimalist 55%, ProductionShell 25%, BalancedMixed 10%, Adaptive 7%,
other 3%), and skill shifts the same distribution (a veteran turtle tilts toward Adaptive, a novice
turtle toward Bunker/FodderHeavy). The percentages are simulation choices, not historical statistics.

Character-class selection becomes weighted rather than the current hard mapping in
`SeedAiTestUniverseAction::characterClass()` (Miner→Collector, Turtle→General, Trader→Discoverer,
the Turtle→General mapping being especially questionable). The weight is a function of archetype and
skill; once selected, the class is kept stable unless class changes are deliberately implemented.

`persona_version` is bumped whenever a generation distribution changes, so a seed-derived account
does not silently change identity six months later just because the distribution did.

## The migration path

| Phase | Task | What changes | Consumers rewired |
| --- | --- | --- | --- |
| 1 | `PERS-001` | schema + four enums + `persona_version`, deterministic backfill; no behaviour change | none yet |
| 1 | `PERS-002` | `AiPersonaFactory` + conditional distributions + weighted class selection | `SeedAiTestUniverseAction` |
| 2 | `PERS-003` | presence band keyed by `activity_band`, not archetype | `RoutineProfile::presenceBand` |
| 3 | `PERS-004` | `DefenseNeedEvaluator` → `DefenseNeed` | `QueueableUnitPlanner::needsStandingDefense`/`standingDefenseFloor` |
| 3 | `PERS-005` | `DefenseCompositionPlanner` | `QueueableUnitPlanner::bestDefense` |
| 3 | `PERS-006` | `ThreatResponsePlanner` (inbound → intents) | `QueueableUnitPlanner::underAttack` path |
| 4 | `PERS-007` | `SavingsGoal` — spend respects a reserved goal | `EconomyUpgrades`/`QueueableBuildingPlanner` |
| 5 | `PERS-008` | add `Raider` + `Hybrid`, migrate `Trader`/`Casual` | every `match(AiArchetype)` site |
| 6 | `PERS-009` | skill band branches planner sophistication | unit/raid/FS/economy planners |
| 7 | `PERS-010` | `allows()` = legality, preference replaces prohibition; vengeance → aggression | policies, `NativeAffectEngine` |
| — | `PERS-011` | deferred: `Expeditioner`, `ActiveTrader` significance, `patience` | — |

The order matters: behaviours move off the archetype (phases 2–4) **before** the deprecated
`Trader`/`Casual` cases are removed (phase 5), so no account changes identity while its behaviour is
still archetype-derived.

## The defence redesign

Today the module has two archetype-keyed decisions: `needsStandingDefense` / `standingDefenseFloor`
(a ratio of fleet value per archetype) and `bestDefense` (max attack-per-cost). The direction replaces
the conceptual dependency, not the ratio:

- **`DefenseNeedEvaluator`** returns a `DefenseNeed` value object — `protectedValue` (offline
  production, stored resources, stationary fleet/satellite value), `currentDefenseValue`,
  `ThreatBand`, `DefenseIntent` — from the account's activity/offline duration, defence doctrine,
  archetype, skill, known local threat (when skill supports it) and any incoming attack.
- **`DefenseCompositionPlanner`** handles *what to build* independently of *whether more is needed*:
  what assets this persona intends to leave exposed → would the doctrine consider them underprotected
  → what wall composition is the doctrine targeting → which component is most below its target ratio →
  how much can be queued this session.

This is the place the monoculture breaks: two veteran miners (`ProductionShell` vs `Minimalist`)
genuinely disagree, as do three turtles (`RocketPlasma` / `BalancedMixed` / `Adaptive`). It also
reconciles with the decision doctrine `D7` (defence = unprofitability, not a ratio): the evaluator
reads the observed attacker where the doctrine names it, the composition planner is the mechanism.

- **`ThreatResponsePlanner`** replaces the `underAttack()` shortcut that today builds one random
  high attack/cost turret on the first eligible planet. An inbound produces response intents —
  `evacuateFleet`, `evacuateResources`, `reinforceDefense`, `attemptNinja`, `DoNothing` — potentially
  several at once, and the existing individual planners fulfil them. This eliminates the
  "first eligible planet gets one defence unit" shape naturally.

## The stockpile problem needs goals

Today the economy asks *can I afford the best available thing?* — buy if yes, don't if no. That cannot
produce intentional saving, and it is the cause of the enormous accidental stockpiles (the 2B-metal
pathology) that defence must not be the primary answer to. The direction introduces `SavingsGoal`
(target cost, priority, `reconsider_at`) so a `GoalSaver` can say: available 2.0B metal, goal
Astrophysics, spendable 80M, reserved the rest. `AiStockpileStrategy` decides *how* resources are
spent, never *whether* `decision-doctrine.md` `D10` (spend-first, never grow the store to chase it)
binds the `ImmediateSpender`; the `GoalSaver` is taste over `D10`, not a contradiction of it.

## Skill sophistication

`AiSkillBand` today means mostly *how noisy is this AI* — a veteran turtle and a novice turtle build
the same defence, differing only in decision noise. That undermines the skill concept. `PERS-009`
adds, per planner, one `match ($skill)` branch over *what the player can effectively reason about*:

| Behaviour | Novice | Standard | Veteran |
| --- | --- | --- | --- |
| Defence | fixed package | profitability-aware | local-threat simulation |
| Stockpile | reacts late | spends/FS sensibly | saves intentionally for goals |
| Raids | simple profit | profit + risk | target history, distance, fleet counters |
| Fleetsave | simplistic | reliable | varied / timing-aware |
| Fleet composition | favourite/static ratio | sensible doctrine | target-sensitive |
| Economy | affordable ROI | ROI + goals | opportunity cost + long-term goals |

Not different code paths — a small `match` per planner, the same shape `AiSkillBand` already uses for
noise.

## Soften the action forbids

A miner can raid; a turtle can raid; a trader can raid. `allows() === false` per archetype is a
software compatibility boundary today, not an authentic OGame behavioural boundary. `PERS-010` splits
legality from preference: `allows()` should eventually represent *can this action physically/rules-wise
happen*, and the persona expresses *how likely am I to choose it* — Miner 0.15, Turtle 0.08, Raider
1.00, Fleeter 0.85, Hybrid 0.55, modulated by activity, aggression, skill and target profitability.
During migration the existing forbids stay, explicitly marked technical debt, so behaviour does not
suddenly explode.

Vengeance stops keying off the archetype directly. Instead:

```text
vengeanceWeight = 0.15 + 0.85 × aggression + archetypeVengeanceBias
```

with a small bias (`Fleeter +0.15`, `Raider +0.10`, `Turtle +0.05`, `Hybrid +0.05`, `Miner 0`), so
the difference between two fleeters exceeds the artificial gap between every fleeter and every miner.

## The gates

- **Gate 1** — the enums are module policy over host state; no object id, machine name or requirement
  appears, and adding a host object never needs a module edit. The composition planner reads the
  defence objects from the host (`ObjectService::getDefenseObjects()`), exactly as `bestDefense` does
  today.
- **Gate 2** — each new class *replaces* an existing archetype-keyed helper rather than sitting beside
  it: `DefenseNeedEvaluator` replaces `needsStandingDefense`/`standingDefenseFloor`, the composition
  planner replaces `bestDefense`, the threat planner replaces the `underAttack` shortcut, `SavingsGoal`
  is a value object consumed by the existing economy path, `AiPersonaFactory` is the single generation
  entry. Nothing forwards, nothing is a single-implementation abstraction, and the direction's own
  deferrals (`Expeditioner`, `ActiveTrader` significance, fleet doctrine, `patience`) are honoured as
  deferrals, not shipped early.
- **Gate 3** — every mechanism names an experienced player: a miner who sometimes raids, a turtle who
  believes in rocket/plasma, a goal-saver holding for Astro, a fleeter who scans before committing.

## Acceptance

- A veteran turtle and a novice turtle construct defence differently (`PERS-004`/`PERS-005`/`PERS-009`),
  proven by a test.
- Two veteran miners with different defence doctrines disagree (`PERS-005`).
- An account whose presence band is Casual plays 1–3 sessions/day regardless of archetype (`PERS-003`),
  and diligence places it inside its band.
- A `GoalSaver` with a declared Astrophysics goal spends only `available − reserved` and never burns
  the reserved pile on an affordable mine (`PERS-007`).
- `Trader` and `Casual` disappear from the archetype as behaviours, migrated explicitly, with no
  silently renumbered persisted value (`PERS-008`).
- `allows()` refuses only on legality; a miner can raid at a low probability modulated by aggression
  and skill (`PERS-010`), with the existing forbids kept during migration and marked technical debt.
- Every changed mechanism keeps the deterministic seeded replay (`random_seed` + `RandomSource`), and
  `persona_version` pins the generation distribution so a replay is stable across distribution edits.
- Changed module code keeps 100% PCOV; full Pest green; `bash scripts/ogamex gate` clean; Pint clean.

## Slices

| Code | Kind | Priority | Depends on |
| --- | --- | --- | --- |
| `PERS-001` | impl | P1 | — |
| `PERS-002` | impl | P1 | `PERS-001` |
| `PERS-003` | impl | P2 | `PERS-001` |
| `PERS-004` | impl | P1 | `PERS-001` |
| `PERS-005` | impl | P1 | `PERS-004` |
| `PERS-006` | impl | P2 | `PERS-004` |
| `PERS-007` | impl | P1 | `PERS-001` |
| `PERS-008` | impl | P2 | `PERS-002`, `PERS-003`, `PERS-004`, `PERS-005`, `PERS-007` |
| `PERS-009` | impl | P2 | `PERS-004`, `PERS-005` |
| `PERS-010` | impl | P2 | `PERS-008`, `PERS-009` |
| `PERS-011` | deferred | P3 | — |

The ready entry point is `PERS-001` (schema + enums + backfill, no behaviour change), which unblocks
the whole graph.
