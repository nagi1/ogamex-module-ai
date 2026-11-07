# Diplomacy, cooperation and betrayal in real OGame: evidence for the foreign ministry

- Written: 2026-11-05. Read-only research. No numbers below are invented. Where nothing was found, the entry says **NOT FOUND**.
- Confidence labels: **HIGH** means official Gameforge text or measured from Gameforge's own public API. **MED** means an official board, a staff post, or the community wiki. **LOW** means a single third-party guide or an anecdote.
- **API-MEASURED** marks figures we measured ourselves from Gameforge's public OGame API, which is the same data a real player sees in highscore, alliance and galaxy views. The snapshot was taken on 2026-11-04/05 and covers 70 live universes: en (23), de (26) and es (21). Two non-standard en servers were excluded: en-300 and en-301, which have speed 1, 85k–350k accounts and 0 active players.
  - Sources: `https://s{N}-{lang}.ogame.gameforge.com/api/{players,alliances,serverData}.xml` and `.../api/highscore.xml?category=1&type=0`.
  - "Active" means the player status has none of `i`, `I`, `v`, `b` or `a`.
  - The raw dump is not committed. It is reproducible from those URLs.

---

## 1. Alliances: membership, size, choice, switching, wings

- **Membership share among active players: 62.4% overall.** API-MEASURED: 17,289 active players across the 70 universes. HIGH.
  - **By universe age**, using universe id order per language as the age proxy:
    - Newest universe per language (≈33 days old, 3,916 active players): **34.5%**.
    - Universes ranked 2nd–6th newest (75–280 days, 4,765 active): **65.7%**.
    - Older universes (8,608 active): **73.2%**.
  - **By rank band in older universes:**
    - Top 10: **98%**.
    - Ranks 11–100: **93%**.
    - Ranks 101–500: **74%**.
    - Ranks 501–1000: **63%**.
    - Ranks 1001 and below: **40%**.
  - **The same rank gradient holds in the newest universes:** 96% / 90% / 70% / 49% / 10%.
  - **Conclusion:** a strong player is almost always in an alliance. A weak or new player often is not.
- **Membership falls as accounts go inactive.** API-MEASURED, HIGH as a measurement, inference only as to cause.
  - Share still in an alliance:
    - Active: 62.4%.
    - Vacation mode: **51.3%** (n=20,257).
    - Inactive 7–28 days (`i`): **25.5%** (n=4,266).
    - Inactive more than 28 days (`I`): **17.2%** (n=42,234).
  - This is a cross-section. It is consistent with alliances removing inactive members, but it cannot separate "kicked" from "never joined".
  - It matches the alliance rulebooks that remove members after about 7 days of inactivity: `plan/details/research/player-personas.md` lines 169–172, MED.
- **Alliance sizes are tiny and heavily skewed.** API-MEASURED, HIGH. The count below is the active members of each of the 4,355 alliances that have at least one active member.
  - Median **1**, p75 **2**, p90 **5**, p99 **20**.
  - **29%** of alliances have 2 or more active members, **4.9%** have 10 or more, and **1.0%** have 20 or more.
  - Where active alliance members sit:
    - 28.6% in 1-active-member alliances.
    - 21.7% in alliances of 2–5.
    - 17.5% in alliances of 6–10.
    - 18.1% in alliances of 11–20.
    - 7.3% in alliances of 21–40.
    - 1.7% in alliances of 41–60.
    - 5.0% in alliances over 60. Almost all of that 5% is the "Tutorial" alliances, covered in the last bullet of this section.
  - **No 60-member cap on live servers.** The "up to 60 players" figure and the "elite 20–30" advice in `raw/third-party/ogame.life/alliance/TP-023.md` (TP-023, LOW, private-server blog) are contradicted by the API: de-283 `TuT` has 407 members, 235 of them active.
  - The wiki defines a "Mass Alliance" as more than 40 members and treats it as a pejorative (`raw/wiki/ogame.fandom.com/concept/WIK-189.md`, WIK-189, MED).
- **How players choose and apply.**
  - Joining is an application. Players are advised to talk to a member first. The miner's stated use of an alliance is to "trade deuterium with fleeters ... and (usually) the fleeter's protection". Source: board alliance FAQ, cited in `plan/details/research/veteran-play.md` §8. MED.
  - Alliances publish admission criteria in their public text. Founders actively invite players, and some alliances use a recruit "probation" rank. Source: [Gameforge alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html). HIGH as an official guide, but it is marketing-style and gives no numbers.
  - Selection criteria in the third-party guide:
    - Activity: "dead alliances offer little protection".
    - Joining "slightly above your level".
    - Checking current wars.
    - Source: TP-023, LOW.
  - The 11.8.0 and 11.16.0 changelogs fix bugs in application texts and in accept/deny messages. Applications with a free-text body, accepted or denied with a message, are therefore the real mechanic. Source: [EN v11 changelog](https://board.en.ogame.gameforge.com/index.php?thread/842143-version-11-changelogs/). HIGH.
- **When alliances form, and switching.** API-MEASURED, MED: the inference relies on survivorship.
  - Method: take each universe's start as its earliest alliance `foundDate`, then look at alliances that today have 5 or more active members.
  - In the 38 universes older than one year, the median such alliance was founded on universe **day 23.5**, and a median **57%** were founded within the first **30 days**.
  - Reading: alliances that last are formed in the first month. Late joiners join existing alliances rather than found new ones.
  - **How often individual players switch alliances: NOT FOUND.** Measuring it needs two API snapshots of `players.xml`, compared by player id. The method is cheap, and has not been done.
- **Wings and academies exist, but are rare as named entities.**
  - API-MEASURED, HIGH: of 1,266 alliances with 2 or more active members, only 3 carry wing or academy names: `Red Dawn Academy` (8 active), `LTL Wing` (2) and `Miners Business Academy` (18).
  - The large "Tutorial"/"TuT" alliances found in 13 of the 26 de universes, holding up to 235 active members, look like a newcomer holding pen. **Their purpose is not documented: inference only.**
  - The wiki says large alliances "create wings to move low point members to" (WIK-189, MED).
  - NIGO ran an "Academy" to train recruits before integration (`raw/wiki/ogame.fandom.com/concept/WIK-234.md`, WIK-234, MED, anecdotal history).
  - Several en universes have alliances literally tagged `FARM` (up to 33 active members, en-273). **Their purpose is not documented.**

## 2. Pacts: NAP, BND, wars

- **NAPs are not enforced by the game.** They rest on reputation alone.
  - "NAPs are not technically enforced in OGame." A good NAP is written, defines what counts as a violation, and has a cancellation period, with **24–48 h** cited as common. Source: [Gameforge alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html). HIGH.
  - NAPs are "enforced only by the alliances and/or players concerned, and are often broken for many reasons". Veterans hold the view that "only NABS do Naps". Source: `raw/wiki/ogame.fandom.com/concept/WIK-199.md`, WIK-199, MED.
  - Disagreement: the official guide treats NAPs as normal diplomacy, while wiki culture sneers at them as a crutch for the weak.
  - A NAP can be between an alliance and a single player (WIK-199).
  - A miner can get NAPs and protection from high-ranked players nearby: `plan/details/research/player-personas.md` lines 84–85. MED.
- **A BND (Bündnis) is a NAP plus further cooperation.**
  - The game imposes no penalty for breaking one. Treaties are listed in the "Diplomatiebereich" on the alliance page. Source: owiki.de `Bündnis` and `Diplomatiebereich`, as summarised by search results; the direct fetch returned HTTP 500. MED/LOW, pages date from about 2012–2013.
  - On the NL board, peace, cooperation and alliance declarations are posted by one party and confirmed by the other, while war declarations are unilateral. Source: [NL Handelswaar & Diplomatie Regels](https://board.nl.ogame.gameforge.com/index.php/Thread/93270-Handelswaar-Diplomatie-Regels/), from a search excerpt. MED.
- **War declarations are formal board threads.** The current Polish rules are the clearest example. Source: [PL "Regulamin działu Wojny"](https://forum.pl.ogame.gameforge.com/forum/thread/152-regulamin-dzia%C5%82u-wojny/), 2021, amended 02.2024. MED-HIGH as official board rules, but national.
  - Who and how: wars are only alliance against alliance. The target's acceptance is not needed. The war must also be stated in the in-game external alliance text.
  - **Counting and duration:** each war chooses a counting system and end conditions. The end is a loss threshold, or a date **1–3 months** out, whichever comes first.
  - **Limits:** each alliance may declare at most **3** wars. Allies may join, with **24 h** approval by the side leader.
  - **Inactivity:** after **3 weeks** with no valid battle report, a moderator closes the war.
  - **Ways a war ends:** a threshold is reached, the deadline passes, both sides agree (result unresolved), the alliance dissolves (counts as a loss), or a member leaves the coalition with the opponent's consent (counts as a loss).
  - **Mass leaving or mass vacation-mode** used to dodge a war is banned and can be scored as a loss.
  - **The 12 h wait before bashing was removed in February 2024**, with the text now pointing to the automatic bash system.
  - Older EN/wiki rules required a declaration in the "Area of Diplomacy" at least **12 h** before bashing, and stated that a player who leaves the alliance is no longer at war (`raw/wiki/ogame.fandom.com/rules/WIK-088.md`, WIK-088, MED, old).
- **How common pacts and wars are, and how long they last: NOT FOUND.** No source gives counts of NAPs or wars per universe or alliance, or how long they typically run.
  - The API does not expose diplomacy.
  - The war subforums could be counted per universe as a next step.

## 3. Cooperation inside an alliance

- **ACS.**
  - Arrival is synchronised to the second, and the slowest fleet sets the time ([Gameforge guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html), HIGH).
  - In redesign universes, all ACS-attack participants must be on each other's buddy lists (`raw/wiki/ogame.fandom.com/concept/WIK-126.md`, WIK-126, MED).
  - Bashing interaction:
    - A player at the bash limit for a target cannot join an ACS against it (US rules, HIGH).
    - Fleets that are not ACS'd count separately, however close together they land (US hidden-rule clarifications, staff, 2020, MED).
  - An ACS split, meaning a share of loot to a participant, is legal only if the participant's ships "had a significant impact on the outcome". It must be completed within **72 h**, and the operator must be notified (US rules, HIGH).
  - **How often ACS attack or ACS defend is used: NOT FOUND.**
- **Resource transfers between members** are bounded by the pushing rule, and alliance membership gives no exemption. Official rules, HIGH; details in §6.
  - Practice: a stronger member lent 2.5M metal to rebuild a crashed mate. Repayment came about 7 days later, and the account was **banned for pushing**.
  - Board consensus on that case:
    - Help a mate through better trade rates, ACS or fleet intercepts, not loans.
    - A richer helper should accept not being repaid. A strong-to-weak transfer is legal.
    - The window was raised from 48 h to 72 h earlier.
  - Source: [DE board, 2015](https://board.de.ogame.gameforge.com/index.php?thread/190321-handelszeiterh%C3%B6hung-innerhalb-einer-allianz/). MED.
  - Old wiki rule: a player can receive help up to the amount he lost, with the admin informed in advance (WIK-088, MED, old, not in current rules).
  - French FAQ: the alliance and its sub-alliances may resupply a mate whose fleet was destroyed ([aide-ogame FAQ](http://www.aide-ogame.net/FAQ-alliance.html), LOW, old).
  - Internal "taxation" or communal pools are suggested by TP-023 (LOW). Note: a pool sending a weaker member's resources to a higher-ranked one is pushing by definition.
- **Moonshots between mates.**
  - The free direction is only from higher-ranked to lower-ranked. A lower-ranked player giving a moonshot to a higher-ranked one must be paid at **3/2/1–2/1/1** (US rules, HIGH).
  - The wiki procedure is 1667 LF for a 20% moon chance, with "the person with LOWER points should always sent the resources before receiving the benefit" (`raw/wiki/ogame.fandom.com/def-layering/WIK-029.md`, WIK-029, MED).
- **Shared moons, jump gates, depot, espionage sharing.**
  - The depot level sets how many allied ships can hold in your orbit (Gameforge guide, HIGH).
  - Moon networks and jump gates "for alliance members" are recommended in TP-023 (LOW). Note: a jump gate is a per-player mechanic, so "shared" really means mates stationing at each other's moons.
  - Espionage-report sharing: the Gameforge guide does not cover it. TP-023 describes "Scout Networks" (LOW).
  - **Frequency: NOT FOUND.**
- **When a member is hit,** the Gameforge guide says the first response should be "a depot withdrawal and a conversation about what happened — not silence" (`plan/details/research/player-personas.md` lines 215–216, HIGH).

## 4. Betrayal and deception

- **Attacking your own mates is legal mechanically and under the rules, but it is a cultural taboo.**
  - Honour: alliance members are "neutral", and attacks on them do not change honour points (`raw/wiki/ogame.fandom.com/concept/WIK-222.md`, WIK-222, MED).
  - Alliance rules: founders should be "extremely strict when it comes to a member raiding another fellow member" (`raw/wiki/ogame.fandom.com/strategy/WIK-124.md`, WIK-124, MED). Real alliance rulebooks punish attacks on members with kick or fines ([clanplanet "Die Fleeter-Elite"](https://www.clanplanet.de/_sites/render.asp?clanid=61178&id=275169), LOW).
  - Pushing still applies between members. FR staff, 2020: attacking a **higher-ranked** mate who agrees to be looted is not pushing, but loot that lifts you above him is ([FR board](https://board.fr.ogame.gameforge.com/index.php?thread/731829-attaquer-un-membre-de-son-alliance-dans-quels-cas-est-ce-du-push/), MED).
- **NAP breaking is the cardinal sin.** "NAP breaking is the worst reputation damage in OGame. Alliances known for NAP breaking rarely find new partners and are actively attacked" ([Gameforge guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html), HIGH). The wiki says NAPs are "often broken" (WIK-199), so breaking happens even though it is punished socially.
- **Leaders disband, quitting members gift loot.**
  - A disband deletes the alliance, and every member loses the tag (`raw/wiki/ogame.fandom.com/concept/WIK-197.md`, WIK-197, MED).
  - A quitting member offering his planets to be looted by a mate is a real, recurring request (FR board 2020, above).
  - A player recycled a crash of his own alliance mate right after leaving the alliance, and the account was then blocked ([jeuxvideo.com, 2008](http://www.jeuxvideo.com/forums/1-14888-44340-1-0-1-0-0.htm), LOW, from a search excerpt, single anecdote).
- **Spies and fake truces.**
  - NIGO Universe 2 was "particularly focused on espionage activities against enemy players" (WIK-234, MED). This describes intelligence work, not infiltration of an enemy alliance.
  - TP-023 lists "strategic inactivity (temporarily appearing inactive to seem like poor targets)" as a defensive deception (LOW).
  - **Documented infiltration spies, fake truces, or leader resource grabs: NOT FOUND** in any reachable source. Reddit is blocked to our crawler.
- **How common betrayal is, and its triggers** (inactivity, rank gap, a big fleet seen at a weak mate): **NOT FOUND as numbers.**
  - The only causal statement is NIGO's: growing "aggression toward rival players" led to "deterioration of both internal and external stability, as the number of enemies began to exceed that of allied factions" (WIK-234, MED).
  - The WIK-124 tip warns that a domineering leader invites "a stronger member [to] raid you ... forecasting a series of rebellions", so a rank inversion inside an alliance is named as a trigger (MED, qualitative).

## 5. Leaving and inactivity

- Inactive accounts mostly leave or are removed: membership is 62.4% for active players, 25.5% for `i` and 17.2% for `I` (API-MEASURED, §1).
  - Rulebook practice is removal after about **7 days** inactive (player-personas.md, MED).
  - Vacation accounts mostly stay: **51.3%** are in an alliance.
- **Farming inactive mates.**
  - Inactive players are "neutral" for honour (WIK-222).
  - Once an inactive mate is out of the alliance, the galaxy view shows him as `i`/`I`, and he is an ordinary farm target for anyone.
  - Pushing does not apply to raids on a higher-ranked inactive, because the target is not "lower ranked".
  - **A source on farming a still-tagged inactive mate: NOT FOUND.**
- **Leaving during a war.**
  - Old rule: a player who leaves is no longer part of the war (WIK-088 / [ogame.fandom War](https://ogame.fandom.com/wiki/War)).
  - Current PL rule: membership is judged at fleet launch, and *mass* leaving or mass vacation-mode to dodge a war is banned (PL war rules).
- **Account sitting** is limited to a **48 h** maximum. The sitter may not run espionage, ACS attack, ACS defend or moon destruction, and may not remove the account from vacation mode (US rules, HIGH; EN rules §3, HIGH).
- **Planned leaving.** Kick messages carry a reason since 11.15.0 (EN changelog, HIGH). This means kicks are a routine leader action.

## 6. Hard rules: official constraints

The EN rules were archived on 2025-03-31 ([Wayback of `en.ogame.gameforge.com/ajax/main/rules`](https://web.archive.org/web/20250331233948/https://en.ogame.gameforge.com/ajax/main/rules)). They were superseded by the [EN board Game Rules valid from 05.08.2025](https://board.en.ogame.gameforge.com/index.php?thread/852578-game-rules/). The national supplement used below is the [US rules thread](https://board.us.ogame.gameforge.com/index.php?thread/51369-ogame-us-game-rules/), last dated 05.05.2025.

| Rule | Exact constraint | Source, confidence |
|---|---|---|
| Multi-accounting | "Every player is allowed to control a single account per universe." Accounts sharing a network must not play each other's account and "may not attack each other for any reason". | EN §2 HIGH; US HIGH |
| Account sitting | Maximum 48 h, through Lobby sitting codes only. A sat account "cannot carry out battle activities with other players". | EN §3 HIGH |
| Pushing | "No account is allowed to obtain unfair profit from the resources of a lower ranked player." This includes "resources sent from a lower-ranked account to a higher-ranked one with nothing tangible in return", setting up a battle so the higher-ranked account profits, and "manipulating trade ratios". | EN §5 (2025-03) / §4 (2025-08) HIGH |
| Trade and help deadline | "Trades, recycling help & ACS splits must be completed within 72 hours." | EN HIGH |
| Trade ratio window | **3/2/1 to 2/1/1**; outside this range counts as pushing. This is the US board rule; the EN rules give no figure. | US HIGH (national) |
| Pulling (strong to weak) | Capped at **500M resources a week**, resetting Monday 00:00. Applies if the universe is under 3 weeks old, the player is in the economy top 20, or the player has more than 2bn economy points. The puller may not be overtaken. In new universes sending resources is fully forbidden until **3 weeks and 3 days**, except in season universes. Moonshots, trades, recycler help, ACS and profit sharing are not pulling. | US (added 05.05.2025) HIGH (national) |
| Receiving a push | If a lower-ranked player sends you resources unexpectedly, you must return them and report it. | US HIGH |
| Bashing | Rule text: at most **6 attacks per planet or moon per 24 h** on an active player, moon destruction included; probes and IPMs excluded. Allowed only during a forum-announced alliance war, and investigated only if reported (EN 2025-03 §4). **The rule is now enforced in-game**: version 11.6.1 added "bashing protection ... after the 6th attack ... within 24 hours"; from 11.8.0 only attacker wins and draws count, and a capped player cannot join an ACS or send non-probe espionage on that target. `serverData.xml` shows `bashingSystemEnabled=1` and `bashlimit=6` on 68 of 70 universes (2 return no `bashingSystemEnabled` value). Fully destroyed attacking fleets do not count, and moon destruction always counts (US staff 2020). The EN rules from 05.08.2025 **no longer have a bashing section**. | EN rules HIGH; [EN changelog](https://board.en.ogame.gameforge.com/index.php?thread/842143-version-11-changelogs/) HIGH; API HIGH |
| War exception in-game | **Disagreement or unknown.** The written rule allows bashing during war, and PL dropped its 12 h wait in favour of the "automatic system". No source confirms whether the in-game counter is lifted between warring alliances: a 2022 player post said "No such function exists". | MED, unresolved |
| Insults and spam | Not allowed. Offensive alliance texts are removed, and the founder may be warned or banned. | EN §8 HIGH; US staff MED |

---

## What the foreign ministry should do

Every input below is something a real player sees: the highscore, the galaxy view and its status flags, alliance pages, application messages, received messages, espionage reports and its own combat reports. Thresholds marked *(ours)* are our own choices anchored on the evidence cited. They are not OGame facts.

### Join
1. **J1. When to join.**
   - **Rule:** if the account is active and outside an alliance, it applies to an alliance from day 1 to 30 of the universe.
   - **Why:** 73% of active players in older universes are members, and alliances that last are founded within the first month.
   - **Test:**
     - By day 30 the account has sent at least one application.
     - Once its rank is in the top 100, the account is in an alliance for at least 90% of ticks, matching the 93–98% measured.
2. **J2. Which alliance to choose.**
   - **Rule:** prefer alliances that have 5–20 active members (the 90th to 99th percentile size) and an open status, and whose median member points are at or slightly above its own.
   - **Rule:** reject alliances whose members are mostly `i`, `I` or `v` in the galaxy view, because a dead alliance gives no protection.
   - **Why:** TP-023 (LOW) and the API size distribution.
   - **Test:** the choice is invariant to hidden state. It is computed only from `alliances.xml`-equivalent public data plus galaxy status flags.
3. **J3. How to apply.**
   - **Rule:** send an application with a free-text body. Accept that it may be denied or left pending for days.
   - **Why:** player-communication.md puts reply cadence in hours to days.
   - **Test:** no application is sent to a closed alliance, and none is re-sent within 24 h *(ours)*.

### Help
4. **H1. Defending a mate.**
   - **Rule:** ACS-defend or hold at a mate's planet only when the depot level and flight time allow arrival before the hostile fleet, and the hostile fleet is one our own simulation says we can beat or deter.
   - **Rule:** after a mate is hit, send one acknowledgement message, not silence.
   - **Why:** the Gameforge guide.
5. **H2. Rebuilding a crashed mate.**
   - **Rule:** help a crashed mate only through a gift from higher rank to lower rank, at most the documented pull cap, or through a ratio trade inside **3/2/1–2/1/1** settled within **72 h**.
   - **Rule:** never take a loan that must be repaid upward later than 72 h.
   - **Test:** for every transfer, *if* sender rank is lower than receiver rank, *then* goods come back within 72 h at an in-window ratio. The 2015 ban case is a fixture.
6. **H3. ACS attacks and moonshots.**
   - **Rule:** join an ACS attack only when its own ships significantly change the outcome, which is the condition for a legal split. Settle the split within 72 h.
   - **Rule:** offer a free moonshot only downward in rank. Charge upward-rank moonshots at an in-window ratio.

### Leave and kick
7. **L1. Leaving.**
   - **Rule:** leave or switch when the alliance's active members fall below 3, more than 50% of its members show `i`/`I` for 7 days *(ours)*, or the alliance is in a war it cannot survive and the account is not a core member.
   - **Rule:** never leave in a coordinated wave to dodge a war (mass-leave ban).
8. **L2. Removing inactive members.**
   - **Rule:** when the AI leads an alliance, it removes members inactive for 7 days or more, with a reason in the kick message (11.15.0). Vacation members are kept.
   - **Why:** vacation members stay in alliances at 51% versus 17–26% for inactive ones.

### Pacts
9. **P1. NAPs.**
   - **Rule:** propose NAPs only to neighbours who rank strictly above us, or to alliances we cannot beat. Write the terms down with a cancellation period of 24–48 h.
   - **Rule:** honour every NAP for its whole term. Cancel only by notice, never by surprise attack.
   - **Test:** zero attacks on a NAP partner before the notice period has passed.
10. **P2. Wars.**
    - **Rule:** declare war only alliance against alliance, with stated end conditions: a 1–3 month or loss threshold.
    - **Rule:** stop once the condition is met, or after 3 weeks with no valid hit.

### Betrayal
11. **B1. Betrayal of mates is forbidden.** The AI never attacks or spies on current alliance mates or active NAP partners. A real experienced player treats that as the cardinal sin: it ruins diplomacy for the rest of the universe.
12. **B2. Legitimate opportunism.**
    - **Rule:** a **former** mate who has gone `i`/`I` and been removed is an ordinary inactive target, subject to normal farm rules and the bash limit. This is ordinary play, not betrayal.
    - **Rule:** a former mate who left on bad terms may become a normal target after leaving.
    - **Rule:** after a NAP is cancelled with notice and the notice has expired, the former partner is fair game.
13. **B3. Being betrayed.**
    - **Rule:** if a partner or mate attacks us, record a grudge (`resources/behavior/grudges.yaml`). End the pact immediately and do not offer a new one to that alliance *(ours: for the rest of the universe)*.
    - **Why:** "Alliances known for NAP breaking rarely find new partners and are actively attacked."
14. **B4. Deception that is allowed.** Allowed: irregular activity patterns and "appearing inactive" (TP-023, LOW). Not allowed: spy infiltration or fake truces as default play, because there is no documented frequency to anchor them.

### Hard constraints (must hold in every tick and in every test)
- **C1.** Never send a non-returned transfer, set up a battle, or make a trade that makes a higher-ranked account richer at a lower-ranked account's expense. Close every trade, recycling help and ACS split within 72 h, at ratios inside 3/2/1–2/1/1.
- **C2.** Never send a winning or draw attack more than **6 times per planet or moon per 24 h** against the same active target. Read the limit from host config (`bashlimit`), not a constant. Do not count fully destroyed own fleets or probe-only espionage. Moon destruction always counts. Assume no war exception until the host says otherwise.
- **C3.** One AI account per universe per "player identity". AI accounts that share a host or network never attack each other and never trade outside C1.
- **C4.** No sitting-style actions: no espionage, ACS or moon destruction on another account's behalf.
- **C5.** Respect the pull cap and new-universe freeze when the host enables them: at most 500M a week of strong-to-weak gifts, and no sending in the first 3 weeks and 3 days.
- **C6.** No insults or spam in any message or alliance text.

### Gaps worth closing next (cheap, measurable)
- Take a second API snapshot about 7 days later and compare `players.xml` alliance ids. This gives the measured weekly switching rate and the kick or leave rate by status, which answers §1 switching and §5 directly.
- Count war threads per universe on the DE, PL and EN boards to estimate war frequency (§2).
- ACS frequency, espionage-report sharing and betrayal rates: **no source found.** Keep these as qualitative rules until a battle-report corpus (for example board "Combat Reports" sections) is mined.
