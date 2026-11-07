# Late-game tactics: how often real players use them

Research note, 2026-11-05. Read-only study for the anomaly report ("no strong account ever uses X").
Every number has a source. Confidence: **A** = rule or host code, **B** = several independent player
statements, **C** = one statement or a biased sample, **D** = inference.

## Sources used and how far to trust them

- **Local forum/reddit corpus** in `storage/rl/external/sources/` (FORUM board_en/board_us/forum_origin,
  ARCHIVE, REDDIT r/ogame): 93,419 documents. Cited as `FORUM post <id>` or `reddit <id>`. The posts
  can be searched with the scratch scripts this study used (keyword regexes over `post.jsonl`).
- **Showcase battle reports:** 5,402 CR threads on board_en and board_us ("[TOT…]", "Top 10", "vs").
  This sample is **biased toward big, spectacular hits**. Treat the share of CRs that mention a tactic
  as an upper bound on how often the tactic leads to a big fight, not as a per-day rate.
- **OGMEM real battles:** `storage/rl/ogsim/r8w/ogmem-strategies.json`, built by
  `rl/scripts/ogmem_strategies.py`. 186 real fights (372 sides) after removing simulator inputs. It has
  no missiles, phalanx or moon events because the battle reports do not contain them.
- **Local wiki and strategy store:** `plan/research/ogame/raw/wiki/...` (WIK-xxx) and
  `plan/details/research/strategy/principles/*.yaml` (FS-, NIN-, CRASH-, INT-, ACS- ids).
- **Host code** (OGameX is meant to copy OGame exactly) for mechanics.
- **Web:** Gameforge marketing pages and the official boards. The fandom wiki returned HTTP 402, so the
  local WIK copies were used instead.

---

## 1. Interplanetary missiles (IPM) and anti-ballistic missiles (ABM)

- **Rules (A).** IPM costs 12,500 metal / 2,500 crystal / 10,000 deuterium. It needs Missile Silo 4 and
  Impulse Drive 1. Range is `5 × Impulse − 1` systems. Flight time is `(30 + 60 × systems) / speed`
  seconds, and an IPM in flight does not take a fleet slot. It ignores shields and domes, and the
  defences it destroys are **not** rebuilt. Each ABM stops one IPM. A silo stores **5 IPM or 10 ABM per
  level**. ABMs fire automatically and can never be destroyed by a fleet. Sources: WIK-025, WIK-026
  (`raw/wiki/ogame.fandom.com/def-ipm/`).
- **Typical use: clearing a turtle's defence before a fleet hit (B).** Documented cases:
  - 18,715 IPMs fired before a Top-10 RIP hit (FORUM 6716361, 2014).
  - 2,520 and 5,421 IPMs fired at a plasma wall (FORUM 7253937 and 7252739, 2025).
  - ~7,531 IPMs against an inactive turtle with 3 billion of defence (FORUM 7248926, 2025).
  - 3,500+ IPMs before a hit (FORUM 7248478, 2025).
  - ~700 IPMs to flatten an inactive turtle (FORUM 7210004, 2023).
  - 1,000 IPMs to "nuke his defense" because the fleet alone could not win (FORUM 7185875, 2022).
  - A planned grind: 5 players built silo 4–5 on mobile colonies one system away and kept 120 IPMs
    ready (reddit c5v82kf).
- **How often (C).** IPM or "nuke" appears in **126 of 5,402 showcase CR threads (2.3%)**. Phalanx
  appears in 5.5% and ninja in 6.0% of the same threads. IPM use is a rare, deliberate setup before a
  big hit on a defence-heavy target. It is not an everyday raiding tool. It is also used as revenge:
  "I tend to missile people who send spy probes on me" (reddit thread v3x424). Players called that an
  overreaction.
- **Silo levels and stocks (C, small sample).** 33 posts state a silo level. Median **4**, p25 4,
  p75 7, maximum 21. Level 4 is the IPM unlock, which explains the peak. Turtles do keep ABMs:
  - "Missile Silo 10 with 100 ABMs" next to 200k rocket launchers (reddit lh6fh4h). 100 ABMs is the
    full capacity of silo 10.
  - "20–40 ABMs should be enough … people will not want to waste 40 IPMs" (FORUM 7254212).
  - The Geromoto bunker held 46 ABMs against an 18k-IPM strike (FORUM 6716361).
  - A player who had removed his ABMs ate 18 IPMs (FORUM 7253919).
- **Disagreement.** None on the rules. On value, players disagree whether ABMs are worth having,
  because a determined attacker simply sends more IPMs (FORUM 7254212).

## 2. Moon shots (deliberately making a moon)

- **Mechanic (A).** Each 100,000 units of debris gives 1% moon chance, up to a maximum of **20% at
  2,000,000 debris**. Host code: `BattleEngine::calculateMoonChance`, maximum taken from settings.
  Wiki: WIK-052.
  - At the standard 30% fleet-to-debris rate, 20% needs **1,667 Light Fighters** or 1,667 Small
    Cargos (WIK-052, WIK-029).
  - In a 40% debris universe it needs 1,250 Light Fighters (WIK-052).
  - In an 80% universe it needs **2,500 probes**, since 2,500 × 1,000 × 0.8 = 2.0M (board.en
    thread 834871).
- **Conflicting source.** The Gameforge marketing page (gameforge.com/en-GB/games/ogame-moon-shot.html)
  says 20% needs "at least 5 million" debris and to "plan for 5–8 attempts". This disagrees with the
  host code and every player source, so the 2M figure stands. The attempt count is consistent with
  maths: a 20% chance per try means 5 tries on average, and P(no moon after 8 tries) = 0.8⁸ ≈ 17%.
- **Who and how (A/B).**
  - **Usually two friendly players trade shots.** Each crashes 1,667 LF into the other's stronger home
    fleet and recycles the debris (WIK-029).
  - Trading threads exist where players offer "probe/LF/HF" moon shots or "got 1000 tries"
    (FORUM 7240111, 7257285, 627446).
  - **Rules (A):**
    - Free moon shots are allowed, but **pushing rules apply**: a higher-ranked player may not keep
      debris made from a lower-ranked player's fleet (old .org rules, FORUM 2390059 and 5850561).
    - Wiki rule: shots are allowed "so long as the debris field does not exceed 2 million"
      (WIK-088).
    - Moon shots are exempt from the weekly pulling limit (FORUM 6509033).
  - **Using your own second account is forbidden.** The multi-account rules list "It is not allowed
    to give each other moonshots", alongside ACS attacks, ACS defence and trades between accounts
    (rules of 17.9.2012, FORUM 6509030, restated in 2025 at FORUM 7254269).
- **When (B).** Moon shots happen from the early mid-game onward, at every new planet that has no moon.
  One player was "needing three more moons, so I'll be doing this until I get lucky" (FORUM 627446).
  A moon is also bought with "mooners" items in pay-to-win universes (FORUM 7217566).
- **How often (C).** Moon-shot or moon-chance wording appears in **83 of 5,402 CR threads (1.5%)**.
  No per-season count was found in any source.
- **Naming problem in our own data (A).** In `rl/ogsim/examples/season.rs:892`, `moon_shot` is
  incremented on **Mission::MoonDestroy** arrival. `anomaly_report.py` EVENT_NAMES also maps
  `moon_shot` to "moon destruction". The comment at `season.rs:930` says so explicitly. Moon *making*
  is counted as `moon_rolls` / `moon_made` (`rl/scripts/moons.py:6`).
  - So "no account ever does moon shots" in the anomaly report actually means **no moon
    destructions**.
  - Real moon destructions are also rare: "moon destroyed/blast" wording appears in 8 of 5,402 CR
    threads (0.15%).

## 3. Sensor phalanx

- **Mechanic (A/B).**
  - Range is **level² − 1 systems**. The Discoverer class gets +20%. Host:
    `PhalanxService::calculatePhalanxRange`.
  - Classic scan cost is a flat **5,000 deuterium** (host `getScanCost`, many guides).
  - **Conflicting source:** the Gameforge marketing page gives a distance-based cost of
    `(distance² − 1) × 5,000` (0 D in the same system, 495,000 D at 10 systems). This could not be
    confirmed in-game; treat it as unverified.
  - The phalanx cannot see fleets launched from or parked on a moon, jump-gate jumps, or recalled
    deployments. Sources: CRASH-006, CRASH-009, FS-010, and the Gameforge sensor-phalanx page.
  - The alliance "system phalanx" (Researcher alliance class) scans a whole system for 40,000 D
    (WIK-096).
- **How often hunters scan (C).**
  - "I lanxed him about 20–30 times a day … for about one whole month" (FORUM 400942).
  - "We had deadshot lanx him every few seconds" during a timed crash (FORUM 635732).
  - "I only phalanxed you about 4 times in total" (FORUM 442486).
  - The current universe-tasks list includes "use phalanx 5 times on different player" as a routine
    task tier (FORUM 7229165), which shows Gameforge treats phalanx use as normal daily play.
  - The phalanx appears in **296 of 5,402 showcase CRs (5.5%)**.
- **Typical levels (C, n = 33).** Median **4**, p25 3, p75 6, maximum 13. Level 4 covers 15 systems,
  level 6 covers 35.
- **Defender counters (A/B).**
  - Save from the moon (CRASH-006).
  - Deploy-recall, which disappears from the phalanx (FS-010). The disappearance itself is a tell
    (CRASH-013).
  - Decoy single recyclers sent to the debris field ahead of the real save (FORUM 3595156, 2898100,
    1994272).
  - Split the fleet into waves (FS-009).

## 4. Espionage frequency

- **Raiders (B).**
  - "I spy around **500 planets every day**" (inactive farming, FORUM 7275523, 2026).
  - "I spy over **200 people daily**" (FORUM 7230473).
  - "I spy 300 inactives daily and do around 150 CRs" (FORUM 7084989).
  - These are tool-assisted farmers. A defensible target band for a strong raider is **200–500 spy
    reports per day**.
- **Being probed (B).**
  - "I get spied like 20–50 times a day. That's normal" (reddit ib0xj4o).
  - "Maybe we all stop getting probed 30 times a day" (FORUM 7239302).
  - A hunter on one fleeter: "probe her 10/15 per day for the pressure" (FORUM 635489).
- **Miners (D).** No source gives a number. Miners mainly probe before a fleet save or to check a
  threat, so their count is far below raiders'. **Do not set a target.**
- **Rules for probing (B).** Escalate to 5–10 probes when a report is incomplete (INT-002). Repeatedly
  probing an active player alerts them (INT-009).
- **Our counter (A).** `season.rs:887` counts `spy_sent` on **Mission::Espionage arrival**. The policy
  does dispatch espionage (`players.rs:1997`, `errands.rs:575`, `errands.rs:733`). A season-long 0
  therefore means no espionage fleet ever arrived. Check whether those code paths are reachable for
  strong accounts, for example whether raids read target state without probing. Under the
  no-cheating rule, that would be a correctness bug, not just bookkeeping.

## 5. Planet relocation

- **Rule (A).**
  - Cost is **240,000 DM per planet**, or 168,000 DM in discount "happy hours". The moon moves with
    the planet. Sources: FORUM 5275539, 6030418, 7037915; host `PlanetMoveService`, setting
    `planet_relocation_cost` = 240000.
  - The 2010 introduction restricted moves to similar positions (1–3, 4–12, 13–15) (FORUM 5964585).
    This is a historical rule.
  - Host: 24 h relocation cooldown, and the jump gate is blocked for 24 h after a move
    (`PlanetMoveService.php:21` and `:193`). A player confirms "you can't use jump gate for 24 hour
    when you use relocate option" (FORUM 7163687).
- **Why players relocate (B):**
  - **Attack positioning** near a target: "240k DM last night for a relocation … see you tonight"
    (FORUM 7217070); "congrats for another relocation … the only way you can do HoFs" (FORUM 499056);
    "relocate at will and smash all your moons" (FORUM 7216382).
  - **Slot production bonus and temperature**, which can take several moves per planet (FORUM 7149655,
    7148809, 7183712).
  - **Defence clustering** after merges (FORUM 6997425).
  - Players also park planets in safe systems with filler accounts. GO clarification: relocating
    through filler accounts is **multi-accounting and not allowed** (FORUM 7254462).
- **How often (C).** "relocat" appears in 467 corpus documents, but no source gives a per-season rate.
  Players repeatedly call it DM-gated, pay-to-win and expensive: "around 1 million expeditions to get
  enough DM" (FORUM 7054079).
  - Conclusion: rare per account, and done mostly by DM-rich top hunters.

## 6. Crawler

- **Rule (A).**
  - Only the **Collector** class can build Crawlers. Existing Crawlers keep working after a class
    switch, but new ones cannot be built (WIK-076).
  - The task brief's "Collector/General" is **wrong**: the General cannot build them.
  - At most **8 working Crawlers per mine level**, summed over the three mines (WIK-227).
  - The bonus is capped at **+50% of base production**. A Collector at 150% overload gets 0.045% per
    Crawler, so 50 / 0.045 means **1,112 Crawlers saturate the cap** (FORUM 7213209 and 7213211,
    WIK-076).
  - Crawlers are stationary: speed 0 in host `CivilShipObjects` id 217, planets only.
- **Real holdings (A for our data).** OGMEM defenders (`r8w/ogmem-strategies.json` battles):
  - **13 of 186 fights (7.0%), which is 13 of 372 sides (3.5%)**, have Crawlers.
  - Median **1,100**; values 10, 100, 720, 750, 832, 1029, 1100, 1100, 1151, 1200, 1203, 1703, 2500.
  - All 13 are in the <1e8 fleet-value tier, where Crawlers are 6.4% of defending-fleet value.
  - The median sitting at the 1,112 cap confirms that **Collector miners build Crawlers up to the cap
    and then stop**. Forum examples agree: "1200 crawlers", "920 crawlers on 14 planets", "992
    crawlers" (FORUM 7205773, 7114984, 7115144).
- **Disagreement.** Some posts complain that Crawlers are not worth their energy (FORUM 7114984).

## 7. Ninja, ACS attack and defend, deploy escapes, jump-gate escapes

- **Ninja (A/B).** The defender keeps the real fleet off the spy report (on a moon, or on a mission
  timed to return) and lands it at the combat second (NIN-001, NIN-002, NIN-004; WIK-005, TP-011).
  - The attacker's counter is a last-second probe, using an ACS delay to slip the probe in before
    impact (WIK-126, NIN-005).
  - **Frequency (C):** ninja appears in **326 of 5,402 showcase CRs (6.0%)**.
  - Players describe ninja and baits as the main remaining way to catch careful players: "That just
    leaves ninjas on human greed or predictable delays … or various forms of backstabs" (FORUM
    7172730).
- **ACS attack (A).** Limits are 5 players and 16 fleets. A joining fleet may delay the union by at most
  30% of the remaining flight time (host `FleetUnionService`, `max_players` 5, `max_fleets` 16,
  `MAX_DELAY_PERCENTAGE` 0.30; ACS-013).
  - Players must be in the same alliance or on each other's buddy lists (WIK-126).
  - **Frequency (C):** the word "ACS" appears in **326 of 5,402 showcase CRs (6.0%)**.
- **ACS defend (A).** Mission type 5 (hold). Allied fleets hold at the planet. The host implements it;
  Alliance Depot support is unverified (ACS-008). No frequency source was found.
- **Deploy escape (A).** A deploy between two own planets can be recalled, and a recalled deploy
  disappears from the phalanx. That makes it the safest save without a moon (FS-010, FS-011, CRASH-003).
  Deploys between moons are called "the only 100 percent safe fleetsave" (FORUM 2898100). No
  frequency source was found; it is the default save when a moon is available.
- **Jump-gate escape (A/B).**
  - The host cooldown is 60 min ÷ universe war speed, minus 10% per gate level above 1, with a minimum
    of 1 min (`JumpGateService`).
  - Jumps are invisible to the phalanx (Gameforge sensor-phalanx page).
  - Used to dodge a hit in seconds: "It took skill to jump gate my fleet in a few seconds" (FORUM
    6598129).
  - The jump gate appears in **60 of 5,402 CRs (1.1%)**.
- **Rule limits (A).** Multi-account rules forbid ACS attack, ACS defence and trades between one
  person's own accounts (FORUM 6509030 and 7254269).

## 8. Deception and betrayal

- **Fake attacks and bait (B).**
  - A documented case: in the RoLUE history, heisenberg "sent a fake attack at Pidge, hoping he would
    ask nastynate for assistance". The helper's fleet arrived late at night and was crashed for a
    Top-10 (FORUM 7213866).
  - Bait is described as "bite n switch ninjas" (FORUM 7236207) and as a Deathstar plus a satellite
    wall to bait a big attack (NIN-004).
  - **Frequency:** "fake attack/ACS/fleet/activity" or "decoy" appears in only **42 of 93,419
    documents**. It is rare, and a top-player tactic.
- **Decoys in fleet saves (B).** Single-recycler decoys flown to the debris field ahead of the real save,
  10–30 s early and 30–60 s late (FORUM 3595156 and 2898100). Fake activity to hide one's online time:
  "she did a lot of fake acti" (FORUM 635489). FS-008 is the save-side version of this (see §9).
- **Betrayal (C).**
  - A spy inside an alliance passed a fleeter's save times and online/offline status to the attacker,
    who then hit the fleeter (FORUM 6598129, 2013).
  - Alliance-internal spies forward spy reports (FORUM 7274150, 2026).
  - "We got back stabbed once" (FORUM 7150874).
  - Hitting an alliance mate is described as socially worse than hitting a rival (FORUM 639436).
  - **Frequency:** betray/backstab/traitor wording appears in 183 documents, many of them unrelated.
    No documented case of a member leaving with the alliance's resources was found. OGame has no
    shared alliance treasury, so that scenario does not exist mechanically (D).
- **Rule limit (A).** None of this breaks the rules between separate people. The same moves between
  accounts of one person are forbidden as multi-accounting.

## 9. FS-008

- **What it is (A, local).** `plan/details/research/strategy/principles/fleetsave-survival.yaml`, id
  **FS-008**, "Mask the dispatch moment with post-save activity":
  - Stay online briefly, or create activity shortly, after launching a save, so the departure time
    cannot be read off the 15-minute activity star.
  - Confidence B, sources TP-009 (sidian fleet-saving guide) and FOR-005 (board.en "Fleetsaving v2",
    thread 623529). Status: unwired, P2.
  - Related principles: INT-005 and INT-010 (the activity star is per planet and lasts 15 min) and
    INT-007 (do not hover after dispatch).
  - **Tension:** INT-007 says to dispatch and leave; FS-008 says to blur the dispatch time with a
    little further activity. Both aim to hide when the save was launched.
- **Can the sim represent it? Yes.** The host and sim already keep a per-planet last-activity time
  (INT-004 says "supported"). FS-008 becomes:
  - after a save dispatch at time t, add one more activity stamp on a random own planet at t + U(1, 14)
    min; or
  - spread activity so the last stamp before going offline is not the dispatch second.
  - The way to test it: an observer reading only activity stars can estimate the dispatch time to within
    ±15 min, but no better.

---

## What the sim should do

Testable rules and targets. "Season" means one simulated universe season. Ranges are taken from the
sources above. No target is given where no source supports one.

1. **Espionage (top priority, possible correctness bug).**
   - A season-long `spy_sent` of 0 is wrong for any raider.
   - Target for a strong raider: **≥ 1 espionage arrival per raid launched**, with escalation to 5–10
     probes when a report is incomplete (INT-002).
   - The real band of 200–500 reports per day comes from tool-assisted inactive farmers. Use it as a
     ceiling, not a floor.
   - Being probed: an active top account receives about **20–50 probes per day** (reddit ib0xj4o,
     FORUM 7239302).
   - Test: no raid arrives on a target that the owner has not probed within the intel window
     (no-cheating rule).
2. **Rename the counter.** Rename `moon_shot` to `moon_destroy` in `season.rs` and
   `anomaly_report.py` EVENT_NAMES, and count moon *creation* attempts as their own event.
   - Moon creation target: any account that owns a moonless planet with a fleet ≥ 1,667 LF-equivalent
     and an alliance partner, or a stronger neighbour, makes **≥ 1 attempt**, keeps the debris ≤ 2M
     (20%), and repeats until a moon forms.
   - Expect about 5 attempts per moon (P = 0.2 per attempt).
   - **Forbid** moon shots between accounts flagged as one owner (multi rule).
   - Debris goes to the lower-ranked side (pushing rule).
3. **IPM.**
   - Trigger: a hunter has located a target (fleet seen or probed) whose defence makes a fleet hit
     unprofitable, and IPM cost < expected gain.
   - Then build silo ≥ 4 and fire IPMs **before** the fleet lands.
   - Expected share: about **2% of big hits** (126/5,402).
   - Turtles with silo ≥ 2 hold **20–100 ABMs**, filled to silo capacity. Flag a silo with no
     missiles only when the account is a turtle.
   - A fleeter's silo can legitimately sit empty, since stock is built just in time. Do **not** keep
     "silo without missiles" as a hard anomaly for fleeters.
4. **Phalanx.**
   - Hunters with a moon in range scan before every timed crash and keep scanning a chosen target
     repeatedly (up to 20–30 times a day during a hunt, FORUM 400942).
   - Each scan costs 5,000 D (host).
   - Target: **≥ 1 scan before each crash on a fleet returning to a planet**.
   - Typical levels are 3–6.
   - Defenders with a moon save from the moon, so a phalanx scan of them returns nothing.
5. **Crawler.**
   - Only the Collector class may build them.
   - The target is the cap, `min(8 × Σ mine levels, 1,112 at 150% overload)` per planet, reached
     during the miner's mid-game.
   - The expected share of real defending sides holding Crawlers is about 3.5% (OGMEM), with a
     median of about 1,100.
   - Fail the season if no Collector-class miner ever builds Crawlers.
6. **Ninja and ACS.**
   - ACS attack, ACS defend and ninja each appear in about 6% of showcase hits.
   - Target: ninja and ACS events are present, but rare compared with solo raids, and only between
     players in the same alliance or on each other's buddy lists.
   - Respect the limits: 5 players, 16 fleets, and the 30% delay rule.
   - Attacker counter: send a last-second probe or ACS delay when the target has a moon in range or
     allies nearby.
7. **Saves.**
   - With a moon: moon-to-moon deploy, moon-to-debris-field harvest, or jump gate. All are
     phalanx-invisible.
   - Without a moon: deploy to an own colony and recall it (FS-010, FS-011).
   - Jump-gate escape: when a hostile fleet arrives within the gate cooldown window and both moons have
     gates, jump.
   - Expected jump-gate mention rate in big hits is about 1%.
8. **Relocation.**
   - Allow it only for DM-rich hunters moving next to a target, or for slot or temperature
     optimisation.
   - Cost 240k DM, then a 24 h cooldown and a 24 h jump-gate block.
   - Expected rate: very low (see "too rare" below).
9. **Deception.**
   - Model as bait (a soft-farm look with a ninja fleet hidden, NIN-003 and NIN-004) and decoy
     recyclers in harvest saves.
   - FS-008 is a delayed activity stamp after a save (§9).
   - Test: an attacker's success rate against bait falls when a probe arrives seconds before impact
     (NIN-005).

### Forbidden by rules

- **Moon shots, ACS attack or defence, and trades between accounts of one person**, and the use of
  filler accounts to relocate or block systems (FORUM 6509030, 7254269, 7254462).
- Moon shots that leave a higher-ranked player with a lower-ranked player's debris (pushing).
- Moon-shot debris above 2M (WIK-088).

### Too rare or under-sourced to set a target

- **Relocation frequency:** no per-season number exists, and it is DM-gated. Model it as an option, not
  a target.
- **Fake attacks and alliance betrayal:** 42 and roughly 183 noisy corpus hits, with a few documented
  cases. Do not require them. At most, allow a small in-alliance information leak (spy) at a rate the
  owner chooses.
- **Moon destruction:** 8 of 5,402 CRs. An absence in one season is not an anomaly.
- **Miner espionage per day:** no source. Leave it unconstrained.
- **"Leaving with the alliance's resources":** OGame has no shared alliance stock, so this cannot
  happen in the game.
