# How experienced players actually play

Research note, 14 September 2026. Compiled from published guides, Gameforge's own material, the
official rules and community threads, plus what can be measured about player activity. It exists to
answer one question for [gate 3](../specs/cognition-gates.md): *can the mechanism be named as something
an experienced player does?* Where the sources disagree, the disagreement is recorded rather than
resolved, and the module's own choice is stated with its reason.

Confidence markers: **MEASURED** (figures with a source) · **DOCUMENTED** (guide/wiki/official) ·
**ANECDOTAL** (named veteran) · **CONTRADICTED** (sources disagree) · **ESTIMATE** (our synthesis).

## 1. The opening, and how it is really decided

**DOCUMENTED.** The published community opening is a step table, not a rule: solar 1 → metal 1 → metal
2 → solar 2 → metal 3-4 → solar 3 → crystal 1 → solar 4 … reaching robotics 1 around step 27, lab 1,
shipyard 1-2, and energy technology 1 after that. The stated goal is "get a small cargo ship as soon as
possible to start raiding inactive players", with espionage probes before warships.
[fandom Quick Start](https://ogame.fandom.com/wiki/Quick_Start_Guide) ·
[Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started)

**DOCUMENTED.** A second modern guide gives the same shape in blocks and a different research entry
point — energy 1 → combustion 2 → espionage 2 → computer 2 → impulse 1 instead of energy 1 first.
The order of *buildings* is stable; the research entry point is not. [Sidian](https://sidian.app/s/ogame-wiki/guides/getting-started)

**MEASURED / ANECDOTAL.** What actually decides the next mine is **payback time**, stated three times
independently: "prioritize mines with lowest amortization first, i.e. build the mines which repay their
cost the fastest"; "you simply get the cheapest mine first"; "around 35/30/31… build the cheapest mine
level next".
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/) ·
[ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/)

**MEASURED.** The mechanics that make payback the right question: each level costs **+50 % metal, +60 %
crystal, +50 % deuterium** while production grows about **10–15 % per level**, so a mine's payback
lengthens as it levels. Crystal mines age worse than metal, and deuterium mines worse than crystal.
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**DOCUMENTED.** Players compare costs in one currency using the accepted trade band, e.g. weightings
`1/3 M + 1/2 C + D` or `M + 1.5 C + 3 D`, and "build the one that costs the least".
[ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/)

**DOCUMENTED / ANECDOTAL.** Level-ratio heuristics exist (metal ≈ 2× crystal ≈ 2× deuterium in levels,
or output ratios 2:1:1 and 2:1.5:0.8), but the same thread contains veterans who deliberately ran
crystal two levels above metal. **The ratio is personal; the payback rule is not.**
[OGames ratios](https://ogames.net/blog/ogame-mine-ratios-payback-times) ·
[ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/)

**DOCUMENTED.** Players stop when payback stops paying: "accept paybacks up to about 2-3 real days at
any speed"; mining is usually abandoned around 35/30/30. [OGames ratios](https://ogames.net/blog/ogame-mine-ratios-payback-times)

**Module consequence.** The economy order is *marginal production gained ÷ weighted price paid* — the
same rule the guides state in words and the same one the surveyed automation tools compute. See
[slice 3P](../GATE-AUDIT.md).

## 2. Energy — the one place the sources genuinely disagree

**CONTRADICTED.** One doctrine builds *through* deficits on purpose: "This guide will sometimes make
you drop below 0 energy. That is ok since in each case the gain in resource production is faster than
if energy is built first. So this build order gives the greatest return on investment." It prints
"-6 but improvement (produce at 90 %)" as an acceptable state.
[fandom Quick Start](https://ogame.fandom.com/wiki/Quick_Start_Guide)

The other doctrine is stated just as plainly: "Always plan your energy supply **one or two mine levels
ahead**. Before you click that mine upgrade, verify that your projected energy surplus covers the new
demand at level completion." [OGames energy](https://ogames.net/blog/ogame-energy-management-guide)

**DOCUMENTED.** Both camps agree on the mechanics and on the shape of the answer: production
efficiency is `available ÷ required`, capped at 100 % (1,000/1,200 puts **every** mine at 83.3 %), and
the plant should stay roughly two levels behind the metal mine ("if your Metal Mine is at 22, your Solar
Plant should be at 20 or higher"). [fandom Energy](https://ogame.fandom.com/wiki/Energy) ·
[OGames energy](https://ogames.net/blog/ogame-energy-management-guide)

**DOCUMENTED.** Switching to fusion depends on deuterium being a surplus, not on energy being short:
fusion unlocks at energy technology 3, and colder planets (higher deuterium yield) are where it pays.
Solar satellites are the mineral alternative and are *fleet units*: destructible, unrepairable, and
better in cold systems. [fusion thread](https://board.en.ogame.gameforge.com/index.php?thread/817798-fusion-reactor-use/)

**CONTRADICTED.** The plant level at which veterans switch to satellites is given as 16, 18, 25, 30 and
32 by different players with calculations attached. There is no canonical number.
[solar plant thread](https://board.us.ogame.gameforge.com/index.php?thread/103866-solar-plant-how-high-do-you-go/)

**Module choice.** The module takes the *predictive* doctrine: it builds capacity before the level that
would outdraw the planet, which reproduces the opening both guide families publish (solar plant first).
The reason is not that deficits are unaffordable but that they are **unobservable in the account's own
records as a decision** — an account that mines at 83 % and never fixes it looks like a player who is
not paying attention, which is the one thing ordinary play never looks like.

## 3. Storage

**DOCUMENTED.** Storage exists for absence, not for growth: "storage only matters when you cannot log
back in to spend resources before they cap", and once full "no more metal can be harvested". [OGames build order](https://ogames.net/blog/ogame-build-order-first-week) ·
[fandom Storage](https://ogame.fandom.com/wiki/Storage)

**DOCUMENTED.** Capacity also decides *how much can be taken*: resources above capacity are unprotected,
while resources inside it can only be looted to 50 %. [Sidian Metal Storage](https://sidian.app/s/ogame-wiki/buildings/resources/metal-storage)

**Module rule.** Upgrade storage when the projected time until the next planned spend exceeds the time
to fill the remaining capacity — the trigger the guides describe in words and the surveyed tools
implement as a threshold (0.8 of capacity in one, at-capacity in another).

## 4. Research priorities

**DOCUMENTED.** Miner: energy technology 1 early (fusion and plasma prerequisities), energy 12 for the
terraformer and no further unless fusion is used, laser 12, ion 5, and **astrophysics is the priority**
because colonies are the long-run multiplier; plasma is "indispensable" for mines.
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**MEASURED.** The miner roadmap pairs mine levels with astrophysics milestones (1 planet at 14-11-10 →
astrophysics 1; 2 planets at 15-13-11 → 3; … 12 planets at 40-32-36 → 23) and says the return stops
paying after astrophysics 23. [miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**DOCUMENTED.** Fleeter: the General class, combat technologies, and a fleet of battleships, cruisers,
recyclers, large cargos and probes in stated quantity bands; the official raider guide also states the
profit test (§6). [Gameforge raider guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)

**ANECDOTAL.** Turtle: defence exists to make an attack unprofitable rather than to win — "not to
survive a battle… but rather to inflict maximum possible damage, making the attack unprofitable".
[player styles](https://board.en.ogame.gameforge.com/index.php?thread/810247-guide-01-player-styles-guide/)

**Gap.** No dedicated *trader* playstyle source was found, and OGameX has no marketplace, so the
trader persona has to be defined by the module rather than copied from play.

## 5. The daily routine

**DOCUMENTED.** The canonical loop, from Gameforge: log in for the daily bonus; check fleets and plan
the save; harvest production before storage fills; restart research/construction; log out — "10–20
minutes daily". [Gameforge happy hour](https://gameforge.com/en-GB/games/ogame-happy-hour.html)

**DOCUMENTED.** "Most players check in once or twice a day"; the best farm windows are "early morning
and late night in your timezone". [Sidian farming](https://sidian.app/s/ogame-wiki/guides/farming)

**ANECDOTAL.** A speed-universe player reports "you can comfortably log in 3–4 times per day and
maintain efficient queues"; another reports checking "1-2 times per day".
[OGames build order](https://ogames.net/blog/ogame-build-order-first-week)

**DOCUMENTED.** The hard rule that shapes the routine: "If you go offline for more than 30 minutes with
a valuable fleet, fleet save it." [Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started)

**DOCUMENTED.** Activity itself is observable: a per-planet asterisk refreshed by any planet-context
request, and the profiled target is "active every evening 20:00–23:00, inactive after midnight".
[OGames activity](https://ogames.net/blog/ogame-activity-tracking-guide)

## 6. Fleetsaving

**DOCUMENTED.** "A fleet cannot be destroyed when it is in motion", and "vary your timing — never save
to the same landing time every day". [fandom Fleetsaving](https://ogame.fandom.com/wiki/Fleetsaving)

**DOCUMENTED.** Ranked patterns: deploy (recallable) before a moon exists; moon-to-moon deploy as the
safest; debris-field saves are readable by a sensor phalanx; colonisation saves as a fail-safe with its
own trap (astrophysics level). Defenders are told to return saves **10–20 minutes after** they expect to
log in, and never inside a sleep window. [Sidian fleet saving](https://sidian.app/s/ogame-wiki/guides/fleet-saving)

**DOCUMENTED.** The named failure modes are exactly the imperfections an authentic account should
sometimes show: the "just this once" overnight gamble, forgetting to relaunch after landing, saving to a
planet instead of a moon, a predictable return time, and landing with a full cargo.

**Gap.** No source quantifies how often real players fail a save. The widely quoted "80 % of fleets are
lost while offline" figure could not be found in any source and should not be repeated.

## 7. Raiding

**DOCUMENTED.** Profit is the gate, not loot: "profit = loot − deuterium consumption − expected ship
losses", with rules of thumb that **loot should be at least three times the deuterium spent** and losses
"maximum 20–30 % of the loot". [Gameforge raider guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)

**DOCUMENTED.** Target practice: keep lists of targets and observe their patterns; prefer inactives with
full warehouses, high-production low-defence miners, and players who forgot to save; attack when the
warehouses are full. Scouting is 5–10 probes, read the report, then "calculate if the loot justifies
the attack" — and espionage technology 5 for reliable fleet and defence visibility.
[Sidian farming](https://sidian.app/s/ogame-wiki/guides/farming)

**DOCUMENTED.** Loot split and capacity maths are published (⅓ metal, ½ of the remainder crystal, then
deuterium, capped at 50 % of what is stored). [fandom Raid](https://ogame.fandom.com/wiki/Raid)

**DOCUMENTED.** A hard rule the module must respect: at most **6 attacks per planet or moon per 24 h**
(bashing), probes and interplanetary missiles exempt. [OGame rules §4](https://en.ogame.gameforge.com/ajax/main/rules)

## 8. Colonisation, trade and alliances

**DOCUMENTED.** Slots change what a planet is good at (crystal in the inner slots, deuterium in the
cold outer ones) and position trades fields against temperature; the miner roadmap treats a colony as
"another mine" and builds whatever has the shortest payback.
[fandom Colonization](https://ogame.fandom.com/wiki/Colonization) ·
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)

**DOCUMENTED.** Trades must sit inside the accepted ratio band — trading outside it "is seen as a form
of pushing and can result in a ban" — and trades or ACS splits must complete within 72 hours.
[fandom Trade](https://ogame.fandom.com/wiki/Trade) · [OGame rules §5](https://en.ogame.gameforge.com/ajax/main/rules)

**DOCUMENTED.** Alliances exist for "protection, crashing, domination, toplists, ACS"; joining is
described as the ordinary way to stop being attacked, and applications usually involve talking to a
member first. [alliance FAQ](https://board.en.ogame.gameforge.com/index.php?thread/450057-alliance-faq-v2/)

**MEASURED (proxy).** In a live OGame universe snapshot (14 Sep 2026): 3,194 registered, 1,757 active,
1,437 inactive, 104 in vacation mode (3.3 %), 392 alliances — about **4.5 active players per alliance**.
[mmorpg-stat.eu](https://www.mmorpg-stat.eu/)

## 9. Activity parameters an authentic account needs

Measured where a source exists; the rest are marked as estimates to be re-fitted from our own telemetry
once real accounts run. Mobile benchmarks transfer as *structure* (short, frequent checks) rather than
as cadence, and the Minecraft-style diurnal shape transfers well because both are always-on servers with
mixed timezones. [GameAnalytics benchmarks](https://investgame.net/news/pdf/mobile-gaming-benchmarks-for-q1-2024/) ·
[Minecraft peak hours](https://minecraft-stats.com/blog/when-are-minecraft-servers-busiest-the-data-on-peak-hours)

| Parameter | Evidence | Range to use |
| --- | --- | --- |
| Sessions per day | ESTIMATE from mobile benchmarks + player reports | active 4–8, casual 2–3, one hardcore persona 10–16 |
| Session length | MEASURED (structural analogue: median 4.45 min) | median 4–8 min, p75 ≈ 15 min, one 45–90 min evening block, never constant |
| Active window | MEASURED diurnal shape + profiled OGame target | primary 18:00–23:00 local, secondary 12:00–14:00, sleep 01:00–08:00, weekend start earlier end later |
| Response to a probe or attack | DOCUMENTED mechanics, no distribution | online: 1–15 min (median ≈ 5); offline: 3–9 h; never below 20 s, never constant |
| P(online at a random instant) | ESTIMATE | casual 0.15–0.30, active 0.35–0.50, ≈0 during sleep |
| Absence | MEASURED (inactive flags, 3.3 % vacation share) | 1–3 single-day gaps a month, one 3–7 day gap a quarter, ≥7 consecutive days at least yearly, ≥4 h a day with nothing |
| Save failure | PLACEHOLDER (no source) | one failed save per 20–50 a month, one fleet lost per 1–3 months |
| Growth regularity | PLACEHOLDER | daily growth CV 0.3–0.8, at least one flat day a fortnight, never identical between accounts |
| Social breadth | MEASURED proxy (≈4.5 per alliance) + bot-detection research | 3–10 persistent counterparties, 20–60 distinct a month |

**Documented constraints that follow directly.** An account must be able to go dark for **seven
consecutive days** (the inactivity flag) without that being a failure; vacation mode has a two-day
minimum; and the durable tell that gets accounts reported is **invariance** — 24/7 activity, a save
landing the same minute before every impact, probing whole accounts in seconds — not speed itself, since
a human with an add-on probes a whole account in 5–10 s.
[activity FAQ](https://ogame.fandom.com/wiki/Inactive_Players) · [bot threads](https://board.en.ogame.gameforge.com/index.php?thread/829111-bots/)

Academic work supports the same discriminator: near-constant inter-action intervals are the bot
signature, and social-interaction entropy is among the strongest features separating humans from bots.
[Kang & Kim 2022](https://onlinelibrary.wiley.com/doi/full/10.4218/etrij.2022-0089) ·
[Aion bot dataset](https://ocslab.hksecurity.net/Datasets/game-bot-detection)

## What this changes in the module

- The economy order becomes marginal payback over host-quoted cost and production ([3P](../GATE-AUDIT.md)).
- Energy becomes a predictive rule ([3O](../GATE-AUDIT.md), shipped).
- Storage joins the economy ranking as a capacity rule rather than a taste.
- The routine gains a real shape: sessions in a window, several a day, one long block, an absence
  pattern, and a response latency that is a distribution rather than a constant.
- Fleetsave and raid scoring gain the published profit tests; the bashing limit becomes a constraint.

Every claim above carries its source, and the two contested areas — deliberate energy deficits and the
plant-to-satellite switch point — are recorded as contested rather than quietly resolved.
