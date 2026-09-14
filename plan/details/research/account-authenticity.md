# Account authenticity: what humans and operators can actually observe

Researched 14 September 2026 from public sources only. This is the evidence base for the
product goal "no one will know", and it exists because *authentic* has to mean something
measurable rather than something we believe.

Owner: product/gameplay with runtime. Consumed by [player personas](player-personas.md),
[player model](../specs/player-model.md) and the Phase 3 conversation and session slices.

## The two questions this document separates

1. **What would a suspicious neighbour notice?** This is the axis the product goal is about.
2. **What would an operator police?** This is a rules question, and on OGameX it is a
   *policy choice of the server owner*, not something a design can hide.

Conflating them is the most likely way to get this wrong, so they are kept apart below.

## 1. The operator boundary (official OGame, for reference only)

Official OGame's rules are unambiguous, and the direction of travel over twenty years has
been monotonic: toleration narrowed to a reviewed whitelist, and it narrowed specifically
around anything that acts without a human click.

| Rule text | Source | Confidence |
| --- | --- | --- |
| "Using a programme as an interface between the player and the game is prohibited." | [Game rules](https://board.en.ogame.gameforge.com/index.php?thread/852578-game-rules/) | HIGH |
| "Using bots, macros, automated scripts, etc. will be treated as scripting unless otherwise expressly approved by Gameforge. The tools found in The List of Tolerated Addons … are the only in-game tools that are legal to use." | [US rules](https://board.us.ogame.gameforge.com/index.php?thread/51369-ogame-us-game-rules/) | HIGH |
| "Any kind of automation that executes a sequence of actions without corresponding user interaction is prohibited." | [Forbidden features](https://forum.origin.ogame.gameforge.com/forum/thread/29-forbidden-features/) (last revised March 2026) | HIGH |
| "No Scheduling: Delayed or scheduled executions … are forbidden." | same | HIGH |
| "Background calls that cause uncontrolled or unwanted activity (triggering an 'activity star' in the Galaxy view) or cause unnecessary server load are strictly forbidden." | same | HIGH |
| "Tools may not silently scrape a user's private data (such as messages, exact fleet compositions, session tokens, or precise activity times)." | same | HIGH |
| Toleration is whitelist-only: "If a tool is not listed here, it is not permitted for use, regardless of where it is published." | [About toleration](https://forum.origin.ogame.gameforge.com/forum/thread/320-important-for-users-about-toleration-and-tolerated-tools/) | HIGH |
| An AI coding agent "will happily implement things that are flatly forbidden: automation, scheduling, attack alarms and Discord pings, direct probing from custom target lists… A fair number of recent submissions have failed review for exactly these reasons." | [AGENTS.md tool-rules notice](https://forum.origin.ogame.gameforge.com/forum/thread/527-agents-md-keep-your-ai-coding-agent-within-the-tool-rules/) (July 2026) | HIGH |

Three consequences, stated plainly:

- **On official OGame, this module is prohibited by construction.** Playing without a human
  click and never emitting an activity star are mutually exclusive. There is no disclosure
  channel equivalent to a declared "bot account" elsewhere; the whitelist *is* the disclosure
  mechanism and it exists for tools that assist a present human.
- **On OGameX the owner is the operator**, so this is a server-policy decision, and the
  [product spec](../specs/product.md) already takes the honest position: server rules explain
  automation and account information identifies it. That stance is what keeps the project
  legitimate; nothing in this document should be read as a technique for concealing automation
  from an operator who forbids it.
- **The operator-facing tell is our request footprint**, not our prose. Activity stars we emit
  and the times we emit them are first-class observability, and they are the thing an operator
  can see trivially and a neighbour can see for free.

## 2. What players actually suspect, and how they test it

The canonical example is the "player active 24/7?" thread
([board, April 2020](https://board.en.ogame.gameforge.com/index.php?thread/816703-player-active-24-7/),
quotes HIGH, factual weight LOW — these are unverified player claims about other players):

- **Uptime without a sleep window.** "everyone need to sleep sometime, so is not possible to
  be active 24/7"; the detail that convinced them was persistence — "active from day 1 since
  uni started more then 100days".
- **Never losing a fleet.** "i tried to attack them, also on spy mission but no luck… they
  always fs."
- **Narrow, unprofitable automation.** Sending expeditions while never collecting the debris
  field.
- **The confound, from the accused side.** A player admits playing "16 - 17 hours a day
  because of the virus" and being accused anyway. **This is the calibration point: uptime
  alone is weak evidence, because a documented human generated the same accusation.**

**How they probe.** Players described sending dummy attacks to force a fleet movement, spying
"like crazy" to learn when the fleet moves, and trying an espionage mission on the theory that
"some bots register this as an espionage mission so it would bypass the auto-fleetsave". One
also noted that "if you spy someone his moon becomes active" — i.e. players know the activity
marker is contaminated by other people's probes.

**The second accusation genre is economic, not temporal.** In the 2018 ["Ban wave"](https://board.en.ogame.gameforge.com/index.php?thread/801820-ban-wave/)
thread the trigger was growth: "A 11 billion push in a day is a complete joke". A player
states the epistemology outright: "People don't need compelling evidence to suspect foul play
in such a case… rate of growth feels much more related to time invested than just experience."

**The third is prevalence belief.** A player on the Italian server asserted "almost 60-70% of
logged in players botting h24 … sleeping 1-2 hours per night" (HIGH for the quote, LOW for the
number — it is an unsourced assertion). A moderator's entire answer: "Ban information is not
public, and Bots are not allowed."

## 3. How detection actually works

- **There is no published methodology and no published punishment table, deliberately.** A
  board administrator: "There is no list because it was asked to keep internally. Yes, there
  are fixed ban lengths. No, they have never really been made public. It is labeled as
  internal." (MEDIUM-HIGH; staff statement, 2020.)
- **Investigations are manual and effortful, and at least one rule is report-triggered.** A
  then-GO: "A ban is not processed without an investigation. That investigation requires effort
  (significant effort in the cases of scripting)." The rules state for bashing that "a bashing
  investigation will only proceed if a player is reported" (HIGH for the rule text; the
  scripting analogue is not published, so extending it would be speculation).
- **Ban status is publicly visible in-game** (struck-through name in galaxy view) but the
  public pillory was removed, with conflicting testimony about why (MEDIUM).
- **Mass waves happen and are at least partly economy-driven.** The 2018 wave was described by
  players as hitting push networks rather than bot signatures (MEDIUM).
- **No OGame ban, prevalence or detection-rate statistic exists publicly.** Do not invent one.
  The only hard numbers available are from a different operator: Jagex reported banning "over
  6.9 million accounts" in a year with a **0.36% appeal-quash rate**, and stated the honest
  expectation — "it's always an arms race", "we do get to these bad actors, but it takes time"
  (HIGH, [official post](https://oldschool.runescape.wiki/w/Update:Bots,_Bans_and_Appeals:_An_Update)).

## 4. What is observable, at what resolution

| Instrument | Resolution | What it can and cannot show | Confidence |
| --- | --- | --- | --- |
| Activity star (galaxy and moon view) | "active in the last 15 minutes", then a minute counter up to 60 | Fleet/mission events, not presence. **Contaminated**: another player probing lights up your moon. No login, logout, session length or IP. | MEDIUM-HIGH |
| Public statistics API `highscore.xml` | **hourly** | A per-player score series any third party can plot. This is the single most externally measurable thing about an account. | HIGH |
| `universe.xml`, `playerData.xml` | weekly | The planet-and-owner map. | HIGH |
| `players.xml`, `alliances.xml`, `serverData.xml` | daily | Roster and settings. | HIGH |
| Report counts (combat/espionage/recycler/missile), statistics API | — | Aggregate activity, queryable by approved third parties. | HIGH |
| Espionage reports | per mission, costs a slot, lights the target's marker | Another player's building levels and fleet, at a real cost to the prober. | HIGH |
| Login/logout times, session counts, IPs | — | **Not exposed to other players** on the evidence found. | Not established that it is exposed; the contrary is what the sources support |

**So a suspicious neighbour has two precise instruments — hourly score deltas and the 15-minute
activity marker — and one free provocation — attacking or probing us.** Everything else is
inference from those.

## 5. What the broader literature says actually discriminates

The strongest available quantitative study is Kang, Jeong, Mohaisen & Kim, *Multimodal Game Bot
Detection using User Behavioral Characteristics* ([arXiv:1606.01426](https://arxiv.org/html/1606.01426v1)),
trained on 49,739 characters with 7,702 labelled bots (HIGH for the paper's content).

| Feature family | Measured separation |
| --- | --- |
| Social-interaction diversity (Shannon entropy over interaction types) | bots **0.4299** vs humans **0.8352** — the largest gap reported |
| Social-graph degree | party network **1.4 (bots)** vs **25.41 (humans)**; whispers 1.51 vs 15.31 |
| Session length | "80% of game bots last longer than 4 hours 10 minutes, whereas 80% of human users last less than 2 hours 20 minutes" |
| Action mix | bots do not pursue rankings: humans earn "PK points" about three times as often |
| Harvest rate | "60% of game bots harvest more than 5,000 items a day", which is "almost impossible" for humans |
| Classifier result | random forest, 62 features: accuracy **0.961**, precision 0.956, **recall 0.742** — the authors concede the recall is low and that false positives are "exceedingly similar" to true positives |

**Self-similarity is the best-validated single detector.** Bots "repeatedly do the same series
of actions, therefore their action sequences have high self-similarity" (Lee et al., NDSS 2016,
MEDIUM-HIGH at abstract level). This is exactly what a schedule-driven agent produces, and it is
the risk our session design has to be measured against.

**The industry's most reliable family is unavailable here, in both directions.** Mouse
trajectories and keystroke dynamics need a client input stream ([BeCAPTCHA-Mouse](https://www.sciencedirect.com/science/article/pii/S0031320322001248),
[keystroke dynamics](https://arxiv.org/html/2303.04605v2), MEDIUM). In an asynchronous
server-rendered browser game the entire observable surface is a sequence of HTTP requests bound
to a session cookie. So nobody can fingerprint our typing, **and** the features that remain — what
we did, when, and how consistently — are the weaker family whose measured recall is 0.742.

**Human judgement is a weak detector.** In the 2012 2K BotPrize two bots out-scored the human
players, and the average "humanness" rating given to the *human* players was only **41.4%**
(MEDIUM, [Wikipedia summary of the BotPrize results](https://en.wikipedia.org/wiki/Computer_game_bot_Turing_test)).
Combine that with report-triggered investigations and the practical risk is not "being proven" —
it is "being reported often enough to warrant a manual look at exact logs".

## 6. Player taxonomies are dimensional, not categorical

The plan's archetypes are a design vocabulary, and this is the evidence that they must not be
treated as fixed types.

- **Bartle's taxonomy was never a validated instrument.** It is a design thought-experiment from
  MUD observation, and Bartle himself distances himself from the derived test: "I'm often asked
  about the Bartle Test … Sadly, I'm not [responsible for it]" (HIGH on the framing, MEDIUM on the
  mediation).
- **The direct factor-analytic test failed to replicate it.** Yee's analysis of roughly 7,000 MMO
  respondents found Bartle "captured 3 motivations fairly well" while including an unrelated
  element or excluding a related one in each case — and specifically, "What was very interesting
  was to not see the Explorer type validated" (HIGH, [author's own write-up](https://nickyee.com/facets/revisiting.html)).
- **The successors are dimensional and blended.** Quantic Foundry derives 9 gamer types from
  segment analysis of 500,000+ profiles, states they are not mutually exclusive, and reports
  **81 unique blended combinations** (HIGH for the page content; the underlying dataset is
  self-reported).

**Design consequence:** keep motivations as continuous scores with low per-account test-retest
meaning, never as a type label. "There are no player types" is the defensible position.

## 7. Authenticity checklist

Ranked by observability × diagnosticity: what a neighbour or operator can actually see, and how
much it actually implies.

| # | Signal | Observable | Diagnostic | What it requires of us |
| --- | --- | --- | --- | --- |
| 1 | Reaction latency to a probe or attack, and whether a save ever fails | HIGH — directly provocable and free | HIGH — the one thing a player can *test* | Reaction must be gated on plausible perception and decision time, and must sometimes fail. A 100% save rate over months is itself the outlier. |
| 2 | Uptime shape across the 24-hour cycle | HIGH — activity markers sampled over days | MEDIUM — strong in aggregate, weak alone (a legit 16–17 h/day player was accused) | A real sleep window, a diurnal shape, and genuine week-to-week irregularity. Not fewer hours — *variability*. |
| 3 | Growth curve | HIGH — `highscore.xml` is hourly and public | MEDIUM-HIGH — the trigger of the 2018 accusations | Growth must be explicable by visible behaviour and must not spike without a visible cause. Mechanically optimal economy play is a tell. |
| 4 | Self-similarity of the action sequence | MEDIUM — needs days of watching, which players do | HIGH — the best-validated server-side detector across three commercial MMOs | Jittered, non-stationary schedules; occasional suboptimal choices; different behaviour on different days. |
| 5 | Social breadth and social-graph shape | MEDIUM — messages, alliance membership, report sharing | HIGH — the largest measured gap in the literature | Low-efficiency social behaviour: conversations that are not task-shaped, relationships that are not all instrumental, contact outside the alliance. |
| 6 | Never losing a fleet, never missing a return | MEDIUM — only visible through repeated attempts | MEDIUM — happens to good humans too; diagnostic only in aggregate with #1 | Deliberate, survivable losses. Perfect defence is a signature. |
| 7 | Message content and reply timing | MEDIUM — only if we speak; report sharing spreads screenshots past the alliance | MEDIUM — plausible, but **not established** as an OGame accusation in any source found | Authored, situation-specific text; never templated; never instant. |
| 8 | Our request footprint and activity stars | HIGH — trivially, to the operator | HIGH but binary — a **policy** boundary, not an authenticity one | Cadence indistinguishable from a human opening pages, and no stars on planets we have no reason to be viewing. |
| 9 | Inter-account transfers and their timing | MEDIUM — recipients see timestamps | MEDIUM — the *rule* problem (pushing/pulling, ratios, completion windows) is likelier to catch us than the authenticity problem | Human-scale latencies and ratio discipline on every transfer. |
| 10 | Report rate | HIGH — reports are free | HIGH as a trigger, low as proof | Authenticity's real job is to keep the report rate at human-background levels. |
| 11 | "Always-exact resources for moonshot attempts" | — | — | **Not established.** No source describes this as a player observation. Do not build a rule on it. |

### The capability chain these signals require

Every signal above is produced by *playing*, and playing is a chain rather than a set of independent
actions. It is written down here because it was not recorded anywhere, and the cost was a capability
set that could produce none of the top-ranked signals:

```
metal / crystal / deuterium mine, solar plant
        → robot factory
        → shipyard            → units
        → research lab        → research
        → fleets              → fleetsave, probes, raids, colonies
```

| Signal | The gameplay it needs | How the chain was broken |
| --- | --- | --- |
| 1 — saves, and saves that sometimes fail | a fleet worth saving, and the judgement not to always | no shipyard could be built |
| 3 — growth curve | buildings *and* research *and* units, in that order | the buildable set was four requirement-free mines and a plant |
| 5 — social breadth | probes, messages, contact outside the alliance | no probes, because no shipyard |
| 6 — deliberate survivable losses | a fleet to risk | no fleet |

The rules that follow, so this cannot recur silently:

- **A capability set is complete against a goal, never against a list.** An account that can build
  mines but never a shipyard cannot produce a growth curve, a save metric or a fleet loss, so no
  pilot run can report on signals 1, 3, 5 or 6 at all.
- **A slice that adds abilities must show the account reaching the next stage of the chain.**
  "The cohort acts" was satisfied by one building type, which is exactly how a dead end passed
  acceptance.
- **The chain is a fact of this game, not a policy choice.** Widening the buildable set to reach the
  enablers is correctness work, not new scope.

### Signals we cannot fake, and what follows

1. **There is no client-side input stream.** Nobody can fingerprint our input, and we cannot
   demonstrate human-like input. Judgement will rest on action-level and timing-level features.
2. **Every action requires a request, and every request may stamp an activity star.** A tool can
   be switched off; an agent that plays cannot. We can shape the cadence, we cannot remove the
   timestamps.
3. **Any scheduler we write is finite and authored**, and the self-similarity literature is a
   direct statement that this becomes visible in the logs over time. The mitigation is measured
   irregularity, not a promise of it.
4. **We cannot honestly occupy the tolerated-tool category**, because that category is for tools
   that assist a present human. Disclosure is a server-policy question for OGameX.
5. **We cannot control how confident an observer is, only our exposure.** Judgement is shaped by
   outcomes — did you lose a fleet, did you grow faster than me.

## 8. Gap register

| # | Question | Status |
| --- | --- | --- |
| G1 | OGame ban volumes, bot prevalence, detection rate | **Not published.** Every forum number is an unsourced assertion. |
| G2 | How Gameforge actually detects scripting | **Not published.** Public record shows report-triggered, manual investigation only. |
| G3 | Whether players suspect *AI personas* rather than scripts and multi-accounts | **Not found.** All located suspicion talk is about automation, multi-accounting and pay-to-win. |
| G4 | Community reaction to a disclosed AI account in a browser game | **Not established.** No verifiable precedent located. |
| G5 | The exact highscore statistic categories available over the API | **Unverified** in this pass. |
| G6 | Rule churn | **Flagged.** The 20-second rule was cancelled in 2024 and reinstated in 2025; the pulling rule was added in 2025; the English rule set was re-posted in August 2025. Re-verify before relying on any rule text. |

Research to validation: the checklist items are hypotheses about *perceived* humanity, and they
are testable in the disclosed pilot by asking human reviewers to read anonymised traces and say
which accounts they think are automated, and why — while keeping individual schedules and
message histories private, and never reproducing real players' identities or messages.
