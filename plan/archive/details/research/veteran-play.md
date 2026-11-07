# How experienced players actually play

Research note, 14 September 2026, rewritten after a verification pass over every cited page. It exists to
answer one question for [gate 3](../specs/cognition-gates.md): *can the mechanism be named as something an
experienced player does?* Where the sources disagree, the disagreement is recorded rather than resolved,
and the module's own choice is stated with its reason.

This pass fetched every URL and checked each claim against the page that is supposed to contain it. The
result is not only new material but a set of **retractions** — several claims in the first version were
not on the pages they were cited to, and they are listed in [§11](#11-verification-status). The
algorithms that follow from this note are in
[the gameplay algorithms](../specs/gameplay-algorithms.md).

Confidence markers: **MEASURED** (figures with a source) · **DOCUMENTED** (guide, wiki or official) ·
**ANECDOTAL** (named player) · **CONTRADICTED** (sources disagree) · **ESTIMATE** (our synthesis) ·
**NOT FOUND** (looked for, does not exist on any reachable page).

## 1. The opening, and how it is really decided

**DOCUMENTED.** Two published openings, both step sequences, and they agree on the *shape* and diverge on
the order and the research entry point:

- Sidian: "Metal Mine 1 → 4 / Crystal Mine 1 → 2 / Solar Plant 1 → 3 / Metal Mine 5 → 7 / Crystal Mine 3 → 5 /
  Deuterium Synthesizer 1 → 3 / Robotics Factory 1 → 3 / Research Lab 1", with energy technology 1 →
  combustion 2 → espionage 2 → computer 2 → impulse 1 on day one.
  [Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started)
- OGames: "1. Metal Mine → 2, 2. Metal Mine → 3, 3. Solar Plant → 2, 4. Metal Mine → 4, 5. Crystal Mine → 2,
  6. Solar Plant → 3, 7. Metal Mine → 5", ending day one at metal 6–8, crystal 4–5, deuterium 2–3,
  robotics 1, with an explicit "do not build a Shipyard yet" and the shipyard deferred to days 4–5.
  [OGames first week](https://ogames.net/blog/ogame-build-order-first-week)
- The official tutorial is non-numeric and states only the reason: "Without energy, the mine is not able
  to produce any resources", with the tutorial reward steps at metal 4 / crystal 2 / solar 4 and metal 10 /
  crystal 7 / deuterium 5. [Tutorial 01](https://board.en.ogame.gameforge.com/index.php?thread/813416-tutorial-01-basic-economy/)

**CONTRADICTED (divergence).** Past roughly ten steps the sequences are not the same plan. The stated goal
is not in dispute — "get a small cargo ship as soon as possible to start raiding inactive players", probes
before warships — but a step table is not a rule, and the module keeps none
([E5](../specs/gameplay-algorithms.md#e5--the-opening-and-where-taste-is-allowed)).

**MEASURED / ANECDOTAL.** What actually decides the next mine is **payback time**, stated independently
three times: "you should prioritize mines with lowest amortization first, i.e. build the mines which repay
their cost the fastest"; "you simply get the cheapest mine first: it is not a guarantee of best
amortization, but it provides good results"; "around 35/30/30 is where people usually stop mining".
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/) ·
[ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/)

**MEASURED.** The mechanics that make payback the right question: costs rise "50% for each level of Metal
and Deuterium mines, and 60% for each level of Crystal mine" while production grows "starting from 10 and
increasing in accordance to mine levels, about 15% on average" — so a mine's payback lengthens as it
levels. Crystal mines age worse than metal, and deuterium worse than crystal; the miner guide's own
late-game table peaks around 40/32/36 and says that after astrophysics 23 "the ROI is not good".
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**DOCUMENTED.** The written form of the comparison is explicit: "Payback (hours) = Upgrade Cost (in
metal-equivalent units) ÷ Hourly Production Gain", with "accept paybacks up to about 2-3 real days at any
speed" and cost weightings given as `1/3 M + 1/2 C + D` or `M + 1.5 C + 3 D` against the "conventional
trade rates 3:2:1".
[OGames payback](https://ogames.net/blog/ogame-mine-ratios-payback-times) ·
[ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/)

**CONTRADICTED.** Level-ratio heuristics exist and disagree: "Metal Mine roughly twice the level of
Crystal Mine, and Crystal Mine roughly twice the level of Deuterium Synthesizer" (2:1:1 in *levels*),
versus the offset form "Metal Mine = Crystal Mine + 3 = Deuterium Synthesizer + 5–7", versus an output
ratio of about 2:1.5:0.8 — and the same thread contains players who deliberately ran crystal two levels
*above* metal. **The ratio is personal; the payback rule is not.**

**Module consequence.** The economy order is *marginal production gained ÷ weighted price paid*, with the
horizon and the offsets as persona taste
([E1](../specs/gameplay-algorithms.md#e1--payback-ordering--the-next-mine)).

## 2. Energy — the one place the sources genuinely disagree

**DOCUMENTED.** Both camps agree on the mechanics and on the arithmetic: "Production efficiency =
available energy ÷ required energy (capped at 100%)", so "1,000 energy available and your mines demand
1,200, every mine runs at 83.3% output". [OGames energy](https://ogames.net/blog/ogame-energy-management-guide)

**CONTRADICTED.** One doctrine builds *through* deficits deliberately: older guides "will sometimes make
you drop below 0 energy. That is ok since in each case the gain in resource production is faster than if
energy is built first." The other is stated as plainly: "Always plan your energy supply **one or two mine
levels ahead**. Before you click that mine upgrade, verify that your projected energy surplus covers the
new demand at level completion." The official tutorial takes a third, purely reactive position: "When your
energy level is shown in red, you have to upgrade your solar plant urgently", alongside the option to
"change the production factor of some mines". [OGames energy](https://ogames.net/blog/ogame-energy-management-guide) ·
[Tutorial 01](https://board.en.ogame.gameforge.com/index.php?thread/813416-tutorial-01-basic-economy/)

**DOCUMENTED.** The plant-behind-mine rule appears once, in one source: "keep your Solar Plant level
within one or two levels of your Metal Mine level. If your Metal Mine is at 22, your Solar Plant should be
at 20 or higher". [OGames energy](https://ogames.net/blog/ogame-energy-management-guide)

**DOCUMENTED.** Fusion is conditional on deuterium, not on energy being short: it needs energy technology
3 and a deuterium synthesizer 5, its deuterium consumption is `ROUNDUP(10 × level × 1.1^level)` and its
output `ROUNDDOWN(30 × level × (1.05 + energy_tech × 0.01)^level)`, and players keep the two levels close
("always keep your ETech at the same level as your Fusions or at least one level below"). Solar satellites
are units — affected by temperature, destructible, unrepairable.
[Tutorial 02](https://board.en.ogame.gameforge.com/index.php?thread/813417/) ·
[fusion thread](https://board.en.ogame.gameforge.com/index.php?thread/817798-fusion-reactor-use/)

**CONTRADICTED, and badly.** The plant level at which players switch to satellites is given as **16**
("the minimum level of solar plant when it is cost-efficient to change for satellites is 16"), **18**,
**20–26** ("most miners usually build Solar Plants up to the high twenties"), **25** ("I stopped at 25 when
I started having to spend 1kk+ Metal for 690 Energy when 20 Solar Sats … yielded roughly the same amount"),
**30** and **32** — each with a calculation attached. There is no canonical number.
[solar plant thread](https://board.us.ogame.gameforge.com/index.php?thread/103866-solar-plant-how-high-do-you-go/)

**Module choice.** The module takes the *predictive* doctrine: build capacity before the level that would
outdraw the planet, which reproduces the opening both guide families publish (solar plant first). The
reason is not that deficits are unaffordable but that they are **unobservable in the account's own records
as a decision** — an account that mines at 83% and never fixes it looks like a player who is not paying
attention, which is the one thing ordinary play never looks like. The satellite switch stays a persona
band, never a constant ([Y2](../specs/gameplay-algorithms.md#y2-fusion-satellites-and-where-the-sources-give-up)).

## 3. Storage

**DOCUMENTED.** Storage exists for absence, not for growth: "your storage should hold at least 24–48 hours
of mine production" and "always check your storage before logging off for a long period"; once full "the
mine cannot continue to produce… you will lose important resources, with every minute, in which the
storage is overloaded". [Sidian metal storage](https://sidian.app/s/ogame-wiki/buildings/resources/metal-storage) ·
[Tutorial 01](https://board.en.ogame.gameforge.com/index.php?thread/813416-tutorial-01-basic-economy/)

**DOCUMENTED.** Capacity also decides *how much can be taken*: "You can loot up to 50% of the target's
stored resources", while "resources above storage capacity are fully lootable". One source adds "50% (75%
with Raider class)". [Sidian farming](https://sidian.app/s/ogame-wiki/guides/farming) ·
[Gameforge raider guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)

**CONTRADICTED, and unresolvable.** Three incompatible statements of what storage protects: "resources
within capacity are protected — attackers can only loot a maximum of 50%"; "what is stored in warehouses
cannot be stolen"; and storages "protect up to 10% of your basic daily production". The actionable half
is the first, and it is the one the module uses.

**Capacity formula.** Two forms are in circulation, `⌊5000 × 2.5^level⌋` (Sidian) and
`5000 × ⌊2.5 × e^(20 × level / 33)⌋` (jstar88, trilogi77 — and halfguru's `5000 × 2^level` is simply
wrong), plus a literal table to level 20 in `pyogame` whose author admits he could not find the formula.
It does not matter: the host computes capacity, so the module reads it.

**Module rule.** Upgrade storage when the time to fill the remaining capacity is shorter than the time
until the next planned spend or the planned absence
([E3](../specs/gameplay-algorithms.md#e3--storage--the-fill-time-trigger)) — the trigger the guides
describe in words, rather than the 0.8/0.9/1.0 thresholds the tools each picked differently.

## 4. Research priorities

**DOCUMENTED.** Miner: energy technology 1 early (a fusion and plasma prerequisite), energy 12 for the
terraformer and no further unless fusion is used, laser 12, ion 5, and **astrophysics is the priority**
because colonies are the long-run multiplier; plasma is "indispensable" for mines.

**MEASURED.** The miner roadmap is a table pairing mine levels with astrophysics milestones, from
"1 planet at 14-11-10 → astrophysics 1" to "12 planets at 40-32-36 → astrophysics 23", and it says the
return stops paying after that. The same source says "it is common to stop at 13-11-9 before pursuing
astrophysics" and, crucially for the module, "treat them like they are mines… build whatever has the
shortest return on investment time" — which makes the roadmap a *derived* consequence of one rule, not a
table to copy. [miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**DOCUMENTED.** Fleeter: the General class, combat technologies, and a fleet of battleships, cruisers,
recyclers, large cargos and probes in stated quantity bands ("Battleship 20–50+, Cruiser 10–30, Recycler
5–20, Large Cargo 10–30, Espionage Probe 10+", labelled "recommended quantity"). [Gameforge raider
guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)

**DOCUMENTED.** Turtle: defence exists to make an attack unprofitable rather than to win — "the goal of
defense is not to survive a battle with the attacker, but rather to inflict maximum possible damage,
making the attack unprofitable", and "the best defense is the one that's never used. When you're
unprofitable, you're almost 100% sure to not be attacked". The same source gives the one hard number in
this area: defence repair has a "chance of 70%… with the premium-feature 'Engineer' the chance is 85%".
[Tutorial 03](https://board.en.ogame.gameforge.com/index.php?thread/813418/)
[player styles](https://board.en.ogame.gameforge.com/index.php?thread/810247-guide-01-player-styles-guide/)

**NOT FOUND.** No defence-to-value ratio target exists on any reachable page. The module computes the
defence it wants from the attacker it has actually observed
([U3](../specs/gameplay-algorithms.md#u3-defence--unprofitability-not-ratios)).

**NOT FOUND.** No dedicated *trader* playstyle source exists, and OGameX has no marketplace at all, so
the trader persona is module-defined and must not claim a source it does not have.

## 5. The daily routine

**DOCUMENTED.** The canonical loop, from Gameforge: log in for the daily bonus; check fleets and plan the
save; harvest production before storage fills; restart research and construction; log out — "this routine
takes 10–20 minutes daily". [Gameforge happy hour](https://gameforge.com/en-GB/games/ogame-happy-hour.html)

**DOCUMENTED.** The counts are ranged and not canonical: "most players check in once or twice a day"
(Sidian), "you can comfortably log in 3–4 times per day" at 50× speed (OGames), and "three focused
20-minute sessions per day" (a raiding guide). Best farming windows are "early morning and late night in
your timezone", the overnight window given as 01:00–06:00 local, and "hit them every 8–12 hours".
[Sidian farming](https://sidian.app/s/ogame-wiki/guides/farming) ·
[OGames raiding](https://ogames.net/blog/ogame-raiding-guide)

**DOCUMENTED.** The hard rule that shapes the routine: "If you go offline for more than 30 minutes with a
valuable fleet, fleet save it. Losing a fleet to a spy is the #1 beginner mistake".
[Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started)

**DOCUMENTED (host-verified).** Activity itself is observable and the thresholds matter more than any
habit: the galaxy view shows a per-planet marker for 15 minutes after that body's last update, then a
minute counter to 60, then nothing — so a neighbour can see *which* planet was touched and when.
[OGames activity tracking](https://ogames.net/blog/ogame-activity-tracking-guide)

**MEASURED (host).** The strongest constraint in this whole note is not in a guide at all: OGameX's own
admin detector flags an account with **18 or more distinct hours-of-day containing a mission departure in
a 7-day window**, an expedition re-dispatched within **10 seconds** of its return, or a fleet leaving
within **10 seconds** of an attack departing. Those are the numbers the routine must satisfy, and they are
the acceptance test for the routine slice ([H1](../specs/gameplay-algorithms.md#h1--the-active-hours-constraint)).

## 6. Fleetsaving

**DOCUMENTED.** "A fleet cannot be destroyed when it is in motion", and "vary your timing. Never save to
the same landing time every day. Pattern-aware attackers will deduce your flight duration and intercept
you on return". [Sidian fleet saving](https://sidian.app/s/ogame-wiki/guides/fleet-saving)

**DOCUMENTED.** Ranked patterns: "1) the safest way to fleetsave is between moons via a deploy mission.
2) if you don't have a moon, use the recalled deploy method. 3) ALWAYS time your fleet to land AFTER you
expect to be online", with the note that only harvest, deploy and colonise saves "work in longer terms".
[fleetsaving v2](https://board.en.ogame.gameforge.com/index.php?thread/623529-fleetsaving-version-2/)

**CONTRADICTED (mild).** The landing buffer is given differently: "your fleet should return 10–20 minutes
after you expect to log in" (OGames, a single source) versus a "+30–60 minutes" buffer (Gameforge's own
fleetsave guide) versus the general "land after you expect to be online". The module uses a persona band
inside the reported range and cites all three.

**DOCUMENTED.** The named failure modes are exactly the imperfections an authentic account should
sometimes show, and they are named in the sources: "Calculated too short… Forgot resources… Ignored moon
attack", "The 'Just This Once' Overnight Gamble", "Forgetting to Re-launch After Landing", "Saving to a
Planet Instead of a Moon", "Predictable Save Patterns", "Trusting the Wrong Ally", and the mechanisms they
enable — the "blind lanx" and the forced-recall landing. One source states the consequence plainly: "a bad
FS can be as lethal as not FSing at all".
[Gameforge fleetsave](https://gameforge.com/en-GB/games/ogame-fleetsave.html) ·
[OGames fleet save](https://ogames.net/blog/ogame-fleet-save-guide)
[Sidian fleet saving](https://sidian.app/s/ogame-wiki/guides/fleet-saving)

**NOT FOUND.** No source quantifies how often real players fail a save. The widely quoted "80% of fleets
are lost while offline" could not be found in any source despite targeted searching, and must not be
repeated. The module keeps the failure rate as a **placeholder** realised as the named mistakes above
([V3](../specs/gameplay-algorithms.md#v3-the-save-that-fails)).

## 7. Raiding

**DOCUMENTED.** Profit is the gate, not loot, in the publisher's own words: "Profit = Loot − Deuterium
consumption − expected ship losses", "the stolen resources should be at least triple the deuterium
consumption", "expected ship losses may account for maximum 20–30% of the loot", and "a raid with a
negative result is not a raid — it's a donation to the opponent". Target practice: inactive players,
"miners with high resource production but weak defence", "players who have forgotten their fleetsave",
and a scout-then-read-then-calculate loop. [Gameforge raider guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)

**DOCUMENTED.** Scouting practice: "send 5–10 Espionage Probes" then "calculate if the loot justifies the
attack", and "you need Espionage Technology 5 to see fleet and defence reliably. At lower levels your
reports may be incomplete". One source adds that a target scoring less than a fifth of yours "cannot
defend against your fleet class economically". [Sidian farming](https://sidian.app/s/ogame-wiki/guides/farming) ·
[OGames raiding](https://ogames.net/blog/ogame-raiding-guide)

**DOCUMENTED.** Loot is a single 50% of what is stored (75% for one class) — see §3. **The "loot split"
as a multi-wave fraction that the first version of this note described is not on any page and is
retracted**; the capacity split that `jstar88/Ogame-Algorithms` implements is the game's own
one-raid distribution across the three resources, which is a host mechanic.

**DOCUMENTED.** The bashing rule, quoted: "You are not allowed to attack any given planet or moon owned by
an active player more than 6 times in a 24-hour period. This rule also applies to moon destruction
missions. Probe attacks and interplanetary missile attacks do not fall under the bashing rule… Bashing is
only allowed when your alliance is at war", and — the detail a module-side counter would get wrong —
"attacking fleets that are completely destroyed do not count towards the bashing rule".
[OGame rules §4](https://en.ogame.gameforge.com/ajax/main/rules)

**DOCUMENTED.** Debris: "a percentage of the resources worth of all the ships that were destroyed… usually
30% but can be higher", metal and crystal only, "each recycler can take 20,000 units of resources", a
1%-per-100,000 moon chance capped at 20%, and the deliberate 300-crystal probe-attack field. Debris is
never guaranteed income ([T4](../specs/gameplay-algorithms.md#t4--debris-and-the-second-trip)).

## 8. Colonisation, trade and alliances

**DOCUMENTED.** Slots change what a planet is good at and the sources agree on the direction, not the
boundaries. Crystal in the inner slots, metal in the middle, deuterium in the cold outer ones; position
trades fields against temperature. **CONTRADICTED on the details**: "slots 1–5 get a notable crystal mine
production bonus" versus "only slots 1–3 get crystal bonus. I know because I have a slot 4 planet and get
no extra crystal" versus specific figures "1: 30%, 2: 22.5%, 3: 15%"; and "any position other than 8 is a
mistake and a complete waste" versus "slot 12–15 for deut" and "planets in slots 13-15 run cold… produce
substantially more". Since the host publishes its own position bonuses, the module reads them
([C1](../specs/gameplay-algorithms.md#cl1--slot-choice)).

**DOCUMENTED.** Trades must sit inside accepted practice: "trades, recycling help & ACS splits must be
completed within 72 hours" and no account may obtain "unfair profit from the resources of a lower ranked
player… manipulating trade ratios for a higher-ranked account to gain an advantage", which "is seen as a
form of pushing and can result in a ban". The merchant offers "excellent rates for selling Deuterium, up
to 3-2-1". **NOT FOUND**: no page states an explicit legal ratio window such as "2:1:1 to 3:2:1", so the
module's band is self-imposed and is labelled as ours
([X2](../specs/gameplay-algorithms.md#x2--trade)). [OGame rules §5](https://en.ogame.gameforge.com/ajax/main/rules)

**DOCUMENTED.** Alliances exist for "protection, crashing, domination, toplists, ACS", joining is an
application and players are advised to talk to a member first, and a miner's stated use for an alliance is
to "trade deuterium with fleeters to gain additional resources and (usually) the fleeter's protection".
**NOT FOUND**: no page describes what alliance members actually do day to day.
[alliance FAQ](https://board.en.ogame.gameforge.com/index.php?thread/450057-alliance-faq-v2/)

**DOCUMENTED, qualitatively.** Expeditions are "at the lowest estimate comparable to your mine income",
their rewards scale with a pathfinder (×2) and the Discoverer class (×1.5), and "the chances of your fleet
getting delayed or coming home early are big and you can also meet aliens, pirates or have your fleet
lost". **NOT FOUND**: no outcome probabilities and no fleet-loss rate appears on any reachable page, so
the expedition evaluator stays on own-outcome sampling and keeps its loss/slot budget.

**MEASURED (proxy).** In a live universe snapshot (14 Sep 2026): 3,194 registered, 1,757 active, 1,437
inactive, 104 in vacation mode (3.3%), 392 alliances — about **4.5 active players per alliance**.
[mmorpg-stat.eu](https://www.mmorpg-stat.eu/)

## 9. Activity parameters an authentic account needs

Two of these are now hard constraints rather than estimates: the host's own detector thresholds, and the
> 10-second reaction floor it implies. Everything without a source is marked PLACEHOLDER and is filled
from our own pilot telemetry later.

| Parameter | Evidence | Range to use |
| --- | --- | --- |
| **Distinct active hours per rolling 7 days** | **MEASURED (host detector)** | **fewer than 18**, whatever the persona |
| **Dark period per day** | MEASURED (host detector implies it) | at least 6 consecutive hours |
| **Reaction floor** | **MEASURED (host detector)** | **never faster than 10 s**; an expedition re-dispatch never inside ~60 s |
| Sessions per day | ESTIMATE from benchmarks and player reports | casual 2–3, active 4–8, one hardcore persona 10–16 |
| Session length | MEASURED analogue (median 4.45 min mobile; "10–20 minutes daily") | median 4–8 min, p75 ≈ 15 min, one 45–90 min block, never constant |
| Gap and session shape | **MEASURED analogue** (email inter-event times are a power law with exponent ≈ 1; web dwell times are Weibull with shape `k < 1` on 98.5% of pages, median `k` 0.65–0.80) | heavy-tailed: log-normal or Weibull with `k ≈ 0.7–0.9`, never uniform |
| Active window | MEASURED diurnal shape + reported habit | primary 18:00–23:00 local, secondary 12:00–14:00, sleep 01:00–08:00, weekend starts earlier and ends later |
| Response to a probe or attack | DOCUMENTED mechanics, no distribution | online 1–15 min (median ≈ 5), offline 3–9 h; right-skewed, never constant |
| P(online at a random instant) | ESTIMATE | casual 0.15–0.30, active 0.35–0.50, ≈ 0 inside the sleep window |
| Absence | MEASURED (inactive flags) + ESTIMATE (frequencies) | 1–3 single-day gaps a month, one 3–7 day gap a quarter, ≥ 7 consecutive days yearly, never past ~28 days |
| Save failure | **PLACEHOLDER** (no source; the "80%" figure does not exist) | one fail per 20–50 attempts, one lost fleet per 1–3 months |
| Growth regularity | PLACEHOLDER | daily growth CV 0.3–0.8, at least one flat day a fortnight, never identical between accounts |
| Social breadth | MEASURED proxy (≈4.5 per alliance; Aion entropy 0.43 bot vs 0.84 human over seven interaction types) | 3–10 persistent counterparties, 20–60 distinct a month, spread across action *kinds* |
| Inter-action interval variance | **MEASURED** (a WoW bot's keystrokes spike at its 1 s and 5.5 s poll timers while human intervals are Pareto; 97% detection accuracy from frequency, mean ATI and ATI standard deviation on one day of data) | no periodic peak, a large and time-varying per-action SD |

**Documented constraints that follow directly.** An account must be able to go dark for **seven
consecutive days** — the inactive flag is 7 days and "long inactive" is 28 — without that being a failure;
vacation mode has a two-day minimum and is itself visible as an absence; and the durable tell that gets
accounts reported is **invariance** — round-the-clock activity, a save landing the same minute before
every impact, probing a whole account in seconds — not speed, since a human with an add-on probes a whole
account in 5–10 s.

Academic work supports the same discriminator, and it is the strongest external evidence for the routine
design: near-constant or *periodic* inter-action intervals are the bot signature, and social-interaction
entropy is among the features that separate humans from bots. [Kang & Kim 2022](https://onlinelibrary.wiley.com/doi/full/10.4218/etrij.2022-0089) ·
[Gianvecchio et al., CCS'09](http://www.gianvecchio.com/uploads/1/0/7/8/10784991/ccs09.pdf) ·
[Aion dataset](https://ocslab.hksecurity.net/Datasets/game-bot-detection)

## 10. What this changes in the module

- The economy order becomes marginal payback over host-quoted cost and production, with queue time a
  measured tie-break rather than an assumed discount ([E1](../specs/gameplay-algorithms.md#e1--payback-ordering--the-next-mine),
  [E2](../specs/gameplay-algorithms.md#e2-queue-occupancy-honest-about-what-is-not-proven)).
- Storage joins the economy ranking as a fill-time rule, not a threshold copy
  ([E3](../specs/gameplay-algorithms.md#e3--storage--the-fill-time-trigger)).
- Energy keeps its predictive rule ([Y1](../specs/gameplay-algorithms.md#y1--the-energy-interlock)) and the
  satellite switch becomes a persona band over host numbers.
- The routine gains a real shape, and now has a **hard** ceiling: fewer than 18 distinct active hours in
  any 7 days, a real nightly dark period, no reaction under 10 s, and heavy-tailed gaps rather than
  uniform jitter ([H1](../specs/gameplay-algorithms.md#h1--the-active-hours-constraint),
  [H2](../specs/gameplay-algorithms.md#h2--session-shape)).
- Fleetsave and raid scoring gain the published profit tests; the bashing limit is read from the host's own
  records, because destroyed fleets do not count.
- The failed save becomes a named, deliberate event with a placeholder rate, because no source has one.

## 11. Verification status

What this pass checked, and what it changed. Full per-rule tables are in the research pass notes; the
summary is what matters for trust.

**Verified as cited, unchanged.** The payback rule in the players' words; the +50%/+60%/+50% cost
escalation and ~15% production growth; the efficiency formula `available ÷ required` capped at 100%; the
30-minute fleet-save rule; the ranked fleetsave patterns and the "never the same landing time" rule; the
named failure modes; the raid profit test with both the 3× and 20–30% figures; the bashing limit; the 72-
hour trade rule; the colony slot direction; the alliance purposes; the expedition description.

**Retracted or corrected.**

| Claim in the first version | Verdict | Where it is now |
| --- | --- | --- |
| The raid "loot split" as a multi-wave fraction | **Not on any page** | §7 — retracted |
| "An explicit legal trade ratio band" | **Not stated**; §5 bans manipulation | §8 — self-imposed band, labelled ours |
| "Defence-to-value ratio" targets | **No source** | §4 — computed from the observed attacker |
| One satellite switch point | **Contested**: 16/18/20–26/high twenties/25–30/32 | §2 |
| Storage "protects" resources | **Three incompatible statements** | §3 — contested, using the lootable half |
| "Return 10–20 min after waking" as the rule | Single non-Gameforge source; Gameforge says +30–60 min | §6 — three sources, band inside |
| `ogame.fandom.com` as a citation | **Unreachable** (302 to an ad server); `?action=raw` works | Cites replaced with Sidian, the EN board and `?action=raw` where used |
| The `board.origin.ogame.gameforge.com` tutorial threads (06, 08, 09, 10, 12, 13, 15, Guide 06, Guide 10, Tactic 05a) | **Dead board**; only Tutorials 01–04 recovered on the archived EN board | Those links are no longer cited |
| "80% of fleets are lost while offline" | **No source exists** | §6 and §9 — placeholder |

**Numbers still missing, and honestly placeheld.** Save-failure rate; login and session-length
distributions for OGame specifically; reaction latency distributions; per-persona absence shape;
expedition outcome probabilities; satellite energy per slot; any defence-to-value ratio; what alliance
members actually do day to day. Each is marked PLACEHOLDER or NOT FOUND above rather than dressed up.
